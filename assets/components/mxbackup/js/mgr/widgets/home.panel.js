MxBackup.panel.Home = function (config) {
    config = config || {};
    var runProfile = new MxBackup.combo.Profile({id: 'mxbackup-run-profile'});
    Ext.apply(config, {
        id: 'mxbackup-panel-home', cls: 'container', renderTo: 'mxbackup-panel-home-div',
        items: [
            {xtype: 'panel', cls: 'mxbackup-hero', border: false, html: '<h2>' + _('mxbackup') + '</h2><p>' + _('mxbackup_intro') + '</p>'},
            {
                xtype: 'panel', cls: 'mxbackup-actions', border: false, layout: 'hbox', layoutConfig: {align:'middle'},
                items: [
                    {xtype: 'displayfield', value: '<b>' + _('mxbackup_run_profile') + '</b>', width: 90},
                    runProfile,
                    {xtype: 'button', cls: 'primary-button', text: _('mxbackup_create_backup'), disabled: !MxBackup.config.canRun, handler: function () { var record = runProfile.store.getAt(runProfile.store.findExact('id', runProfile.getValue())); if (record) MxBackup.run(record.get('name'), false); }},
                    {xtype: 'button', text: _('mxbackup_dryrun'), disabled: !MxBackup.config.canRun, handler: function () { var record = runProfile.store.getAt(runProfile.store.findExact('id', runProfile.getValue())); if (record) MxBackup.run(record.get('name'), true); }}
                ],
                listeners: {afterrender: {fn: function () {
                    runProfile.store.on('load', function (store) {
                        var index = store.findExact('name', MxBackup.config.defaultProfile || 'prod');
                        if (index < 0) index = 0;
                        if (store.getAt(index)) runProfile.setValue(store.getAt(index).get('id'));
                    });
                    runProfile.store.load();
                }}}
            },
            {
                xtype: 'modx-tabs', cls: 'mxbackup-tabs', deferredRender: false, border: true,
                items: [
                    {title: _('mxbackup_profiles'), items: [{xtype: 'mxbackup-grid-profiles'}]},
                    {title: _('mxbackup_tables'), items: [{xtype: 'mxbackup-grid-tables'}]},
                    {title: _('mxbackup_masking'), items: [{xtype: 'mxbackup-grid-masking'}]},
                    {title: _('mxbackup_settings'), items: [{xtype: 'mxbackup-panel-settings'}]},
                    {title: _('mxbackup_history'), items: [{xtype: 'mxbackup-grid-runs'}]}
                ]
            }
        ]
    });
    MxBackup.panel.Home.superclass.constructor.call(this, config);
};
Ext.extend(MxBackup.panel.Home, MODx.Panel);
Ext.reg('mxbackup-panel-home', MxBackup.panel.Home);
