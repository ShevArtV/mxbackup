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
        if (value === 'restore') return _('mxbackup_run_type_restore');
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
        var menu = [{
            text: _('mxbackup_run_details'),
            handler: function () { this.viewDetails(this, row); },
            scope: this
        }];
        var item = this.store.getAt(row);
        if (MxBackup.config.canRestore && item && item.get('run_type') === 'backup'
            && item.get('archive_path') && (item.get('status') === 'success' || item.get('status') === 'warning')) {
            menu.push({
                text: _('mxbackup_restore'),
                handler: function () { this.restoreArchive(item); },
                scope: this
            });
        }
        return menu;
    },
    restoreArchive: function (record) {
        var password = new Ext.form.TextField({fieldLabel: _('mxbackup_restore_password'), inputType: 'password', anchor: '100%'});
        var scope = new Ext.form.ComboBox({
            fieldLabel: _('mxbackup_restore_scope'), hiddenName: 'scope', mode: 'local', triggerAction: 'all',
            editable: false, forceSelection: true, value: 'all', anchor: '100%',
            store: new Ext.data.ArrayStore({fields: ['id', 'name'], data: [
                ['all', _('mxbackup_restore_scope_all')],
                ['files', _('mxbackup_restore_scope_files')],
                ['database', _('mxbackup_restore_scope_database')]
            ]}),
            valueField: 'id', displayField: 'name'
        });
        var form = new Ext.form.FormPanel({border: false, bodyStyle: 'padding:16px', labelWidth: 145, items: [
            {xtype: 'displayfield', value: '<b>' + MxBackup.escape(record.get('archive_name')) + '</b>'},
            password, scope,
            {xtype: 'displayfield', value: '<div class="mxbackup-warning">' + MxBackup.escape(_('mxbackup_restore_safety_notice')) + '</div>'}
        ]});
        var win = new Ext.Window({
            title: _('mxbackup_restore'), width: 560, height: 260, modal: true, layout: 'fit', items: [form],
            buttons: [{text: _('mxbackup_restore_check'), handler: function () {
                var passwordValue = password.getValue(), scopeValue = scope.getValue();
                MxBackup.request('mgr/restore/preflight', {id: record.get('id'), password: passwordValue}, function (response) {
                    var info = response.object || {};
                    win.close();
                    var summary = _('mxbackup_restore_preflight_ok') + '<br><b>' + _('mxbackup_archive') + ':</b> '
                        + MxBackup.escape(info.archive_name || record.get('archive_name')) + '<br><b>'
                        + _('mxbackup_report_files') + ':</b> ' + MxBackup.escape(info.site_files || 0) + '<br><b>'
                        + _('mxbackup_mode') + ':</b> ' + MxBackup.escape(info.manifest && info.manifest.mode ? info.manifest.mode : '—') + '<br><b>'
                        + _('mxbackup_restore_confirmation') + ':</b> <code>' + MxBackup.escape(info.confirmation || '') + '</code><br><br>'
                        + MxBackup.escape(_('mxbackup_restore_type_token'));
                    if (info.warnings && info.warnings.length) {
                        summary += '<br><br><span class="mxbackup-status-warning">' + MxBackup.escape(info.warnings.join(' ')) + '</span>';
                    }
                    Ext.Msg.prompt(_('mxbackup_restore'), summary, function (answer, value) {
                        if (answer !== 'ok') return;
                        MODx.msg.status({title: _('mxbackup_restore'), message: _('mxbackup_restore_running'), dontHide: true});
                        MxBackup.request('mgr/restore/create', {
                            id: record.get('id'), password: passwordValue, scope: scopeValue, confirmation: value
                        }, function (restoreResponse) {
                            MODx.msg.hide();
                            var report = restoreResponse.object && restoreResponse.object.report ? restoreResponse.object.report : {};
                            var safety = report.stats && report.stats.safety_backup ? report.stats.safety_backup : '';
                            MODx.msg.alert(_('mxbackup_restore'), _('mxbackup_restore_finished')
                                + (safety ? '<br><b>' + _('mxbackup_restore_safety_backup') + ':</b> <code>' + MxBackup.escape(safety) + '</code>' : ''));
                            this.refresh();
                        }.createDelegate(this));
                    }, this, false, '');
                }.createDelegate(this));
            }, scope: this}, {text: _('cancel'), handler: function () { win.close(); }}]
        });
        win.show();
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
