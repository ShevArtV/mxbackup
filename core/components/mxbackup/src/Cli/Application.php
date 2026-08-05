<?php

namespace MxBackup\Cli;

use MxBackup\Bootstrap;
use MxBackup\Core\Config\ConfigValidator;
use MxBackup\Core\Storage\LocalStorage;
use MxBackup\Core\Storage\PathValidator;
use MxBackup\Core\Storage\RetentionPolicy;
use Throwable;

final class Application
{
    private $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    public function run(array $argv)
    {
        $command = isset($argv[1]) ? (string)$argv[1] : 'help';
        $options = $this->parse(array_slice($argv, 2));
        if (!empty($options['verbose'])) {
            $this->modx->setLogTarget('ECHO');
            $this->modx->setLogLevel(\modX::LOG_LEVEL_INFO);
        }

        try {
            if ($command === 'help' || in_array($command, ['-h', '--help'], true)) {
                $this->help();
                return 0;
            }
            $config = Bootstrap::config($this->modx, $this->toConfig($options));
            if ($command === 'list-profiles') {
                foreach ($config['profiles'] as $name => $profile) {
                    echo $name . "\t" . (isset($profile['mode']) ? $profile['mode'] : 'custom') . PHP_EOL;
                }
                return 0;
            }
            if ($command === 'validate-config') {
                return $this->validate($config);
            }
            if ($command === 'cleanup') {
                return $this->cleanup($config);
            }
            if ($command === 'restore-check' || $command === 'restore') {
                return $this->restore($command, $config, $options);
            }
            if ($command === 'backup' || $command === 'dry-run') {
                $dry = $command === 'dry-run' || !empty($options['dry-run']);
                $result = Bootstrap::runner($this->modx)->run($config, $dry);
                echo json_encode([
                    'success' => $result->isSuccess(),
                    'archive' => $result->getArchivePath(),
                    'report' => $result->getReport(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                return $result->isSuccess() ? 0 : 1;
            }
            fwrite(STDERR, 'Неизвестная команда: ' . $command . PHP_EOL);
            $this->help();
            return 2;
        } catch (Throwable $e) {
            fwrite(STDERR, '[mxBackup] ' . $e->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private function validate(array $config)
    {
        $errors = (new ConfigValidator())->validate($config);
        $profile = $config['profile'];
        $format = $profile['format'];
        if ($format === 'zip' && !class_exists('ZipArchive')) $errors[] = 'Отсутствует ext-zip.';
        if ($format === 'tar.gz' && !class_exists('PharData')) $errors[] = 'Отсутствует Phar.';
        try {
            $platform = Bootstrap::platform($this->modx);
            $root = realpath($platform->getSiteRoot());
            $path = isset($profile['storage_path']) && $profile['storage_path'] !== '' ? $profile['storage_path'] : $config['storage_path'];
            if ($path === '') $path = dirname($root) . DIRECTORY_SEPARATOR . 'backups';
            (new PathValidator())->validateCandidate($path, $root, !empty($config['allow_web_storage']));
        } catch (Throwable $e) {
            $errors[] = 'Каталог хранения: ' . $e->getMessage();
        }
        try {
            $tables = Bootstrap::platform($this->modx)->database()->listTables();
            if (!$tables) $errors[] = 'В базе данных не найдено таблиц.';
        } catch (Throwable $e) {
            $errors[] = 'База данных недоступна: ' . $e->getMessage();
        }
        if ($errors) {
            foreach ($errors as $error) fwrite(STDERR, 'ERROR: ' . $error . PHP_EOL);
            return 1;
        }
        echo "Конфигурация корректна.\n";
        return 0;
    }

    private function cleanup(array $config)
    {
        $root = realpath(Bootstrap::platform($this->modx)->getSiteRoot());
        $path = isset($config['profile']['storage_path']) && $config['profile']['storage_path'] !== ''
            ? $config['profile']['storage_path'] : $config['storage_path'];
        if ($path === '') $path = dirname($root) . DIRECTORY_SEPARATOR . 'backups';
        $path = (new PathValidator())->validateCandidate($path, $root, !empty($config['allow_web_storage']));
        (new LocalStorage())->ensureDirectory($path);
        $path = (new PathValidator())->validate($path, $root, !empty($config['allow_web_storage']));
        $deleted = (new RetentionPolicy())->cleanup($path, $config['retention']['days'], $config['retention']['count']);
        echo json_encode(['deleted' => $deleted], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return 0;
    }

    private function restore($command, array $config, array $options)
    {
        if (empty($options['archive'])) {
            throw new \RuntimeException('Укажите --archive=/absolute/path/backup.zip.');
        }
        $password = $this->archivePassword($options);
        $runner = Bootstrap::restoreRunner($this->modx);
        try {
            $preflight = $runner->preflight(
                $options['archive'],
                $password,
                isset($options['checksum']) ? $options['checksum'] : null
            );
        } catch (\RuntimeException $e) {
            if ($password === null && strpos($e->getMessage(), 'проверьте пароль') !== false && $this->isInteractive()) {
                $password = $this->promptPassword();
                $preflight = $runner->preflight(
                    $options['archive'],
                    $password,
                    isset($options['checksum']) ? $options['checksum'] : null
                );
            } else {
                throw $e;
            }
        }
        if ($command === 'restore-check') {
            echo json_encode($preflight, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            return 0;
        }
        if (empty($options['confirm'])) {
            fwrite(STDERR, 'Preflight пройден. Для восстановления повторите команду с --confirm=' . $preflight['confirmation'] . PHP_EOL);
            return 2;
        }
        $result = $runner->restore(
            $config,
            $options['archive'],
            isset($options['scope']) ? $options['scope'] : 'all',
            $options['confirm'],
            $password,
            isset($options['checksum']) ? $options['checksum'] : null
        );
        echo json_encode([
            'success' => $result->isSuccess(),
            'report' => $result->getReport(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return $result->isSuccess() ? 0 : 1;
    }

    private function archivePassword(array $options)
    {
        $environment = getenv('MXBACKUP_ARCHIVE_PASSWORD');
        if ($environment !== false && $environment !== '') return (string)$environment;
        if (empty($options['password-file'])) return null;
        $path = realpath((string)$options['password-file']);
        if ($path === false || !is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Файл пароля не существует или недоступен.');
        }
        $password = rtrim((string)file_get_contents($path), "\r\n");
        if ($password === '') throw new \RuntimeException('Файл пароля пуст.');
        return $password;
    }

    private function isInteractive()
    {
        return function_exists('stream_isatty') ? @stream_isatty(STDIN) : (function_exists('posix_isatty') && @posix_isatty(STDIN));
    }

    private function promptPassword()
    {
        fwrite(STDERR, 'Пароль архива: ');
        $stty = function_exists('shell_exec') ? @shell_exec('stty -g 2>/dev/null') : null;
        if ($stty) @shell_exec('stty -echo 2>/dev/null');
        $password = fgets(STDIN);
        if ($stty) {
            @shell_exec('stty ' . escapeshellarg(trim($stty)) . ' 2>/dev/null');
            fwrite(STDERR, PHP_EOL);
        }
        $password = rtrim((string)$password, "\r\n");
        if ($password === '') throw new \RuntimeException('Пароль не введён.');
        return $password;
    }

    private function parse(array $arguments)
    {
        $result = [];
        foreach ($arguments as $argument) {
            if (substr($argument, 0, 2) !== '--') continue;
            $parts = explode('=', substr($argument, 2), 2);
            $result[$parts[0]] = count($parts) === 2 ? $parts[1] : true;
        }
        return $result;
    }

    private function toConfig(array $options)
    {
        $config = [];
        foreach (['profile', 'format'] as $key) {
            if (isset($options[$key])) $config[$key] = $options[$key];
        }
        if (isset($options['storage-path'])) $config['storage_path'] = $options['storage-path'];
        if (isset($options['config'])) $config['config_path'] = $options['config'];
        if (isset($options['mail-to'])) {
            $config['mail'] = ['enabled' => true, 'to' => $options['mail-to']];
        }
        if (!empty($options['no-mail'])) {
            $config['mail'] = ['enabled' => false];
        }
        return $config;
    }

    private function help()
    {
        echo <<<'TXT'
mxBackup 1.2.1-rc

Usage:
  mxbackup.php backup --profile=prod [options]
  mxbackup.php dry-run --profile=dev [options]
  mxbackup.php validate-config [options]
  mxbackup.php list-profiles [options]
  mxbackup.php cleanup [options]
  mxbackup.php restore-check --archive=/absolute/path/backup.zip [options]
  mxbackup.php restore --archive=/absolute/path/backup.zip --scope=all --confirm=TOKEN [options]

Options:
  --profile=NAME
  --storage-path=/absolute/path
  --config=/absolute/path/mxbackup.php
  --format=tar.gz|zip
  --mail-to=user@example.test
  --no-mail
  --dry-run
  --verbose
  --archive=/absolute/path/backup.zip
  --scope=all|files|database
  --checksum=EXPECTED_SHA256
  --confirm=TOKEN
  --password-file=/absolute/path/password.txt

Password may also be provided via MXBACKUP_ARCHIVE_PASSWORD. It is never printed.

TXT;
    }
}
