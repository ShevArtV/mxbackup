<?php

if (!$transport->xpdo) {
    return true;
}

$modx =& $transport->xpdo;
$action = isset($options[xPDOTransport::PACKAGE_ACTION]) ? $options[xPDOTransport::PACKAGE_ACTION] : null;

if (!in_array($action, [xPDOTransport::ACTION_INSTALL, xPDOTransport::ACTION_UPGRADE], true)) {
    return true;
}

$permissions = [
    'load' => 'Загрузка namespace mxBackup',
    'mxbackup_view' => 'Просмотр mxBackup',
    'mxbackup_manage' => 'Управление профилями и правилами',
    'mxbackup_run' => 'Запуск резервного копирования',
    'mxbackup_restore' => 'Восстановление из резервной копии',
];

$template = $modx->getObject('modAccessPolicyTemplate', ['name' => 'mxbackupTemplate']);
if (!$template) {
    $template = $modx->newObject('modAccessPolicyTemplate');
    $template->fromArray([
        'template_group' => 1,
        'name' => 'mxbackupTemplate',
        'description' => 'Права mxBackup',
        'lexicon' => 'permissions',
    ], '', true, true);
    $template->save();
}

$templateId = (int) $template->get('id');
foreach ($permissions as $name => $description) {
    if ($modx->getCount('modAccessPermission', ['template' => $templateId, 'name' => $name])) {
        continue;
    }

    $permission = $modx->newObject('modAccessPermission');
    $permission->fromArray([
        'template' => $templateId,
        'name' => $name,
        'description' => $description,
        'value' => 1,
    ], '', true, true);
    $permission->save();
}

$policy = $modx->getObject('modAccessPolicy', ['name' => 'mxbackupDefault']);
if (!$policy) {
    $policy = $modx->newObject('modAccessPolicy');
    $policy->fromArray([
        'name' => 'mxbackupDefault',
        'description' => 'Полный доступ к mxBackup',
        'parent' => 0,
        'template' => $templateId,
        'class' => '',
        'lexicon' => 'permissions',
    ], '', true, true);
}

$data = $policy->get('data');
if (!is_array($data)) {
    $data = [];
}
foreach (array_keys($permissions) as $name) {
    $data[$name] = true;
}
$policy->set('data', $data);
$policy->save();

$policyId = (int) $policy->get('id');
foreach (['Administrator', 'Developers'] as $groupName) {
    $group = $modx->getObject('modUserGroup', ['name' => $groupName]);
    if (!$group) {
        continue;
    }

    $criteria = [
        'target' => 'mgr',
        'principal_class' => 'modUserGroup',
        'principal' => (int) $group->get('id'),
        'policy' => $policyId,
    ];
    $access = $modx->getObject('modAccessContext', $criteria);
    if (!$access) {
        $access = $modx->newObject('modAccessContext');
        $access->fromArray($criteria + ['authority' => 9999], '', true, true);
    } else {
        $access->set('authority', 9999);
    }
    $access->save();
}

$menu = $modx->getObject('modMenu', ['text' => 'mxbackup']);
if ($menu && trim((string) $menu->get('permissions')) === '') {
    $menu->set('permissions', 'mxbackup_view');
    $menu->save();
}

return true;
