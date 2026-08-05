<?php

namespace MxBackup\Core\Report;

final class RunReport
{
    private $startedAt;
    private $completedAt = 0;
    private $status = 'running';
    private $warnings = [];
    private $errors = [];
    private $stats = [];

    public function __construct($startedAt)
    {
        $this->startedAt = (int)$startedAt;
    }

    public function warning($message)
    {
        $this->warnings[] = (string)$message;
    }

    public function error($message)
    {
        $this->errors[] = (string)$message;
        $this->status = 'error';
    }

    public function set($key, $value)
    {
        $this->stats[$key] = $value;
    }

    public function complete($timestamp)
    {
        $this->completedAt = (int)$timestamp;
        if ($this->status !== 'error') {
            $this->status = $this->warnings ? 'warning' : 'success';
        }
    }

    public function toArray()
    {
        return [
            'status' => $this->status,
            'startedon' => $this->startedAt,
            'completedon' => $this->completedAt,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'stats' => $this->stats,
        ];
    }
}
