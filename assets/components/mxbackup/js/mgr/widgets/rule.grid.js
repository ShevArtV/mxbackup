MxBackup.grid.Masking = function (config) {
    config = config || {};
    this.profileCombo = new MxBackup.combo.Profile({id: 'mxbackup-masking-profile'});
    this.tableCombo = new MxBackup.combo.Table({id: 'mxbackup-masking-table'});
    Ext.apply(config, {
        id: 'mxbackup-grid-masking', url: MxBackup.config.connectorUrl,
        baseParams: {action: 'mgr/database/column/getlist', profile_id: 0, table: ''},
        fields: ['column','type','nullable','action','source','rule_id','target_type','target','json_path','value','priority'],
        height: 520, autoHeight: false, paging: false, remoteSort: false, autosave: false,
        columns: [
            {header: _('mxbackup_column'), dataIndex: 'column', width: 220, renderer: this.renderColumn},
            {header: _('mxbackup_sql_type'), dataIndex: 'type', width: 180},
            {header: _('mxbackup_masking_action'), dataIndex: 'action', width: 170, renderer: this.renderAction},
            {header: _('mxbackup_rule_source'), dataIndex: 'source', width: 150, renderer: this.renderSource},
            {header: _('mxbackup_value'), dataIndex: 'value', width: 180}
        ],
        tbar: [
            '<b>' + _('mxbackup_profile') + ':</b>', this.profileCombo, '-',
            '<b>' + _('mxbackup_table') + ':</b>', this.tableCombo, '->',
            {text: _('mxbackup_truncate_table'), disabled: !MxBackup.config.canManage, handler: this.truncateTable, scope: this}
        ],
        listeners: {rowDblClick: {fn: this.editRule, scope: this}},
        viewConfig: {emptyText: _('mxbackup_select_table')}
    });
    MxBackup.grid.Masking.superclass.constructor.call(this, config);
    this.profileCombo.on('select', function (combo, record) { this.loadProfile(record.get('id')); }, this);
    this.profileCombo.store.on('load', this.selectDefaultProfile, this);
    this.tableCombo.on('select', function (combo, record) { this.loadTable(record.get('name')); }, this);
    this.tableCombo.store.on('load', function (store) {
        var record = null;
        store.each(function (candidate) {
            var name = String(candidate.get('name') || '');
            if (!record && /(^|_)users$/.test(name) && !/(^|_)active_users$/.test(name)) record = candidate;
        });
        record = record || store.getAt(0);
        if (record) { this.tableCombo.setValue(record.get('name')); this.loadTable(record.get('name')); }
    }, this);
    this.profileCombo.store.load();
};
Ext.extend(MxBackup.grid.Masking, MODx.grid.Grid, {
    renderColumn: function (value) { return value === '*' ? '<b>' + _('mxbackup_whole_table') + '</b>' : MxBackup.escape(value); },
    renderAction: function (value) {
        if (!value) return '<span class="mxbackup-badge muted">' + _('mxbackup_action_none') + '</span>';
        var cls = value === 'truncate' || value === 'hide' ? 'warning' : 'info';
        return '<span class="mxbackup-badge ' + cls + '">' + MxBackup.escape(MxBackup.actionLabel(value)) + '</span>';
    },
    renderSource: function (value) {
        if (value === 'standard') return '<span class="mxbackup-badge success">' + _('mxbackup_source_standard') + '</span>';
        if (value === 'custom') return '<span class="mxbackup-badge info">' + _('mxbackup_source_custom') + '</span>';
        return '<span class="mxbackup-badge muted">' + _('mxbackup_source_none') + '</span>';
    },
    selectDefaultProfile: function (store) {
        var index = store.findExact('name', 'dev');
        if (index < 0) index = 0;
        var record = store.getAt(index);
        if (record) { this.profileCombo.setValue(record.get('id')); this.loadProfile(record.get('id')); }
    },
    loadProfile: function (profileId) {
        this.store.removeAll();
        this.store.baseParams.profile_id = profileId;
        this.tableCombo.reset();
        this.tableCombo.store.baseParams.profile_id = profileId;
        this.tableCombo.store.load();
    },
    loadTable: function (table) {
        this.store.baseParams.profile_id = this.profileCombo.getValue();
        this.store.baseParams.table = table;
        this.store.load();
    },
    getMenu: function () {
        if (!MxBackup.config.canManage) return [];
        var data = this.menu.record || {};
        var index = this.store.findExact('column', data.column);
        var record = index < 0 ? null : this.store.getAt(index);
        if (!record) return [];
        var menu = [];
        if (record.get('column') !== '*') menu.push({text: record.get('source') === 'custom' ? _('mxbackup_edit_rule') : _('mxbackup_add_rule'), handler: function(){this.ruleWindow(record);}, scope:this});
        if (record.get('source') === 'custom' && record.get('rule_id')) menu.push({text:_('remove'), handler:function(){this.removeRule(record);}, scope:this});
        return menu;
    },
    editRule: function (grid, row) {
        if (!MxBackup.config.canManage) return;
        var record = grid.store.getAt(row);
        if (record.get('column') !== '*') this.ruleWindow(record);
    },
    ruleWindow: function (record) {
        var grid = this, actionCombo = new MODx.combo.ComboBox({
            fieldLabel:_('mxbackup_masking_action'),name:'mask_action',anchor:'100%',mode:'local',triggerAction:'all',editable:false,
            store:new Ext.data.ArrayStore({fields:['value','display'],data:[['mask',_('mxbackup_action_mask')],['hide',_('mxbackup_action_hide')],['hash',_('mxbackup_action_hash')],['replace',_('mxbackup_action_replace')]]}),
            displayField:'display',valueField:'value',value:record.get('action') || 'mask'
        });
        var win = new MODx.Window({
            title: record.get('source') === 'custom' ? _('mxbackup_edit_rule') : _('mxbackup_add_rule'), width: 620, labelWidth: 160,
            fields:[
                {xtype:'displayfield',fieldLabel:_('mxbackup_table'),value:MxBackup.escape(this.tableCombo.getValue())},
                {xtype:'displayfield',fieldLabel:_('mxbackup_column'),value:MxBackup.escape(record.get('column'))},
                actionCombo,
                {xtype:'textfield',fieldLabel:_('mxbackup_json_path'),description:_('mxbackup_json_path_desc'),name:'json_path',anchor:'100%',value:record.get('json_path') || ''},
                {xtype:'textarea',fieldLabel:_('mxbackup_replacement'),description:_('mxbackup_replacement_desc'),name:'value',anchor:'100%',height:70,value:record.get('value') === null ? '' : record.get('value')},
                {xtype:'numberfield',fieldLabel:_('mxbackup_priority'),name:'priority',anchor:'100%',value:record.get('priority') || 100}
            ],
            buttons:[
                {text:_('save'),handler:function(){
                    var values=win.fp.getForm().getValues(),jsonPath=String(values.json_path||'').replace(/^\$?\.?/,'');
                    var params={profile_id:grid.profileCombo.getValue(),target_type:jsonPath?'json_path':'column',target:grid.tableCombo.getValue()+'.'+record.get('column')+(jsonPath?'.'+jsonPath:''),rule_action:actionCombo.getValue(),value:values.value,priority:values.priority,active:1};
                    var endpoint='mgr/rule/create';if(record.get('source')==='custom'&&record.get('rule_id')){endpoint='mgr/rule/update';params.id=record.get('rule_id');}
                    MxBackup.request(endpoint,params,function(){win.close();grid.loadTable(grid.tableCombo.getValue());});
                }},
                {text:_('cancel'),handler:function(){win.close();}}
            ]
        });
        win.show();
    },
    truncateTable: function () {
        var table=this.tableCombo.getValue();if(!table)return;
        var existing=this.store.getAt(this.store.findExact('column','*'));
        if(existing){MODx.msg.alert(_('mxbackup_masking'),existing.get('source')==='standard'?_('mxbackup_standard_truncate_exists'):_('mxbackup_custom_truncate_exists'));return;}
        Ext.Msg.confirm(_('mxbackup_truncate_table'),_('mxbackup_confirm_truncate'),function(answer){if(answer!=='yes')return;MxBackup.request('mgr/rule/create',{profile_id:this.profileCombo.getValue(),target_type:'table',target:table,rule_action:'truncate',priority:100,active:1},function(){this.loadTable(table);}.createDelegate(this));},this);
    },
    removeRule: function (record) {
        Ext.Msg.confirm(_('remove'),_('mxbackup_confirm_remove_rule'),function(answer){if(answer!=='yes')return;MxBackup.request('mgr/rule/remove',{profile_id:this.profileCombo.getValue(),id:record.get('rule_id')},function(){this.loadTable(this.tableCombo.getValue());}.createDelegate(this));},this);
    }
});
Ext.reg('mxbackup-grid-masking', MxBackup.grid.Masking);
