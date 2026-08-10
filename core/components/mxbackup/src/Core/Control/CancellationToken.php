<?php

namespace MxBackup\Core\Control;

use MxBackup\Core\Contract\RunRepositoryInterface;

final class CancellationToken
{
    private $runs;
    private $runId;
    private $lastCheck = 0.0;
    private $interval;
    private $markerPath;

    public function __construct(RunRepositoryInterface $runs, $runId, $intervalSeconds = 0.5, $markerPath = null)
    {
        $this->runs = $runs;
        $this->runId = (int) $runId;
        $this->interval = max(0.0, (float) $intervalSeconds);
        $this->markerPath = $markerPath ? (string) $markerPath : null;
    }

    public function checkpoint($force = false)
    {
        $now = microtime(true);
        if (!$force && $now - $this->lastCheck < $this->interval) {
            return;
        }
        $this->lastCheck = $now;
        if (($this->markerPath && is_file($this->markerPath))
            || (!$this->markerPath && $this->runs->isCancellationRequested($this->runId))) {
            throw new BackupCancelledException();
        }
    }
}
