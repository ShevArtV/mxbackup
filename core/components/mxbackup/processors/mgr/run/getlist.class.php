<?php

class mxBackupRunGetListProcessor extends modObjectGetListProcessor
{
    public $classKey = 'mxBackupRun';
    public $languageTopics = ['mxbackup:default'];
    public $defaultSortField = 'startedon';
    public $defaultSortDirection = 'DESC';
    public $objectType = 'mxbackup_run';

    public function checkPermissions()
    {
        return $this->modx->hasPermission('mxbackup_view');
    }

    public function prepareRow(xPDOObject $object)
    {
        $row = $object->toArray();
        $report = $object->get('report_json');
        if (!is_array($report)) $report = json_decode((string) $report, true);
        if (!is_array($report)) $report = [];
        $stats = isset($report['stats']) && is_array($report['stats']) ? $report['stats'] : [];
        $row['report_json'] = $report;
        $row['run_type'] = isset($stats['operation']) && $stats['operation'] === 'restore'
            ? 'restore'
            : (!empty($stats['dry_run']) ? 'dry_run' : 'backup');
        $row['archive_name'] = !empty($row['archive_path']) ? basename((string) $row['archive_path']) : '';
        $row['warnings_count'] = isset($report['warnings']) && is_array($report['warnings']) ? count($report['warnings']) : 0;
        $row['errors_count'] = isset($report['errors']) && is_array($report['errors']) ? count($report['errors']) : 0;
        $row['duration'] = !empty($row['completedon']) && !empty($row['startedon'])
            ? max(0, (int) $row['completedon'] - (int) $row['startedon'])
            : null;
        return $row;
    }
}

return 'mxBackupRunGetListProcessor';
