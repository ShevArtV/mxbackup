<?php
$xpdo_meta_map['mxBackupRule'] = [
    'package' => 'mxbackup', 'version' => '1.1', 'table' => 'mxbackup_rule', 'extends' => 'xPDOSimpleObject',
    'fields' => [
        'profile_id'=>0, 'target_type'=>'column', 'target'=>'', 'action'=>'mask', 'value'=>null,
        'priority'=>0, 'active'=>1, 'createdon'=>0, 'editedon'=>0,
    ],
    'fieldMeta' => [
        'profile_id'=>['dbtype'=>'int','precision'=>'11','attributes'=>'unsigned','phptype'=>'integer','null'=>false,'default'=>0,'index'=>'index'],
        'target_type'=>['dbtype'=>'varchar','precision'=>'30','phptype'=>'string','null'=>false,'default'=>'column','index'=>'index'],
        'target'=>['dbtype'=>'varchar','precision'=>'255','phptype'=>'string','null'=>false,'default'=>''],
        'action'=>['dbtype'=>'varchar','precision'=>'20','phptype'=>'string','null'=>false,'default'=>'mask','index'=>'index'],
        'value'=>['dbtype'=>'text','phptype'=>'string','null'=>true],
        'priority'=>['dbtype'=>'int','precision'=>'11','phptype'=>'integer','null'=>false,'default'=>0,'index'=>'index'],
        'active'=>['dbtype'=>'tinyint','precision'=>'1','attributes'=>'unsigned','phptype'=>'boolean','null'=>false,'default'=>1,'index'=>'index'],
        'createdon'=>['dbtype'=>'int','precision'=>'20','attributes'=>'unsigned','phptype'=>'integer','null'=>false,'default'=>0],
        'editedon'=>['dbtype'=>'int','precision'=>'20','attributes'=>'unsigned','phptype'=>'integer','null'=>false,'default'=>0],
    ],
    'indexes' => [
        'profile_id'=>['alias'=>'profile_id','primary'=>false,'unique'=>false,'type'=>'BTREE','columns'=>['profile_id'=>['length'=>'','collation'=>'A','null'=>false]]],
        'priority'=>['alias'=>'priority','primary'=>false,'unique'=>false,'type'=>'BTREE','columns'=>['priority'=>['length'=>'','collation'=>'A','null'=>false]]],
        'active'=>['alias'=>'active','primary'=>false,'unique'=>false,'type'=>'BTREE','columns'=>['active'=>['length'=>'','collation'=>'A','null'=>false]]],
    ],
    'aggregates' => [
        'Profile'=>['class'=>'mxBackupProfile','local'=>'profile_id','foreign'=>'id','cardinality'=>'one','owner'=>'foreign'],
    ],
];
