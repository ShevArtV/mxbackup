<?php

namespace MxBackup\Core;

final class RestoreResult
{
    private $success;
    private $report;

    public function __construct($success, array $report)
    {
        $this->success = (bool)$success;
        $this->report = $report;
    }

    public function isSuccess() { return $this->success; }
    public function getReport() { return $this->report; }
}
