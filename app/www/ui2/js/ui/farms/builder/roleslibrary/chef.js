Scalr.regPage('Scalr.ui.farms.builder.addrole.chef', function () {
    return {
        xtype: 'container',
        itemId: 'chef',
        isExtraSettings: true,
        hidden: true,

        isVisibleForRole: function(record) {
            //we are going to show chef settings only for shared roles under Chef group
            return this.up('form').currentRole['data']['name'] === 'chef';
        },

        onSettingsUpdate: function(record, name, value) {
        },

        setRole: function(record) {
            var field = this.down('chefsettings'),
                defaultSettings = {'chef.bootstrap': 1};
            this.currentRole = record;
            field.limits = this.up('#farmbuilder').getLimits('general', 'general.chef');
            if (record.get('origin') !== 'SHARED') {
                record.loadRoleChefSettings(function(data, status){
                    if (status) {
                        field.setReadOnly(data.roleChefSettings);
                        field.roleChefSettings = data.roleChefSettings;
                        field.farmRoleChefSettings = data.farmRoleChefSettings;
                        field.setValue(data.roleChefSettings ? data.chefSettings : defaultSettings);
                    }
                });
            } else {
                field.setReadOnly(false);
                field.setValue(defaultSettings);
            }
            field.clearInvalid();
        },

        isValid: function() {
            return this.down('chefsettings').isValid(true);
        },

        getSettings: function() {
            return this.down('chefsettings').getValue();
        },

        items: [{
            xtype: 'chefsettings'
        }]
    };
});
