MxBackup.grid.Runs = function (config) {
    config = config || {};
    Ext.apply(config, {
        id: 'mxbackup-grid-runs',
        url: MxBackup.config.connectorUrl,
        baseParams: {action: 'mgr/run/getlist'},
        fields: [
            'id', 'profile', 'mode', 'run_type', 'status', 'archive_path', 'archive_name',
            'archive_size', 'archive_checksum', 'email_sent', 'error', 'report_json',
            'warnings_count', 'errors_count', 'startedon', 'completedon', 'duration'
        ],
        paging: true,
        pageSize: 30,
        remoteSort: true,
        sortBy: 'startedon',
        sortDir: 'DESC',
        columns: [
            {header: _('id'), dataIndex: 'id', width: 55},
            {header: _('mxbackup_run_type'), dataIndex: 'run_type', width: 120, renderer: this.renderType},
            {header: _('mxbackup_profile'), dataIndex: 'profile', width: 100},
            {header: _('mxbackup_status'), dataIndex: 'status', width: 120, renderer: this.renderStatus},
            {header: _('mxbackup_archive'), dataIndex: 'archive_name', width: 270, renderer: this.renderArchive},
            {header: _('mxbackup_size'), dataIndex: 'archive_size', width: 95, renderer: this.renderSize},
            {header: _('mxbackup_started'), dataIndex: 'startedon', width: 145, renderer: this.renderDate},
            {header: _('mxbackup_duration'), dataIndex: 'duration', width: 90, renderer: this.renderDuration}
        ],
        tbar: [{
            text: _('mxbackup_refresh'),
            iconCls: 'icon-refresh',
            handler: this.refresh,
            scope: this
        }],
        listeners: {rowDblClick: {fn: this.viewDetails, scope: this}}
    });
    MxBackup.grid.Runs.superclass.constructor.call(this, config);
};

Ext.extend(MxBackup.grid.Runs, MODx.grid.Grid, {
    renderType: function (value) {
        return value === 'dry_run' ? _('mxbackup_run_type_dry') : _('mxbackup_run_type_backup');
    },
    renderStatus: function (value, meta, record) {
        var suffix = '';
        if (record.get('warnings_count')) suffix = ' (' + record.get('warnings_count') + ')';
        if (record.get('errors_count')) suffix = ' (' + record.get('errors_count') + ')';
        return '<span class="mxbackup-status-' + MxBackup.escape(value) + '">' +
            MxBackup.escape(_('mxbackup_status_' + value) || value) + suffix + '</span>';
    },
    renderArchive: function (value, meta, record) {
        if (record.get('run_type') === 'dry_run') return '<span class="mxbackup-badge muted">' + _('mxbackup_no_archive_dry') + '</span>';
        if (!value) return '—';
        return '<span title="' + MxBackup.escape(record.get('archive_path')) + '">' + MxBackup.escape(value) + '</span>';
    },
    renderSize: function (value, meta, record) {
        return record.get('run_type') === 'dry_run' || !parseInt(value || 0, 10)
            ? '—'
            : MxBackup.formatBytes(value);
    },
    renderDate: function (value) {
        return value ? new Date(parseInt(value, 10) * 1000).format('Y-m-d H:i:s') : '—';
    },
    renderDuration: function (value) {
        return value === null || value === undefined ? '—' : MxBackup.escape(value) + ' ' + _('mxbackup_seconds_short');
    },
    getMenu: function () {
        var record = this.menu.record || {};
        var row = this.store.findExact('id', record.id);
        if (row < 0) return [];
        return [{
            text: _('mxbackup_run_details'),
            handler: function () { this.viewDetails(this, row); },
            scope: this
        }];
    },
    viewDetails: function (grid, row) {
        var record = grid.store.getAt(row);
        if (!record) return;
        var report = record.get('report_json') || {}, warnings = report.warnings || [], errors = report.errors || [];
        var list = function (items) {
            if (!items.length) return '<span class="mxbackup-badge success">' + _('mxbackup_none') + '</span>';
            var html = '<ul>';
            Ext.each(items, function (item) { html += '<li>' + MxBackup.escape(item) + '</li>'; });
            return html + '</ul>';
        };
        var html = '<div class="mxbackup-run-details">';
        html += '<p><b>' + _('mxbackup_run_type') + ':</b> ' + MxBackup.escape(this.renderType(record.get('run_type'))) + '</p>';
        html += '<p><b>' + _('mxbackup_profile') + ':</b> ' + MxBackup.escape(record.get('profile')) + '</p>';
        html += '<p><b>' + _('mxbackup_status') + ':</b> ' + MxBackup.escape(_('mxbackup_status_' + record.get('status')) || record.get('status')) + '</p>';
        html += '<p><b>' + _('mxbackup_started') + ':</b> ' + this.renderDate(record.get('startedon')) + '</p>';
        html += '<p><b>' + _('mxbackup_completed') + ':</b> ' + this.renderDate(record.get('completedon')) + '</p>';
        if (record.get('archive_path')) html += '<p><b>' + _('mxbackup_archive') + ':</b> <code>' + MxBackup.escape(record.get('archive_path')) + '</code></p>';
        if (record.get('archive_checksum')) html += '<p><b>SHA-256:</b> <code>' + MxBackup.escape(record.get('archive_checksum')) + '</code></p>';
        html += '<h4>' + _('mxbackup_warnings') + '</h4>' + list(warnings);
        html += '<h4>' + _('mxbackup_errors') + '</h4>' + list(errors);
        html += MxBackup.reportHtml(report) + '</div>';
        var win = new Ext.Window({
            title: _('mxbackup_run_details') + ' #' + record.get('id'),
            width: 850,
            height: 620,
            modal: true,
            layout: 'fit',
            items: [{xtype: 'panel', border: false, autoScroll: true, bodyStyle: 'padding:16px', html: html}],
            buttons: [{text: _('close'), handler: function () { win.close(); }}]
        });
        win.show();
    }
});

Ext.reg('mxbackup-grid-runs', MxBackup.grid.Runs);
