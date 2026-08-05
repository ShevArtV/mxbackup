<?php
require_once dirname(__DIR__) . '/run/create.class.php';
class mxBackupConfigDryrunProcessor extends mxBackupRunCreateProcessor
{
    public function initialize(){$this->setProperty('dry_run',1);return parent::initialize();}
}
return 'mxBackupConfigDryrunProcessor';
