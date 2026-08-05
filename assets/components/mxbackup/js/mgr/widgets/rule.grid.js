MxBackup.grid.Rules = function (config) {
    config=config||{}; Ext.apply(config,{
        id:'mxbackup-grid-rules',url:MxBackup.config.connectorUrl,baseParams:{action:'mgr/rule/getlist'},
        fields:['id','profile_id','profile_name','target_type','target','action','value','priority','active'],paging:true,pageSize:30,
        columns:[
            {header:_('mxbackup_profile'),dataIndex:'profile_name',width:100},
            {header:_('mxbackup_target_type'),dataIndex:'target_type',width:100},
            {header:_('mxbackup_target'),dataIndex:'target',width:220},
            {header:_('mxbackup_action'),dataIndex:'action',width:90},
            {header:_('mxbackup_priority'),dataIndex:'priority',width:70},
            {header:_('mxbackup_active'),dataIndex:'active',width:60}
        ],
        tbar:MxBackup.config.canManage?[{text:_('mxbackup_add_rule'),handler:this.createRule,scope:this}]:[],
        listeners:{rowDblClick:{fn:this.updateRule,scope:this}}
    }); MxBackup.grid.Rules.superclass.constructor.call(this,config);
};
Ext.extend(MxBackup.grid.Rules,MODx.grid.Grid,{
    createRule:function(){this.ruleWindow(_('mxbackup_add_rule'),'mgr/rule/create',{});},
    updateRule:function(grid,row){if(!MxBackup.config.canManage)return;this.ruleWindow(_('mxbackup_edit_rule'),'mgr/rule/update',grid.store.getAt(row).data);},
    ruleWindow:function(title,action,record){var grid=this,win=new MODx.Window({title:title,width:650,fields:[
        {xtype:'hidden',name:'id',value:record.id||''},
        {xtype:'numberfield',fieldLabel:_('mxbackup_profile_id'),name:'profile_id',anchor:'100%',value:record.profile_id||''},
        {xtype:'textfield',fieldLabel:_('mxbackup_target_type'),name:'target_type',anchor:'100%',value:record.target_type||'column'},
        {xtype:'textfield',fieldLabel:_('mxbackup_target'),name:'target',anchor:'100%',value:record.target||''},
        {xtype:'textfield',fieldLabel:_('mxbackup_action'),name:'action',anchor:'100%',value:record.action||'mask'},
        {xtype:'textarea',fieldLabel:_('mxbackup_value'),name:'value',anchor:'100%',value:record.value||''},
        {xtype:'numberfield',fieldLabel:_('mxbackup_priority'),name:'priority',anchor:'100%',value:record.priority||0},
        {xtype:'checkbox',fieldLabel:_('mxbackup_active'),name:'active',checked:record.active===undefined?true:MxBackup.bool(record.active)}
    ],url:MxBackup.config.connectorUrl,action:action,listeners:{success:{fn:function(){grid.refresh();win.close();}}}});win.show();}
});
Ext.reg('mxbackup-grid-rules',MxBackup.grid.Rules);
