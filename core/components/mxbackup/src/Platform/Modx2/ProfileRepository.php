<?php

namespace MxBackup\Platform\Modx2;

use MxBackup\Core\Config\ProfileStore;
use MxBackup\Core\Contract\ProfileRepositoryInterface;

final class ProfileRepository implements ProfileRepositoryInterface
{
    private $store;

    public function __construct(\modX $modx)
    {
        $this->store = new ProfileStore(self::configDirectory($modx));
    }

    public function all()
    {
        return $this->store->all(true);
    }

    public function find($name)
    {
        $profile = $this->store->find($name);
        return $profile && !empty($profile['active']) ? $profile : null;
    }

    public function getStore()
    {
        return $this->store;
    }

    public static function configDirectory(\modX $modx)
    {
        $corePath = rtrim((string) $modx->getOption('core_path', null, MODX_CORE_PATH), '/\\') . DIRECTORY_SEPARATOR;
        $configured = trim((string) $modx->getOption('mxbackup.config_dir', null, ''));
        if ($configured === '') {
            return $corePath . 'config' . DIRECTORY_SEPARATOR . 'mxbackup' . DIRECTORY_SEPARATOR . 'profiles';
        }

        $configured = str_replace(
            ['{core_path}', '[[++core_path]]'],
            [$corePath, $corePath],
            $configured
        );
        if (!self::isAbsolutePath($configured)) {
            $configured = $corePath . ltrim($configured, '/\\');
        }
        return rtrim($configured, '/\\');
    }

    private static function isAbsolutePath($path)
    {
        return strpos($path, DIRECTORY_SEPARATOR) === 0
            || preg_match('/^[a-z]:[\\\\\/]/i', $path) === 1;
    }
}
