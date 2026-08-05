MxBackup.grid.Tables = function (config) {
    config = config || {};
    this.profileCombo = new MxBackup.combo.Profile({id: 'mxbackup-tables-profile'});
    this.modeCombo = new MODx.combo.ComboBox({
        id: 'mxbackup-table-mode', width: 220, mode: 'local', triggerAction: 'all', editable: false,
        store: new Ext.data.ArrayStore({fields:['value','display'],data:[['all_except',_('mxbackup_table_mode_all_except')],['selected',_('mxbackup_table_mode_selected')]]}),
        displayField: 'display', valueField: 'value', value: 'all_except'
    });
    this.searchField = new Ext.form.TextField({width: 180, emptyText: _('search')});
    Ext.apply(config, {
        id: 'mxbackup-grid-tables', url: MxBackup.config.connectorUrl,
        baseParams: {action: 'mgr/database/table/getlist', profile_id: 0},
        fields: ['name','engine','rows','size','included','selection_mode'],
        height: 520, autoHeight: false, paging: true, pageSize: 25, remoteSort: false, autosave: false,
        columns: [
            {header: _('mxbackup_in_archive'), dataIndex: 'included', width: 95, renderer: this.renderIncluded},
            {header: _('mxbackup_table'), dataIndex: 'name', width: 300},
            {header: _('mxbackup_rows'), dataIndex: 'rows', width: 90},
            {header: _('mxbackup_size'), dataIndex: 'size', width: 100, renderer: MxBackup.formatBytes},
            {header: _('mxbackup_engine'), dataIndex: 'engine', width: 100}
        ],
        tbar: [
            '<b>' + _('mxbackup_profile') + ':</b>', this.profileCombo, '-',
            '<b>' + _('mxbackup_selection_mode') + ':</b>', this.modeCombo, '-',
            this.searchField, '->',
            {text: _('mxbackup_include_all'), disabled: !MxBackup.config.canManage, handler: function(){this.setAll(true);}, scope: this},
            {text: _('mxbackup_exclude_all'), disabled: !MxBackup.config.canManage, handler: function(){this.setAll(false);}, scope: this},
            {text: _('save'), cls: 'primary-button', disabled: !MxBackup.config.canManage, handler: this.saveSelection, scope: this}
        ],
        listeners: {cellclick: {fn: this.toggleTable, scope: this}}
    });
    MxBackup.grid.Tables.superclass.constructor.call(this, config);
    this.store.on('load', this.afterTablesLoad, this);
    this.profileCombo.on('select', function (combo, record) { this.loadProfile(record.get('id')); }, this);
    this.profileCombo.store.on('load', this.selectDefaultProfile, this);
    this.searchField.on('keyup', this.filterTables, this, {buffer: 250});
    this.profileCombo.store.load();
};
Ext.extend(MxBackup.grid.Tables, MODx.grid.Grid, {
    renderIncluded: function (value) {
        return MxBackup.bool(value)
            ? '<span class="mxbackup-toggle on">✓ ' + _('mxbackup_included') + '</span>'
            : '<span class="mxbackup-toggle off">— ' + _('mxbackup_excluded') + '</span>';
    },
    selectDefaultProfile: function (store) {
        var index = store.findExact('name', MxBackup.config.defaultProfile || 'prod');
        if (index < 0) index = 0;
        var record = store.getAt(index);
        if (record) { this.profileCombo.setValue(record.get('id')); this.loadProfile(record.get('id')); }
    },
    loadProfile: function (profileId) {
        this.store.baseParams.profile_id = profileId;
        this.store.load({params:{start:0,limit:this.config.pageSize || 25}});
    },
    afterTablesLoad: function (store) {
        var first = store.getAt(0);
        if (first && first.get('selection_mode')) this.modeCombo.setValue(first.get('selection_mode'));
    },
    toggleTable: function (grid, row, column) {
        if (!MxBackup.config.canManage || column !== 0) return;
        var record = this.store.getAt(row);
        var included = !MxBackup.bool(record.get('included'));
        MxBackup.request('mgr/profile/tables/update', {
            profile_id: this.profileCombo.getValue(), operation: 'toggle', table: record.get('name'), included: included ? 1 : 0
        }, function () { record.set('included', included); record.commit(); }.createDelegate(this));
    },
    setAll: function (included) {
        MxBackup.request('mgr/profile/tables/update', {
            profile_id: this.profileCombo.getValue(), operation: 'set_all', included: included ? 1 : 0
        }, function (response) {
            MODx.msg.status({title: _('success'), message: response.message || _('mxbackup_tables_saved')});
            this.loadProfile(this.profileCombo.getValue());
        }.createDelegate(this));
    },
    filterTables: function () {
        this.store.baseParams.query = String(this.searchField.getValue() || '');
        this.store.load({params:{start:0,limit:this.config.pageSize}});
    },
    saveSelection: function () {
        MxBackup.request('mgr/profile/tables/update', {
            profile_id: this.profileCombo.getValue(), operation: 'mode', selection_mode: this.modeCombo.getValue()
        }, function (response) {
            MODx.msg.status({title: _('success'), message: response.message || _('mxbackup_tables_saved')});
            this.loadProfile(this.profileCombo.getValue());
        }.createDelegate(this));
    }
});
Ext.reg('mxbackup-grid-tables', MxBackup.grid.Tables);
