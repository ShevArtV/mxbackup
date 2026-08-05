MxBackup.panel.Settings=function(config){config=config||{};Ext.apply(config,{
    id:'mxbackup-panel-settings',border:false,labelWidth:210,bodyStyle:'padding:15px',autoHeight:true,
    items:[
        {xtype:'textfield',fieldLabel:_('setting_mxbackup.storage_path'),name:'mxbackup.storage_path',anchor:'100%'},
        {xtype:'textfield',fieldLabel:_('setting_mxbackup.config_path'),name:'mxbackup.config_path',anchor:'100%'},
        {xtype:'textfield',fieldLabel:_('setting_mxbackup.default_profile'),name:'mxbackup.default_profile',anchor:'100%'},
        {xtype:'textfield',fieldLabel:_('setting_mxbackup.archive_format'),name:'mxbackup.archive_format',anchor:'100%'},
        {xtype:'checkbox',fieldLabel:_('setting_mxbackup.mail_enabled'),name:'mxbackup.mail_enabled'},
        {xtype:'textfield',fieldLabel:_('setting_mxbackup.mail_to'),name:'mxbackup.mail_to',anchor:'100%'},
        {xtype:'numberfield',fieldLabel:_('setting_mxbackup.mail_max_attachment_mb'),name:'mxbackup.mail_max_attachment_mb',anchor:'100%'},
        {xtype:'numberfield',fieldLabel:_('setting_mxbackup.retention_days'),name:'mxbackup.retention_days',anchor:'100%'},
        {xtype:'numberfield',fieldLabel:_('setting_mxbackup.retention_count'),name:'mxbackup.retention_count',anchor:'100%'},
        {xtype:'numberfield',fieldLabel:_('setting_mxbackup.lock_ttl_minutes'),name:'mxbackup.lock_ttl_minutes',anchor:'100%'}
    ],buttons:MxBackup.config.canManage?[{text:_('save'),handler:this.saveSettings,scope:this}]:[],listeners:{afterrender:{fn:this.loadSettings,scope:this}}
});MxBackup.panel.Settings.superclass.constructor.call(this,config);};
Ext.extend(MxBackup.panel.Settings,MODx.FormPanel,{
    loadSettings:function(){var panel=this;MxBackup.request('mgr/config/get',{},function(response){panel.getForm().setValues(response.object||{});});},
    saveSettings:function(){var panel=this,values=panel.getForm().getValues();values['mxbackup.mail_enabled']=panel.getForm().findField('mxbackup.mail_enabled').getValue()?1:0;MxBackup.request('mgr/config/update',values,function(){MODx.msg.status({title:_('success'),message:_('mxbackup_settings_saved')});panel.loadSettings();});}
});Ext.reg('mxbackup-panel-settings',MxBackup.panel.Settings);
