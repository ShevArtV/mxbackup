MxBackup.grid.Profiles = function (config) {
    config = config || {};
    Ext.apply(config, {
        id: 'mxbackup-grid-profiles', url: MxBackup.config.connectorUrl,
        baseParams: {action: 'mgr/profile/getlist'},
        fields: ['id','name','description','mode','active','format','encryption_enabled','encryption_password_set','file_include','file_exclude','standard_masking','remote_driver','remote_keep_local','remote_retention_days','remote_retention_count','remote_s3_bucket','remote_s3_region','remote_s3_prefix','remote_s3_endpoint','remote_s3_storage_class','remote_s3_access_key','remote_s3_secret_set','createdon','editedon'],
        paging: true, pageSize: 20, remoteSort: true, autosave: false,
        columns: [
            {header: _('mxbackup_name'), dataIndex: 'name', width: 130},
            {header: _('mxbackup_mode'), dataIndex: 'mode', width: 85, renderer: this.renderMode},
            {header: _('mxbackup_format'), dataIndex: 'format', width: 75},
            {header: _('mxbackup_encryption_short'), dataIndex: 'encryption_enabled', width: 95, renderer: this.renderBool},
            {header: _('mxbackup_remote_short'), dataIndex: 'remote_driver', width: 95, renderer: this.renderRemote},
            {header: _('mxbackup_description'), dataIndex: 'description', width: 320},
            {header: _('mxbackup_standard_masking_short'), dataIndex: 'standard_masking', width: 120, renderer: this.renderBool},
            {header: _('mxbackup_active'), dataIndex: 'active', width: 70, renderer: this.renderBool}
        ],
        tbar: MxBackup.config.canManage ? [{text: _('mxbackup_add_profile'), handler: this.createProfile, scope: this}] : [],
        listeners: {rowDblClick: {fn: this.updateProfile, scope: this}}
    });
    MxBackup.grid.Profiles.superclass.constructor.call(this, config);
};
Ext.extend(MxBackup.grid.Profiles, MODx.grid.Grid, {
    renderBool: function (value) { return MxBackup.bool(value) ? '<span class="mxbackup-badge success">' + _('yes') + '</span>' : '<span class="mxbackup-badge muted">' + _('no') + '</span>'; },
    renderMode: function (value) { return _('mxbackup_mode_' + value) || value; },
    renderRemote: function (value) {
        if (!value) return '<span class="mxbackup-badge muted">' + _('mxbackup_remote_off') + '</span>';
        return '<span class="mxbackup-badge success">' + Ext.util.Format.htmlEncode(value) + '</span>';
    },
    createProfile: function () { this.profileWindow(_('mxbackup_add_profile'), 'mgr/profile/create', {}); },
    updateProfile: function (grid, row) {
        if (!MxBackup.config.canManage) return;
        this.profileWindow(_('mxbackup_edit_profile'), 'mgr/profile/update', grid.store.getAt(row).data);
    },
    getMenu: function () {
        if (!MxBackup.config.canManage) return [];
        var record = this.menu.record || {};
        return [{
            text: _('edit'),
            handler: function () {
                this.profileWindow(_('mxbackup_edit_profile'), 'mgr/profile/update', record);
            },
            scope: this
        }];
    },
    profileWindow: function (title, action, record) {
        var grid = this;
        var win = new MODx.Window({
            title: title, width: 780, labelWidth: 180,
            fields: [
                {xtype: 'hidden', name: 'id', value: record.id || ''},
                {xtype: 'textfield', fieldLabel: _('mxbackup_name'), name: 'name', anchor: '100%', value: record.name || ''},
                {xtype: 'modx-combo', fieldLabel: _('mxbackup_mode'), name: 'mode_display', hiddenName: 'mode', store: new Ext.data.ArrayStore({fields:['value','display'],data:[['prod',_('mxbackup_mode_prod')],['dev',_('mxbackup_mode_dev')],['custom',_('mxbackup_mode_custom')]]}), displayField:'display', valueField:'value', mode:'local', triggerAction:'all', editable:false, anchor:'100%', value:record.mode || 'custom'},
                {xtype: 'modx-combo', fieldLabel: _('mxbackup_format'), name: 'format_display', hiddenName: 'format', store: new Ext.data.ArrayStore({fields:['value','display'],data:[['tar.gz','tar.gz'],['zip','ZIP']]}), displayField:'display', valueField:'value', mode:'local', triggerAction:'all', editable:false, anchor:'100%', value:record.format || 'tar.gz'},
                {xtype: 'hidden', name: 'encryption_enabled', value: 0},
                {xtype: 'checkbox', fieldLabel: _('mxbackup_encryption'), description: _('mxbackup_encryption_desc'), name: 'encryption_enabled', inputValue: 1, checked: MxBackup.bool(record.encryption_enabled)},
                {xtype: 'textfield', inputType: 'password', fieldLabel: _('mxbackup_encryption_password'), description: record.encryption_password_set ? _('mxbackup_encryption_password_keep_desc') : _('mxbackup_encryption_password_desc'), name: 'encryption_password', anchor: '100%', value: ''},
                {xtype: 'textarea', fieldLabel: _('mxbackup_description'), name: 'description', height: 60, anchor: '100%', value: record.description || ''},
                {xtype: 'fieldset', title: _('mxbackup_files'), anchor: '100%', defaults:{anchor:'100%'}, items: [
                    {xtype: 'textarea', fieldLabel: _('mxbackup_file_include'), description: _('mxbackup_file_include_desc'), name: 'file_include', height: 75, value: record.file_include || '*'},
                    {xtype: 'textarea', fieldLabel: _('mxbackup_file_exclude'), description: _('mxbackup_file_exclude_desc'), name: 'file_exclude', height: 110, value: record.file_exclude || ''}
                ]},
                {xtype: 'checkbox', fieldLabel: _('mxbackup_standard_masking'), description: _('mxbackup_standard_masking_desc'), name: 'standard_masking', checked: record.mode === 'dev' || MxBackup.bool(record.standard_masking)},
                /*
                 * Удалённое хранилище. Секция collapsed по умолчанию: она нужна
                 * меньшинству сайтов, а форма профиля и без неё длинная.
                 */
                {xtype: 'fieldset', title: _('mxbackup_remote'), anchor: '100%', checkboxToggle: false, collapsible: true, collapsed: !record.remote_driver, defaults: {anchor: '100%'}, items: [
                    {xtype: 'modx-combo', fieldLabel: _('mxbackup_remote_driver'), description: _('mxbackup_remote_driver_desc'), name: 'remote_driver_display', hiddenName: 'remote_driver', store: new Ext.data.ArrayStore({fields:['value','display'],data:[['',_('mxbackup_remote_off')],['s3','S3']]}), displayField:'display', valueField:'value', mode:'local', triggerAction:'all', editable:false, value: record.remote_driver || ''},
                    {xtype: 'textfield', fieldLabel: _('mxbackup_remote_s3_bucket'), name: 'remote_s3_bucket', value: record.remote_s3_bucket || ''},
                    {xtype: 'textfield', fieldLabel: _('mxbackup_remote_s3_region'), description: _('mxbackup_remote_s3_region_desc'), name: 'remote_s3_region', value: record.remote_s3_region || ''},
                    {xtype: 'textfield', fieldLabel: _('mxbackup_remote_s3_prefix'), description: _('mxbackup_remote_s3_prefix_desc'), name: 'remote_s3_prefix', value: record.remote_s3_prefix || ''},
                    {xtype: 'textfield', fieldLabel: _('mxbackup_remote_s3_endpoint'), description: _('mxbackup_remote_s3_endpoint_desc'), name: 'remote_s3_endpoint', value: record.remote_s3_endpoint || ''},
                    {xtype: 'textfield', fieldLabel: _('mxbackup_remote_s3_storage_class'), description: _('mxbackup_remote_s3_storage_class_desc'), name: 'remote_s3_storage_class', value: record.remote_s3_storage_class || ''},
                    {xtype: 'textfield', fieldLabel: _('mxbackup_remote_s3_access_key'), description: _('mxbackup_remote_s3_access_key_desc'), name: 'remote_s3_access_key', value: record.remote_s3_access_key || ''},
                    {xtype: 'textfield', inputType: 'password', fieldLabel: _('mxbackup_remote_s3_secret_key'), description: record.remote_s3_secret_set ? _('mxbackup_remote_s3_secret_keep_desc') : _('mxbackup_remote_s3_secret_desc'), name: 'remote_s3_secret_key', value: ''},
                    {xtype: 'numberfield', fieldLabel: _('mxbackup_remote_keep_local'), description: _('mxbackup_remote_keep_local_desc'), name: 'remote_keep_local', allowDecimals: false, allowNegative: false, minValue: 1, value: record.remote_keep_local === undefined ? 2 : record.remote_keep_local},
                    {xtype: 'numberfield', fieldLabel: _('mxbackup_remote_retention_days'), description: _('mxbackup_remote_retention_desc'), name: 'remote_retention_days', allowDecimals: false, allowNegative: false, minValue: 0, value: record.remote_retention_days || 0},
                    {xtype: 'numberfield', fieldLabel: _('mxbackup_remote_retention_count'), name: 'remote_retention_count', allowDecimals: false, allowNegative: false, minValue: 0, value: record.remote_retention_count || 0}
                ]},
                {xtype: 'hidden', name: 'active', value: 0},
                {xtype: 'checkbox', fieldLabel: _('mxbackup_active'), name: 'active', inputValue: 1, checked: record.active === undefined ? true : MxBackup.bool(record.active)}
            ],
            url: MxBackup.config.connectorUrl, action: action,
            listeners: {success: {fn: function () { grid.refresh(); win.close(); }, scope: this}}
        });
        win.show();
    }
});
Ext.reg('mxbackup-grid-profiles', MxBackup.grid.Profiles);
