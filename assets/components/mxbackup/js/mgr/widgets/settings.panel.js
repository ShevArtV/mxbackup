MxBackup.panel.Settings = function (config) {
    config = config || {};

    Ext.apply(config, {
        id: 'mxbackup-panel-settings',
        border: false,
        layout: 'form',
        labelWidth: 220,
        bodyStyle: 'padding: 16px',
        autoHeight: true,
        items: [
            {
                xtype: 'fieldset',
                title: _('area_mxbackup_general'),
                layout: 'form',
                labelWidth: 220,
                anchor: '100%',
                defaults: {anchor: '100%', msgTarget: 'under'},
                items: [
                    {
                        xtype: 'textfield',
                        fieldLabel: _('setting_mxbackup.storage_path'),
                        description: _('setting_mxbackup.storage_path_desc'),
                        name: 'storage_path'
                    },
                    {
                        xtype: 'textfield',
                        fieldLabel: _('setting_mxbackup.config_path'),
                        description: _('setting_mxbackup.config_path_desc'),
                        name: 'config_path'
                    },
                    {
                        xtype: 'mxbackup-combo-profile',
                        fieldLabel: _('setting_mxbackup.default_profile'),
                        description: _('setting_mxbackup.default_profile_desc'),
                        name: 'default_profile',
                        hiddenName: 'default_profile',
                        valueField: 'name'
                    },
                    {
                        xtype: 'modx-combo',
                        fieldLabel: _('setting_mxbackup.archive_format'),
                        description: _('setting_mxbackup.archive_format_desc'),
                        name: 'archive_format',
                        hiddenName: 'archive_format',
                        store: new Ext.data.ArrayStore({
                            fields: ['value', 'display'],
                            data: [['tar.gz', 'tar.gz'], ['zip', 'ZIP']]
                        }),
                        displayField: 'display',
                        valueField: 'value',
                        mode: 'local',
                        triggerAction: 'all',
                        editable: false,
                        forceSelection: true
                    }
                ]
            },
            {
                xtype: 'fieldset',
                title: _('area_mxbackup_mail'),
                layout: 'form',
                labelWidth: 220,
                anchor: '100%',
                defaults: {anchor: '100%', msgTarget: 'under'},
                items: [
                    {
                        xtype: 'checkbox',
                        fieldLabel: _('setting_mxbackup.mail_enabled'),
                        description: _('setting_mxbackup.mail_enabled_desc'),
                        name: 'mail_enabled',
                        inputValue: 1
                    },
                    {
                        xtype: 'textfield',
                        fieldLabel: _('setting_mxbackup.mail_to'),
                        description: _('setting_mxbackup.mail_to_desc'),
                        name: 'mail_to'
                    },
                    {
                        xtype: 'numberfield',
                        fieldLabel: _('setting_mxbackup.mail_max_attachment_mb'),
                        description: _('setting_mxbackup.mail_max_attachment_mb_desc'),
                        name: 'mail_max_attachment_mb',
                        allowDecimals: false,
                        allowNegative: false,
                        minValue: 0
                    }
                ]
            },
            {
                xtype: 'fieldset',
                title: _('area_mxbackup_retention'),
                layout: 'form',
                labelWidth: 220,
                anchor: '100%',
                defaults: {anchor: '100%', msgTarget: 'under'},
                items: [
                    {
                        xtype: 'numberfield',
                        fieldLabel: _('setting_mxbackup.retention_days'),
                        description: _('setting_mxbackup.retention_days_desc'),
                        name: 'retention_days',
                        allowDecimals: false,
                        allowNegative: false,
                        minValue: 0
                    },
                    {
                        xtype: 'numberfield',
                        fieldLabel: _('setting_mxbackup.retention_count'),
                        description: _('setting_mxbackup.retention_count_desc'),
                        name: 'retention_count',
                        allowDecimals: false,
                        allowNegative: false,
                        minValue: 0
                    },
                    {
                        xtype: 'numberfield',
                        fieldLabel: _('setting_mxbackup.lock_ttl_minutes'),
                        description: _('setting_mxbackup.lock_ttl_minutes_desc'),
                        name: 'lock_ttl_minutes',
                        allowDecimals: false,
                        allowNegative: false,
                        minValue: 0
                    }
                ]
            }
        ],
        buttons: MxBackup.config.canManage ? [{
            text: _('save'),
            handler: this.saveSettings,
            scope: this
        }] : [],
        listeners: {afterrender: {fn: this.loadSettings, scope: this}}
    });

    MxBackup.panel.Settings.superclass.constructor.call(this, config);
};

Ext.extend(MxBackup.panel.Settings, MODx.FormPanel, {
    loadSettings: function () {
        var panel = this;

        MxBackup.request('mgr/config/get', {}, function (response) {
            var values = response.object || {};
            panel.getForm().setValues(values);
            panel.getForm().findField('mail_enabled').setValue(
                MxBackup.bool(values.mail_enabled)
            );
        });
    },

    saveSettings: function () {
        var panel = this;
        var values = panel.getForm().getValues();
        values.mail_enabled = panel.getForm()
            .findField('mail_enabled')
            .getValue() ? 1 : 0;

        MxBackup.request('mgr/config/update', values, function () {
            MODx.msg.status({title: _('success'), message: _('mxbackup_settings_saved')});
            panel.loadSettings();
        });
    }
});

Ext.reg('mxbackup-panel-settings', MxBackup.panel.Settings);
