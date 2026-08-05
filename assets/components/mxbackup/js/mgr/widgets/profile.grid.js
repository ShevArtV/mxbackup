MxBackup.grid.Profiles = function (config) {
    config = config || {};
    Ext.apply(config, {
        id: 'mxbackup-grid-profiles', url: MxBackup.config.connectorUrl, baseParams: {action: 'mgr/profile/getlist'},
        fields: ['id','name','description','mode','active','config_json','createdon','editedon'],
        paging: true, pageSize: 20, remoteSort: true, autosave: false,
        columns: [
            {header: _('id'), dataIndex: 'id', width: 50},
            {header: _('mxbackup_name'), dataIndex: 'name', width: 140},
            {header: _('mxbackup_mode'), dataIndex: 'mode', width: 90},
            {header: _('mxbackup_description'), dataIndex: 'description', width: 260},
            {header: _('mxbackup_active'), dataIndex: 'active', width: 70, renderer: this.renderBool}
        ],
        tbar: MxBackup.config.canManage ? [{text: _('mxbackup_add_profile'), handler: this.createProfile, scope: this}] : [],
        listeners: {rowDblClick: {fn: this.updateProfile, scope: this}}
    });
    MxBackup.grid.Profiles.superclass.constructor.call(this, config);
};
Ext.extend(MxBackup.grid.Profiles, MODx.grid.Grid, {
    renderBool: function(v){return MxBackup.bool(v) ? _('yes') : _('no');},
    createProfile: function(){this.profileWindow(_('mxbackup_add_profile'), 'mgr/profile/create', {});},
    updateProfile: function(grid,row){if(!MxBackup.config.canManage)return; this.profileWindow(_('mxbackup_edit_profile'), 'mgr/profile/update', grid.store.getAt(row).data);},
    profileWindow: function(title, action, record){
        var grid=this, win=new MODx.Window({title:title, width:720, fields:[
            {xtype:'hidden',name:'id',value:record.id||''},
            {xtype:'textfield',fieldLabel:_('mxbackup_name'),name:'name',anchor:'100%',value:record.name||''},
            {xtype:'modx-combo',fieldLabel:_('mxbackup_mode'),name:'mode',store:new Ext.data.ArrayStore({fields:['value','display'],data:[['prod','prod'],['dev','dev'],['custom','custom']]}),displayField:'display',valueField:'value',mode:'local',anchor:'100%',value:record.mode||'custom'},
            {xtype:'textarea',fieldLabel:_('mxbackup_description'),name:'description',anchor:'100%',value:record.description||''},
            {xtype:'textarea',fieldLabel:'config_json',name:'config_json',height:220,anchor:'100%',value:typeof record.config_json==='string'?record.config_json:Ext.encode(record.config_json||{})},
            {xtype:'checkbox',fieldLabel:_('mxbackup_active'),name:'active',checked:record.active===undefined?true:MxBackup.bool(record.active)}
        ], url:MxBackup.config.connectorUrl, action:action, listeners:{success:{fn:function(){grid.refresh();win.close();}}}}); win.show();
    }
});
Ext.reg('mxbackup-grid-profiles', MxBackup.grid.Profiles);
