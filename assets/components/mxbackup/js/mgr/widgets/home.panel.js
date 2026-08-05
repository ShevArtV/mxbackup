MxBackup.panel.Home = function (config) {
    config = config || {};
    Ext.apply(config, {
        id: 'mxbackup-panel-home', cls: 'container', renderTo: 'mxbackup-panel-home-div',
        items: [{html: '<h2>' + _('mxbackup') + '</h2><p class="mxbackup-note">' + _('mxbackup_intro') + '</p>', border: false}, {
            xtype: 'panel', cls: 'mxbackup-actions', border: false, layout: 'hbox', items: [
                {xtype: 'button', text: _('mxbackup_prod_backup'), disabled: !MxBackup.config.canRun, handler: function(){MxBackup.run('prod', false);}},
                {xtype: 'button', text: _('mxbackup_dev_backup'), disabled: !MxBackup.config.canRun, handler: function(){MxBackup.run('dev', false);}},
                {xtype: 'button', text: _('mxbackup_dev_dryrun'), disabled: !MxBackup.config.canRun, handler: function(){MxBackup.run('dev', true);}}
            ]
        }, {
            xtype: 'modx-tabs', deferredRender: false, border: true, items: [
                {title: _('mxbackup_profiles'), items: [{xtype: 'mxbackup-grid-profiles'}]},
                {title: _('mxbackup_rules'), items: [{xtype: 'mxbackup-grid-rules'}]},
                {title: _('mxbackup_settings'), items: [{xtype: 'mxbackup-panel-settings'}]},
                {title: _('mxbackup_history'), items: [{xtype: 'mxbackup-grid-runs'}]}
            ]
        }]
    });
    MxBackup.panel.Home.superclass.constructor.call(this, config);
};
Ext.extend(MxBackup.panel.Home, MODx.Panel);
Ext.reg('mxbackup-panel-home', MxBackup.panel.Home);
