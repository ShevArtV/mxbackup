<?php
$xpdo_meta_map['mxBackupProfile'] = [
    'package' => 'mxbackup', 'version' => '1.1', 'table' => 'mxbackup_profile', 'extends' => 'xPDOSimpleObject',
    'fields' => [
        'name' => '', 'description' => null, 'mode' => 'custom', 'active' => 1,
        'config_json' => null, 'createdon' => 0, 'editedon' => 0,
    ],
    'fieldMeta' => [
        'name' => ['dbtype'=>'varchar','precision'=>'100','phptype'=>'string','null'=>false,'default'=>'','index'=>'unique'],
        'description' => ['dbtype'=>'text','phptype'=>'string','null'=>true],
        'mode' => ['dbtype'=>'varchar','precision'=>'20','phptype'=>'string','null'=>false,'default'=>'custom','index'=>'index'],
        'active' => ['dbtype'=>'tinyint','precision'=>'1','attributes'=>'unsigned','phptype'=>'boolean','null'=>false,'default'=>1,'index'=>'index'],
        'config_json' => ['dbtype'=>'mediumtext','phptype'=>'json','null'=>true],
        'createdon' => ['dbtype'=>'int','precision'=>'20','attributes'=>'unsigned','phptype'=>'integer','null'=>false,'default'=>0],
        'editedon' => ['dbtype'=>'int','precision'=>'20','attributes'=>'unsigned','phptype'=>'integer','null'=>false,'default'=>0],
    ],
    'indexes' => [
        'name' => ['alias'=>'name','primary'=>false,'unique'=>true,'type'=>'BTREE','columns'=>['name'=>['length'=>'','collation'=>'A','null'=>false]]],
        'mode' => ['alias'=>'mode','primary'=>false,'unique'=>false,'type'=>'BTREE','columns'=>['mode'=>['length'=>'','collation'=>'A','null'=>false]]],
        'active' => ['alias'=>'active','primary'=>false,'unique'=>false,'type'=>'BTREE','columns'=>['active'=>['length'=>'','collation'=>'A','null'=>false]]],
    ],
    'composites' => [
        'Rules' => ['class'=>'mxBackupRule','local'=>'id','foreign'=>'profile_id','cardinality'=>'many','owner'=>'local'],
    ],
];
