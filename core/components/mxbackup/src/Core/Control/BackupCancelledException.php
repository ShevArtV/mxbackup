<?php

namespace MxBackup\Core\Control;

use RuntimeException;

final class BackupCancelledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Резервное копирование остановлено пользователем.');
    }
}
