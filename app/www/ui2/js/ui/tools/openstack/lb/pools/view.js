Scalr.regPage('Scalr.ui.tools.openstack.lb.pools.view', function (loadParams, moduleParams) {

    var store = Ext.create('store.store', {
        fields: ['status', 'lb_method', 'protocol', 'description', 'health_monitors', 'subnet_id', 'subnet_cidr', 'tenant_id', 'admin_state_up', 'name', 'members', 'id', 'vip_id'],
        proxy: {
            type: 'scalr.paging',
            url: '/tools/openstack/lb/pools/xList'
        },
        remoteSort: true
    });

    var panel = Ext.create('Ext.grid.Panel', {

        title: Scalr.utils.getPlatformName(loadParams['platform']) + ' &raquo; Load balancers &raquo; Pools',
        itemId: 'lbPoolsGrid',

        scalrOptions: {
            //'reload': false,
            'maximize': 'all'
        },

        store: store,

        stateId: 'grid-tools-openstack-lb-pools-view',
        stateful: true,

        plugins: {
            ptype: 'gridstore'
        },
        tools: [{
            xtype: 'gridcolumnstool'
        }, {
            xtype: 'favoritetool',
            favorite: {
                text: 'LB Pools',
                href: '#/tools/openstack/lb/pools'
            }
        }],

        viewConfig: {
            emptyText: 'No pools found',
            loadingText: 'Loading pools ...'
        },

        columns: [
            { header: "Name", xtype: 'templatecolumn', flex: 1, sortable: true,
                tpl: new Ext.XTemplate(
                    '<a href="#/tools/openstack/lb/pools/info?{[this.getParams()]}&poolId={id}">{name}</a>', {
                        getParams: function() {
                            var platform = 'platform=' + store.proxy.extraParams.platform,
                                cloudLocation = '&cloudLocation=' + store.proxy.extraParams.cloudLocation;
                            return platform + cloudLocation;
                        }
                    })
            },
            { header: "Description", flex: 1, dataIndex: 'description' },
            { header: "Subnet", flex: 1, dataIndex: 'subnet_cidr', sortable: true },
            { header: "Protocol", flex: 0.5, dataIndex: 'protocol', sortable: true },
            { header: "Virtual IP", width: 125, itemId: 'vipId', dataIndex: 'vip_id', sortable: true, align: 'center', xtype: 'templatecolumn',
                tpl: [
                    '<tpl if="vip_id">',
                        '<img src="' + Ext.BLANK_IMAGE_URL + '" class="x-icon-ok" />',
                    '<tpl else>',
                        '<img src="' + Ext.BLANK_IMAGE_URL + '" class="x-icon-minus" />',
                    '</tpl>'
                ]
            },
            { xtype: 'optionscolumn2',
                menu: [{
                    text:'Add Vip',
                    iconCls: 'x-menu-icon-create',
                    menuHandler: function(data) {
                        var platform = 'platform=' + store.proxy.extraParams.platform,
                            cloudLocation = '&cloudLocation=' + store.proxy.extraParams.cloudLocation,
                            poolId = '&poolId=' + data['id'],
                            params = platform + cloudLocation + poolId;

                        Scalr.event.fireEvent('redirect',
                            '#/tools/openstack/lb/pools/addVip?' + params
                        );
                    },
                    getVisibility: function(data) {
                        return !data['vip_id'];
                    }
                }, {
                    text:'Vip info',
                    iconCls: 'x-menu-icon-view',
                    menuHandler: function(data) {
                        var platform = 'platform=' + store.proxy.extraParams.platform,
                            cloudLocation = '&cloudLocation=' + store.proxy.extraParams.cloudLocation,
                            vipId = '&vipId=' + data['vip_id'],
                            params = platform + cloudLocation + vipId;

                        Scalr.event.fireEvent('redirect',
                            '#/tools/openstack/lb/pools/vipInfo?' + params
                        );
                    },
                    getVisibility: function(data) {
                        return data['vip_id'];
                    }
                }, {
                    xtype: 'menuseparator',
                    getVisibility: function(data) {
                        return data['vip_id'];
                    }
                }, {
                    text:'Delete Vip',
                    iconCls: 'x-menu-icon-delete',
                    menuHandler: function(data) {
                        var platform = 'platform=' + store.proxy.extraParams.platform,
                            cloudLocation = '&cloudLocation=' + store.proxy.extraParams.cloudLocation,
                            vipId = '&vipId=' + data['vip_id'],
                            params = platform + cloudLocation + vipId,
                            poolName = data['name'];

                        Scalr.Request({
                            confirmBox: {
                                type: 'delete',
                                msg: 'Delete virtual IP from <span style="font-weight: bold">' + poolName + '</span> ?'
                            },
                            processBox: {
                                type: 'delete'
                            },
                            params: params,
                            url: '/tools/openstack/lb/pools/xRemoveVip/',
                            success: function() {
                                store.load();
                            }
                        });
                    },
                    getVisibility: function(data) {
                        return data['vip_id'];
                    }
                }, {
                    xtype: 'menuseparator'
                }, {
                    text: 'Pool info',
                    iconCls: 'x-menu-icon-view',
                    menuHandler: function(data) {
                        var platform = 'platform=' + store.proxy.extraParams.platform,
                            cloudLocation = '&cloudLocation=' + store.proxy.extraParams.cloudLocation,
                            poolId = '&poolId=' + data['id'],
                            params = platform + cloudLocation + poolId;

                        Scalr.event.fireEvent('redirect',
                            '#/tools/openstack/lb/pools/info?' + params
                        );
                    }
                }, {
                    xtype: 'menuseparator'
                }, {
                    text:'Edit pool',
                    iconCls: 'x-menu-icon-edit',
                    menuHandler: function(data) {
                        var platform = 'platform=' + store.proxy.extraParams.platform,
                            cloudLocation = '&cloudLocation=' + store.proxy.extraParams.cloudLocation,
                            poolId = '&poolId=' + data['id'],
                            params = platform + cloudLocation + poolId;

                        Scalr.event.fireEvent('redirect',
                            '#/tools/openstack/lb/pools/edit?' + params
                        );
                    }
                }]
            }
        ],

        multiSelect: true,
        selModel: {
            selType: 'selectedmodel',
            getVisibility: function(record) {
                return !record.get('vip_id');
            },
            listeners: {
                selectionchange: function(selModel, selectedRecords) {
                    panel.selectedPools = [];
                    Ext.each(selectedRecords, function(record) {
                        var poolName = record.get('name');
                        poolName = '<span style="font-weight: bold">' + poolName + '</span>';
                        panel.selectedPools.push(poolName);
                    });
                    panel.selectedPools = panel.selectedPools.join(', ');
                }
            }
        },

        listeners: {
            selectionchange: function(selModel, selections) {
                var toolbar = this.down('scalrpagingtoolbar');
                toolbar.down('#delete').setDisabled(!selections.length);
            }
        },

        dockedItems: [{
            xtype: 'scalrpagingtoolbar',
            ignoredLoadParams: ['platform'],
            store: store,
            dock: 'top',
            beforeItems: [{
                text: 'Add pool',
                cls: 'x-btn-green-bg',
                handler: function() {
                    var platform = 'platform=' + store.proxy.extraParams.platform,
                        cloudLocation = '&cloudLocation=' + store.proxy.extraParams.cloudLocation,
                        params = platform + cloudLocation;

                    Scalr.event.fireEvent('redirect',
                        '#/tools/openstack/lb/pools/create?' + params
                    );
                }
            }],
            afterItems: [{
                ui: 'paging',
                itemId: 'delete',
                disabled: true,
                iconCls: 'x-tbar-delete',
                tooltip: 'Delete',
                handler: function() {
                    var request = {
                        confirmBox: {
                            type: 'delete',
                            //msg: 'Delete selected pool(s): ' + panel.selectedPools + ' ?'
                            msg: 'Delete selected pool(s): %s ?'
                        },
                        processBox: {
                            type: 'delete'
                        },
                        params: loadParams,
                        url: '/tools/openstack/lb/pools/xRemove/',
                        success: function() {
                            store.load();
                        }
                    };

                    var records = panel.getSelectionModel().getSelection(),
                        pools = [];
                    request.confirmBox.objects = [];

                    for (var i = 0, recordsNumber = records.length; i < recordsNumber; i++) {
                        pools.push(records[i].get('id'));
                        request.confirmBox.objects.push(records[i].get('name'))
                    }

                    request.params.poolId = Ext.encode(pools);
                    Scalr.Request(request);
                }
            }],
            items: [{
                xtype: 'filterfield',
                store: store
            }, ' ', {
                xtype: 'fieldcloudlocation',
                itemId: 'cloudLocation',
                store: {
                    fields: [ 'id', 'name' ],
                    data: moduleParams.locations,
                    proxy: 'object'
                },
                gridStore: store
            }]
        }]
    });
    return panel;
});
