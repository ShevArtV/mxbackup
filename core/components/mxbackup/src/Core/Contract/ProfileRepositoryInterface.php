<?php

namespace MxBackup\Core\Contract;

interface ProfileRepositoryInterface
{
    public function all();
    public function find($name);
}
