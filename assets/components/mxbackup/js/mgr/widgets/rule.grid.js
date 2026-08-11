MxBackup.grid.Masking = function (config) {
    config = config || {};
    this.profileCombo = new MxBackup.combo.Profile({id: 'mxbackup-masking-profile'});
    this.tableCombo = new MxBackup.combo.Table({id: 'mxbackup-masking-table'});
    Ext.apply(config, {
        id: 'mxbackup-grid-masking', url: MxBackup.config.connectorUrl,
        baseParams: {action: 'mgr/database/column/getlist', profile_id: 0, table: ''},
        fields: ['column','type','nullable','action','source','rule_id','target_type','target','json_path','value','when','priority'],
        height: 520, autoHeight: false, paging: false, remoteSort: false, autosave: false,
        columns: [
            {header: _('mxbackup_column'), dataIndex: 'column', width: 220, renderer: this.renderColumn},
            {header: _('mxbackup_sql_type'), dataIndex: 'type', width: 180},
            {header: _('mxbackup_masking_action'), dataIndex: 'action', width: 170, renderer: this.renderAction},
            {header: _('mxbackup_rule_source'), dataIndex: 'source', width: 150, renderer: this.renderSource},
            {header: _('mxbackup_when'), dataIndex: 'when', width: 220, renderer: this.renderWhen},
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
    renderWhen: function (value) {
        if (!value) return '<span class="mxbackup-badge muted">—</span>';
        if (typeof value === 'string') {
            try { value = Ext.decode(value); } catch (e) { return '<span class="mxbackup-badge warning">!</span>'; }
        }
        var parts = [];
        Ext.iterate(value, function (column, patterns) {
            patterns = Ext.isArray(patterns) ? patterns : [patterns];
            parts.push('<b>' + MxBackup.escape(column) + '</b>: ' + MxBackup.escape(patterns.join(', ')));
        });
        return parts.length ? parts.join('<br>') : '<span class="mxbackup-badge muted">—</span>';
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
        var replacementField = new Ext.form.TextArea({
            fieldLabel:_('mxbackup_replacement'),description:_('mxbackup_replacement_desc'),name:'value',anchor:'100%',height:70,
            value:record.get('value') === null ? '' : record.get('value'),hidden:(record.get('action') || 'mask') !== 'replace'
        });
        var columnData = [];
        this.store.each(function (candidate) {
            if (candidate.get('column') !== '*') columnData.push([candidate.get('column'), candidate.get('column')]);
        });
        // Условие хранится как `{колонка: [маска, маска]}`, а редактируется построчно:
        // одна строка таблицы — одна пара «колонка + маска». Повторы колонки не
        // запрещены, они и означают «ИЛИ» — при сохранении строки схлопываются обратно.
        var existingWhen = record.get('when') || {}, whenRows = [];
        if (typeof existingWhen === 'string') { try { existingWhen = Ext.decode(existingWhen); } catch (e) { existingWhen = {}; } }
        Ext.iterate(existingWhen, function (column, patterns) {
            Ext.each(Ext.isArray(patterns) ? patterns : [patterns], function (pattern) { whenRows.push([column, pattern]); });
        });
        var whenStore = new Ext.data.ArrayStore({fields:['column','pattern'], data:whenRows});
        // Ошибку показываем на самой ячейке: общий алерт не говорил, какая строка кривая.
        var whenRenderer = function (field, placeholder) {
            return function (value, meta, row) {
                if (row.data['invalid_' + field]) meta.css = 'mxbackup-when-invalid';
                value = String(value === null || value === undefined ? '' : value);
                return value === '' ? '<span class="mxbackup-when-placeholder">' + MxBackup.escape(placeholder) + '</span>' : MxBackup.escape(value);
            };
        };
        var addButton = new Ext.Button({text:_('mxbackup_add_condition'),iconCls:'icon icon-plus',handler:function(){
            var row = new whenStore.recordType({column:'',pattern:''});
            whenStore.add(row);
            var index = whenStore.indexOf(row);
            whenGrid.getSelectionModel().selectRow(index);
            whenGrid.startEditing(index, 0);
        }});
        var removeButton = new Ext.Button({text:_('remove'),iconCls:'icon icon-times',disabled:true,handler:function(){
            var selected = whenGrid.getSelectionModel().getSelected();
            if (selected) whenStore.remove(selected);
        }});
        var whenGrid = new Ext.grid.EditorGridPanel({
            store:whenStore, clicksToEdit:1, height:150, hideLabel:true, anchor:'100%', cls:'mxbackup-when-grid',
            autoExpandColumn:'mxbackup-when-pattern', stripeRows:false,
            sm:new Ext.grid.RowSelectionModel({singleSelect:true}),
            viewConfig:{deferEmptyText:false, emptyText:'<div class="mxbackup-when-empty">' + _('mxbackup_when_empty') + '</div>'},
            columns:[
                {header:_('mxbackup_when_column'), dataIndex:'column', width:210, sortable:false,
                 renderer:whenRenderer('column', _('mxbackup_when_column_empty')),
                 editor:new MODx.combo.ComboBox({mode:'local',triggerAction:'all',editable:false,forceSelection:true,
                    store:new Ext.data.ArrayStore({fields:['value','display'],data:columnData}),displayField:'display',valueField:'value'})},
                {id:'mxbackup-when-pattern', header:_('mxbackup_when_pattern'), dataIndex:'pattern', sortable:false,
                 renderer:whenRenderer('pattern', _('mxbackup_when_example')),
                 editor:new Ext.form.TextField({emptyText:_('mxbackup_when_example'),selectOnFocus:true})}
            ],
            tbar:[addButton, '-', removeButton]
        });
        whenGrid.getSelectionModel().on('selectionchange', function (sm) { removeButton.setDisabled(!sm.getSelected()); });
        whenGrid.on('afteredit', function (e) { e.record.data['invalid_' + e.field] = false; e.record.commit(); });
        var conditionBox = new Ext.form.FieldSet({
            title:_('mxbackup_when_title'), autoHeight:true, anchor:'100%', defaults:{anchor:'100%'},
            items:[
                {xtype:'displayfield',hideLabel:true,cls:'mxbackup-when-desc',value:_('mxbackup_when_desc')},
                whenGrid,
                {xtype:'displayfield',hideLabel:true,cls:'mxbackup-when-hint',value:_('mxbackup_when_hint')}
            ]
        });
        var collectWhen = function () {
            var when = {}, invalid = false, blank = [];
            whenStore.each(function (row) {
                var column = String(row.get('column') || '').replace(/^\s+|\s+$/g, '');
                var pattern = String(row.get('pattern') || '').replace(/^\s+|\s+$/g, '');
                row.data.invalid_column = false;
                row.data.invalid_pattern = false;
                if (!column && !pattern) { blank.push(row); return; }
                if (!column) { row.data.invalid_column = true; invalid = true; }
                if (!pattern || pattern === '!') { row.data.invalid_pattern = true; invalid = true; }
                if (row.data.invalid_column || row.data.invalid_pattern) return;
                if (!Object.prototype.hasOwnProperty.call(when, column)) when[column] = [];
                if (when[column].indexOf(pattern) < 0) when[column].push(pattern);
            });
            Ext.each(blank, function (row) { whenStore.remove(row); });
            if (whenGrid.rendered) whenGrid.getView().refresh();
            return invalid ? null : when;
        };
        var win = new MODx.Window({
            title: record.get('source') === 'custom' ? _('mxbackup_edit_rule') : _('mxbackup_add_rule'), width: 760, autoHeight:true, labelWidth:160,
            fields:[
                {xtype:'displayfield',fieldLabel:_('mxbackup_table'),value:MxBackup.escape(this.tableCombo.getValue())},
                {xtype:'displayfield',fieldLabel:_('mxbackup_column'),value:MxBackup.escape(record.get('column'))},
                actionCombo,
                {xtype:'textfield',fieldLabel:_('mxbackup_json_path'),description:_('mxbackup_json_path_desc'),name:'json_path',anchor:'100%',value:record.get('json_path') || ''},
                replacementField,
                conditionBox,
                {xtype:'numberfield',fieldLabel:_('mxbackup_priority'),name:'priority',anchor:'100%',value:record.get('priority') || 100}
            ],
            buttons:[
                {text:_('save'),handler:function(){
                    var values=win.fp.getForm().getValues(),jsonPath=String(values.json_path||'').replace(/^\$?\.?/,''),when=collectWhen();
                    if(when===null){MODx.msg.alert(_('mxbackup_error'),_('mxbackup_invalid_when'));return;}
                    var params={profile_id:grid.profileCombo.getValue(),target_type:jsonPath?'json_path':'column',target:grid.tableCombo.getValue()+'.'+record.get('column')+(jsonPath?'.'+jsonPath:''),rule_action:actionCombo.getValue(),value:values.value,when:Ext.encode(when),priority:values.priority,active:1};
                    var endpoint='mgr/rule/create';if(record.get('source')==='custom'&&record.get('rule_id')){endpoint='mgr/rule/update';params.id=record.get('rule_id');}
                    MxBackup.request(endpoint,params,function(){win.close();grid.loadTable(grid.tableCombo.getValue());});
                }},
                {text:_('cancel'),handler:function(){win.close();}}
            ]
        });
        actionCombo.on('select',function(combo,selected){
            if(selected.get('value')==='replace'){win.showField(replacementField);}else{win.hideField(replacementField);}
            win.doLayout();win.syncSize();win.center();
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
