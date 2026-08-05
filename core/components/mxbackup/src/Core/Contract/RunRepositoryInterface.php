<?php

namespace MxBackup\Core\Contract;

interface RunRepositoryInterface
{
    public function start(array $data);
    public function finish($id, array $data);
}
