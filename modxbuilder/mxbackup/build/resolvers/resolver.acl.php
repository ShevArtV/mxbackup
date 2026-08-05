<?php
if ($transport->xpdo) {
    $modx =& $transport->xpdo;
    if (in_array($options[xPDOTransport::PACKAGE_ACTION], [xPDOTransport::ACTION_INSTALL,xPDOTransport::ACTION_UPGRADE], true)) {
        $permissions=['load'=>'Загрузка namespace mxBackup','mxbackup_view'=>'Просмотр mxBackup','mxbackup_manage'=>'Управление профилями и правилами','mxbackup_run'=>'Запуск резервного копирования'];
        $template=$modx->getObject('modAccessPolicyTemplate',['name'=>'mxbackupTemplate']);
        if(!$template){$template=$modx->newObject('modAccessPolicyTemplate');$template->fromArray(['template_group'=>1,'name'=>'mxbackupTemplate','description'=>'Права mxBackup','lexicon'=>'permissions'],'',true,true);$template->save();}
        foreach($permissions as $name=>$description){if(!$modx->getCount('modAccessPermission',['template'=>$template->get('id'),'name'=>$name])){$permission=$modx->newObject('modAccessPermission');$permission->fromArray(['template'=>$template->get('id'),'name'=>$name,'description'=>$description,'value'=>1],'',true,true);$permission->save();}}
        $policy=$modx->getObject('modAccessPolicy',['name'=>'mxbackupDefault']);
        if(!$policy){$policy=$modx->newObject('modAccessPolicy');$policy->fromArray(['name'=>'mxbackupDefault','description'=>'Полный доступ к mxBackup','parent'=>0,'template'=>$template->get('id'),'class'=>'','lexicon'=>'permissions'],'',true,true);$policy->set('data',array_fill_keys(array_keys($permissions),true));$policy->save();}
    }
}
return true;
