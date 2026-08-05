<?php

namespace MxBackup\Platform\Modx2;

use MxBackup\Core\Contract\RunRepositoryInterface;

final class RunRepository implements RunRepositoryInterface
{
    private $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    public function start(array $data)
    {
        $run = $this->modx->newObject('mxBackupRun');
        $run->fromArray($data, '', true, true);
        return $run->save() ? (int)$run->get('id') : 0;
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
        $statement = $this->modx->prepare(
            'UPDATE ' . $this->modx->getTableName('mxBackupRun')
            . ' SET ' . implode(', ', $sets) . ' WHERE `id` = ?'
        );

        return $statement && $statement->execute($values);
    }
}
