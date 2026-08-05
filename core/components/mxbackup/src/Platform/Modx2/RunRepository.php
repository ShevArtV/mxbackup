<?php

namespace MxBackup\Platform\Modx2;

use MxBackup\Core\Contract\RunRepositoryInterface;

final class RunRepository implements RunRepositoryInterface
{
    private $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    public function start(array $data)
    {
        $run = $this->modx->newObject('mxBackupRun');
        $run->fromArray($data, '', true, true);
        return $run->save() ? (int)$run->get('id') : 0;
    }

    public function finish($id, array $data)
    {
        if (!$id || !($run = $this->modx->getObject('mxBackupRun', (int)$id))) {
            return false;
        }
        $run->fromArray($data, '', true, true);
        return (bool)$run->save();
    }
}
