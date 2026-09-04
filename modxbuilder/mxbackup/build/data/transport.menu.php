<?php
$menu = $this->modx->newObject('modMenu');
$menu->fromArray([
    'text'=>'mxbackup','parent'=>'components','description'=>'mxbackup_menu_desc','icon'=>'','menuindex'=>0,
    'params'=>'','handler'=>'','namespace'=>$namespace,'action'=>'index','permissions'=>'mxbackup_view',
], '', true, true);
return [$menu];
