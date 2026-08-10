<?php

namespace MxBackup\Core\Contract;

interface RunRepositoryInterface
{
    public function start(array $data);
    public function finish($id, array $data);
    public function requestCancel($id = 0);
    public function isCancellationRequested($id);
}
