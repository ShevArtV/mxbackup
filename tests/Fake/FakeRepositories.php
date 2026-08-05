<?php

namespace MxBackup\Tests\Fake;

use MxBackup\Core\Contract\MailerInterface;
use MxBackup\Core\Contract\ProfileRepositoryInterface;
use MxBackup\Core\Contract\RunRepositoryInterface;

final class FakeProfiles implements ProfileRepositoryInterface
{
    public function all() { return []; }
    public function find($name) { return null; }
}

final class FakeRuns implements RunRepositoryInterface
{
    public $records = [];
    public function start(array $data) { $this->records[1] = $data; return 1; }
    public function finish($id, array $data) { $this->records[$id] = array_merge($this->records[$id], $data); return true; }
}

final class FakeMailer implements MailerInterface
{
    public $messages = [];
    public function send(array $recipients, $subject, $body, $attachment = null)
    {
        $this->messages[] = compact('recipients', 'subject', 'body', 'attachment');
        return true;
    }
}
