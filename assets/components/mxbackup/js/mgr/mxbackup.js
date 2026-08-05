var MxBackup = window.MxBackup || {};
MxBackup.panel = MxBackup.panel || {};
MxBackup.grid = MxBackup.grid || {};
MxBackup.bool = function (value) {
    return value === true || value === 1 || value === '1' || value === 'true';
};
MxBackup.request = function (action, params, callback) {
    MODx.Ajax.request({
        url: MxBackup.config.connectorUrl,
        params: Ext.apply({action: action}, params || {}),
        listeners: {
            success: {fn: function (response) { if (callback) callback(response); }, scope: this},
            failure: {fn: function (response) { MODx.msg.alert(_('error'), response.message || _('mxbackup_error')); }, scope: this}
        }
    });
};
MxBackup.run = function (profile, dryRun) {
    if (!MxBackup.config.canRun) return;
    Ext.Msg.confirm(_('mxbackup_run'), dryRun ? _('mxbackup_confirm_dryrun') : _('mxbackup_confirm_run'), function (answer) {
        if (answer !== 'yes') return;
        MODx.msg.status({title: _('mxbackup_run'), message: _('mxbackup_running'), dontHide: true});
        MxBackup.request('mgr/run/create', {profile: profile, dry_run: dryRun ? 1 : 0}, function (response) {
            MODx.msg.hide();
            MODx.msg.alert(_('mxbackup_run'), response.message || _('mxbackup_finished'));
            var grid = Ext.getCmp('mxbackup-grid-runs');
            if (grid) grid.refresh();
        });
    });
};
