MxBackup.grid.Runs=function(config){config=config||{};Ext.apply(config,{
    id:'mxbackup-grid-runs',url:MxBackup.config.connectorUrl,baseParams:{action:'mgr/run/getlist'},
    fields:['id','profile','mode','status','archive_path','archive_size','archive_checksum','email_sent','error','startedon','completedon'],paging:true,pageSize:30,remoteSort:true,sortBy:'startedon',sortDir:'DESC',
    columns:[
        {header:_('id'),dataIndex:'id',width:50},{header:_('mxbackup_profile'),dataIndex:'profile',width:100},
        {header:_('mxbackup_status'),dataIndex:'status',width:90,renderer:this.renderStatus},
        {header:_('mxbackup_archive'),dataIndex:'archive_path',width:300},
        {header:_('mxbackup_size'),dataIndex:'archive_size',width:90,renderer:this.renderSize},
        {header:_('mxbackup_started'),dataIndex:'startedon',width:140,renderer:this.renderDate}
    ],tbar:[{text:_('refresh'),handler:this.refresh,scope:this}]
});MxBackup.grid.Runs.superclass.constructor.call(this,config);};
Ext.extend(MxBackup.grid.Runs,MODx.grid.Grid,{
    renderStatus:function(v){return '<span class="mxbackup-status-'+Ext.util.Format.htmlEncode(v)+'">'+Ext.util.Format.htmlEncode(v)+'</span>';},
    renderSize:function(v){var n=parseInt(v||0,10);return n?Math.round(n/1024/1024*100)/100+' MB':'—';},
    renderDate:function(v){return v?new Date(parseInt(v,10)*1000).format('Y-m-d H:i:s'):'—';}
});Ext.reg('mxbackup-grid-runs',MxBackup.grid.Runs);
