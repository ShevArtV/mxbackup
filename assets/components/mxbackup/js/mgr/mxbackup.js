var MxBackup = window.MxBackup || {};
MxBackup.panel = MxBackup.panel || {};
MxBackup.grid = MxBackup.grid || {};
MxBackup.combo = MxBackup.combo || {};
MxBackup.bool = function (value) {
    return value === true || value === 1 || value === '1' || value === 'true';
};
MxBackup.request = function (action, params, callback) {
    MODx.Ajax.request({
        url: MxBackup.config.connectorUrl,
        params: Ext.apply({action: action}, params || {}),
        listeners: {
            success: {fn: function (response) { if (callback) callback(response); }, scope: this},
            failure: {fn: function (response) {
                MODx.msg.hide();
                var message = response.message || '';
                if (!message && response.data && response.data.length && response.data[0].msg) message = response.data[0].msg;
                MODx.msg.alert(_('error'), message || _('mxbackup_error'));
            }, scope: this}
        }
    });
};
MxBackup.escape = function (value) {
    return Ext.util.Format.htmlEncode(String(value === undefined || value === null ? '' : value));
};
MxBackup.formatBytes = function (value) {
    value = parseInt(value || 0, 10);
    if (value < 1024) return value + ' B';
    if (value < 1048576) return (value / 1024).toFixed(1) + ' KB';
    if (value < 1073741824) return (value / 1048576).toFixed(1) + ' MB';
    return (value / 1073741824).toFixed(1) + ' GB';
};
MxBackup.actionLabel = function (action) {
    var labels = {
        mask: _('mxbackup_action_mask'), hide: _('mxbackup_action_hide'),
        hash: _('mxbackup_action_hash'), replace: _('mxbackup_action_replace'),
        truncate: _('mxbackup_action_truncate')
    };
    return labels[action] || _('mxbackup_action_none');
};
MxBackup.reportHtml = function (report) {
    var stats = report && report.stats ? report.stats : {};
    var tables = stats.table_names || [], truncated = stats.truncated_tables || [], escapedTables = [];
    Ext.each(tables, function (value) { escapedTables.push(MxBackup.escape(value)); });
    var html = '<div class="mxbackup-report"><div class="mxbackup-report-cards">';
    html += '<div><strong>' + MxBackup.escape(stats.files || 0) + '</strong><span>' + _('mxbackup_report_files') + '</span></div>';
    html += '<div><strong>' + MxBackup.escape(stats.tables || 0) + '</strong><span>' + _('mxbackup_report_tables') + '</span></div>';
    html += '<div><strong>' + MxBackup.escape(stats.masked_columns || 0) + '</strong><span>' + _('mxbackup_report_masked_columns') + '</span></div>';
    html += '<div><strong>' + MxBackup.escape(truncated.length) + '</strong><span>' + _('mxbackup_report_truncated') + '</span></div></div>';
    if (escapedTables.length) html += '<div class="mxbackup-report-list"><b>' + _('mxbackup_report_table_list') + ':</b> ' + escapedTables.join(', ') + '</div>';
    if (truncated.length) {
        var escapedTruncated = [];
        Ext.each(truncated, function (value) { escapedTruncated.push(MxBackup.escape(value)); });
        html += '<p><b>' + _('mxbackup_report_truncated_list') + ':</b> ' + escapedTruncated.join(', ') + '</p>';
    }
    return html + '</div>';
};
MxBackup.combo.Profile = function (config) {
    config = config || {};
    Ext.applyIf(config, {
        url: MxBackup.config.connectorUrl,
        baseParams: {action: 'mgr/profile/getlist', limit: 0},
        fields: ['id','name','description','mode','active','format','file_include','file_exclude','standard_masking'],
        displayField: 'name', valueField: 'id', hiddenName: config.name || 'profile_id',
        mode: 'remote', triggerAction: 'all', editable: false, forceSelection: true,
        width: 180, pageSize: 0
    });
    MxBackup.combo.Profile.superclass.constructor.call(this, config);
};
Ext.extend(MxBackup.combo.Profile, MODx.combo.ComboBox);
Ext.reg('mxbackup-combo-profile', MxBackup.combo.Profile);

MxBackup.combo.Table = function (config) {
    config = config || {};
    Ext.applyIf(config, {
        url: MxBackup.config.connectorUrl,
        baseParams: {action: 'mgr/database/table/getlist', profile_id: 0, included_only: 1, limit: 0},
        fields: ['name','included'], displayField: 'name', valueField: 'name', hiddenName: config.name || 'table',
        mode: 'remote', triggerAction: 'all', editable: true, forceSelection: true,
        width: 260, pageSize: 0
    });
    MxBackup.combo.Table.superclass.constructor.call(this, config);
};
Ext.extend(MxBackup.combo.Table, MODx.combo.ComboBox);
Ext.reg('mxbackup-combo-table', MxBackup.combo.Table);
MxBackup.validateConfig = function (profile) {
    MxBackup.request('mgr/config/validate', {profile: profile}, function (response) {
        MODx.msg.alert(_('mxbackup_validate_config'), response.message || _('mxbackup_config_valid'));
    });
};
MxBackup.run = function (profile, dryRun) {
    if (!MxBackup.config.canRun) return;
    Ext.Msg.confirm(_('mxbackup_run'), dryRun ? _('mxbackup_confirm_dryrun') : _('mxbackup_confirm_run'), function (answer) {
        if (answer !== 'yes') return;
        MODx.msg.status({title: _('mxbackup_run'), message: _('mxbackup_running'), dontHide: true});
        MxBackup.request('mgr/run/create', {profile: profile, dry_run: dryRun ? 1 : 0}, function (response) {
            MODx.msg.hide();
            var report = response.object && response.object.report ? response.object.report : null;
            MODx.msg.alert(_('mxbackup_run'), report ? MxBackup.reportHtml(report) : (response.message || _('mxbackup_finished')));
            var grid = Ext.getCmp('mxbackup-grid-runs');
            if (grid) grid.refresh();
        });
    });
};
