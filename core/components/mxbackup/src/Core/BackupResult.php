<?php

namespace MxBackup\Core;

final class BackupResult
{
    private $success;
    private $archivePath;
    private $report;

    public function __construct($success, $archivePath, array $report)
    {
        $this->success = (bool)$success;
        $this->archivePath = $archivePath;
        $this->report = $report;
    }

    public function isSuccess() { return $this->success; }
    public function getArchivePath() { return $this->archivePath; }
    public function getReport() { return $this->report; }
}
