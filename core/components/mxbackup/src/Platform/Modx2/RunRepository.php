<?php

namespace MxBackup\Platform\Modx2;

use MxBackup\Core\Contract\RunRepositoryInterface;

final class RunRepository implements RunRepositoryInterface
{
    private $connection;
    private $modx;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->modx = $connection->modx();
    }

    /**
     * ⚠️ Все обращения идут через Connection::run(): история пишется по краям
     * долгих стадий, когда соединение уже могло закрыться по wait_timeout.
     * Прецедент — восстановление, где `start()` для страховочной копии
     * выполняется сразу после распаковки архива.
     */
    public function start(array $data)
    {
        return $this->connection->run(function () use ($data) {
            $run = $this->modx->newObject('mxBackupRun');
            $run->fromArray($data, '', true, true);
            return $run->save() ? (int)$run->get('id') : 0;
        }, static function ($id) {
            return !$id;
        });
    }

    public function finish($id, array $data)
    {
        if (!$id) {
            return false;
        }

        $allowed = [
            'status', 'archive_path', 'archive_size', 'archive_checksum',
            'manifest_json', 'report_json', 'email_sent', 'error', 'completedon',
        ];
        $jsonFields = ['manifest_json', 'report_json'];
        $sets = [];
        $values = [];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if (in_array($field, $jsonFields, true) && $value !== null) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($value === false) {
                    return false;
                }
            } elseif ($field === 'email_sent') {
                $value = $value ? 1 : 0;
            }
            $sets[] = '`' . $field . '` = ?';
            $values[] = $value;
        }

        if (!$sets) {
            return false;
        }

        $values[] = (int)$id;

        return $this->connection->run(function () use ($sets, $values) {
            $statement = $this->modx->prepare(
                'UPDATE ' . $this->modx->getTableName('mxBackupRun')
                . ' SET ' . implode(', ', $sets) . ' WHERE `id` = ?'
            );

            return $statement && $statement->execute($values);
        });
    }

    public function requestCancel($id = 0)
    {
        $table = $this->modx->getTableName('mxBackupRun');
        $run = $this->connection->run(function () use ($table, $id) {
            $select = $this->modx->prepare(
                'SELECT `id`, `storage_path` FROM ' . $table . ' WHERE `status` = \'running\''
                . ($id ? ' AND `id` = ?' : ' ORDER BY `id` DESC LIMIT 1')
            );
            if (!$select || !$select->execute($id ? [(int) $id] : [])) {
                return false;
            }
            return $select->fetch(\PDO::FETCH_ASSOC);
        });
        if (!$run) {
            return false;
        }
        $id = (int) $run['id'];
        $statement = $this->modx->prepare(
            'UPDATE ' . $table . ' SET `status` = \'cancelling\' WHERE `id` = ? AND `status` = \'running\''
        );
        if (!$statement || !$statement->execute([$id]) || $statement->rowCount() < 1) {
            return false;
        }
        $marker = rtrim((string) $run['storage_path'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.mxbackup-cancel-' . $id;
        if (@file_put_contents($marker, (string) time(), LOCK_EX) === false) {
            $rollback = $this->modx->prepare('UPDATE ' . $table . ' SET `status` = \'running\' WHERE `id` = ? AND `status` = \'cancelling\'');
            if ($rollback) $rollback->execute([$id]);
            return false;
        }
        @chmod($marker, 0600);
        return true;
    }

    public function isCancellationRequested($id)
    {
        // Замыкание отдаёт статус, а не готовый ответ: «не отменяли» — обычный
        // результат, и принимать его за обрыв соединения значит слать лишний
        // пинг на каждую проверку отмены.
        $status = $this->connection->run(function () use ($id) {
            $statement = $this->modx->prepare(
                'SELECT `status` FROM ' . $this->modx->getTableName('mxBackupRun') . ' WHERE `id` = ?'
            );
            if (!$statement || !$statement->execute([(int) $id])) {
                return false;
            }
            return (string) $statement->fetchColumn();
        });

        return $status === 'cancelling';
    }
}
