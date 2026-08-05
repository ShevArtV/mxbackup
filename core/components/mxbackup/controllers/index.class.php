<?php

class mxBackupIndexManagerController extends modExtraManagerController
{
    private $corePath;
    private $assetsUrl;

    public function initialize()
    {
        $this->corePath = $this->modx->getOption('mxbackup.core_path', null, MODX_CORE_PATH . 'components/mxbackup/');
        $this->assetsUrl = $this->modx->getOption('mxbackup.assets_url', null, MODX_ASSETS_URL . 'components/mxbackup/');
        $this->modx->addPackage('mxbackup', $this->corePath . 'model/');
        require_once $this->corePath . 'autoload.php';
        parent::initialize();
    }

    public function getLanguageTopics() { return ['mxbackup:default']; }
    public function checkPermissions() { return $this->modx->hasPermission('mxbackup_view'); }
    public function getPageTitle() { return $this->modx->lexicon('mxbackup'); }
    public function getTemplateFile() { return $this->corePath . 'templates/home.tpl'; }
    public function getContent(array $scriptProperties = []) { return '<div id="mxbackup-panel-home-div"></div>'; }

    public function loadCustomCssJs()
    {
        $assetsPath = $this->modx->getOption('mxbackup.assets_path', null, MODX_ASSETS_PATH . 'components/mxbackup/');
        $version = static function ($file) use ($assetsPath) {
            return is_file($assetsPath . $file) ? '?v=' . filemtime($assetsPath . $file) : '';
        };
        $this->addCss($this->assetsUrl . 'css/mgr/main.css' . $version('css/mgr/main.css'));
        $this->addJavascript($this->assetsUrl . 'js/mgr/mxbackup.js' . $version('js/mgr/mxbackup.js'));
        $this->addLastJavascript($this->assetsUrl . 'js/mgr/widgets/home.panel.js' . $version('js/mgr/widgets/home.panel.js'));
        $this->addLastJavascript($this->assetsUrl . 'js/mgr/widgets/profile.grid.js' . $version('js/mgr/widgets/profile.grid.js'));
        $this->addLastJavascript($this->assetsUrl . 'js/mgr/widgets/rule.grid.js' . $version('js/mgr/widgets/rule.grid.js'));
        $this->addLastJavascript($this->assetsUrl . 'js/mgr/widgets/run.grid.js' . $version('js/mgr/widgets/run.grid.js'));
        $this->addLastJavascript($this->assetsUrl . 'js/mgr/widgets/settings.panel.js' . $version('js/mgr/widgets/settings.panel.js'));
        $this->addHtml('<script>var MxBackup = window.MxBackup || {}; MxBackup.config = ' . $this->modx->toJSON([
            'connectorUrl' => $this->assetsUrl . 'connector.php',
            'canManage' => $this->modx->hasPermission('mxbackup_manage'),
            'canRun' => $this->modx->hasPermission('mxbackup_run'),
        ]) . '; Ext.onReady(function(){MODx.add({xtype:"mxbackup-panel-home"});});</script>');
    }
}
