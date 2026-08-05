<?php
if ($transport->xpdo) {
    $modx =& $transport->xpdo;
    switch ($options[xPDOTransport::PACKAGE_ACTION]) {
        case xPDOTransport::ACTION_INSTALL:
        case xPDOTransport::ACTION_UPGRADE:
            $corePath = $modx->getOption('mxbackup.core_path', null, $modx->getOption('core_path').'components/mxbackup/');
            $modx->addPackage('mxbackup', $corePath.'model/');
            $manager = $modx->getManager();
            foreach (['mxBackupProfile','mxBackupRule','mxBackupRun'] as $class) {
                $manager->createObjectContainer($class);
                $table = $modx->getTableName($class);
                $status = $modx->query("SHOW TABLE STATUS LIKE ".$modx->quote($table));
                $row = $status ? $status->fetch(PDO::FETCH_ASSOC) : false;
                if (!$row || stripos((string)$row['Collation'], 'utf8mb4') !== 0) {
                    try {$modx->exec("ALTER TABLE {$table} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");}
                    catch (Exception $e) {$modx->log(modX::LOG_LEVEL_ERROR,'[mxbackup] Charset: '.$e->getMessage());}
                }
            }
            break;
        case xPDOTransport::ACTION_UNINSTALL:
            break;
    }
}
return true;
