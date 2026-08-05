<?php
if ($transport->xpdo) {
    $modx =& $transport->xpdo;
    if (in_array($options[xPDOTransport::PACKAGE_ACTION], [xPDOTransport::ACTION_INSTALL,xPDOTransport::ACTION_UPGRADE], true)) {
        $corePath=$modx->getOption('mxbackup.core_path',null,$modx->getOption('core_path').'components/mxbackup/');
        $modx->addPackage('mxbackup',$corePath.'model/');
        $defaults = [
            'prod'=>['mode'=>'prod','description'=>'Полный аварийный backup','config_json'=>['format'=>'tar.gz','files'=>['include'=>['*'],'exclude'=>['core/cache/','core/packages/','assets/cache/']],'database'=>['include_tables'=>['*'],'exclude_tables'=>[]],'masking'=>['standard'=>false,'rules'=>[]]]],
            'dev'=>['mode'=>'dev','description'=>'Обезличенный backup для разработки','config_json'=>['format'=>'tar.gz','files'=>['include'=>['*'],'exclude'=>['core/cache/','core/packages/','core/config/','assets/cache/','assets/uploads/private/']],'database'=>['include_tables'=>['*'],'exclude_tables'=>[]],'masking'=>['standard'=>true,'rules'=>[]]]],
        ];
        foreach($defaults as $name=>$data){
            if($modx->getCount('mxBackupProfile',['name'=>$name]))continue;
            $profile=$modx->newObject('mxBackupProfile');$profile->fromArray(array_merge($data,['name'=>$name,'active'=>1,'createdon'=>time(),'editedon'=>time()]),'',true,true);$profile->save();
        }
    }
}
return true;
