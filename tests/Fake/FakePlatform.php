<?php

namespace MxBackup\Tests\Fake;

use MxBackup\Core\Contract\DatabaseAdapterInterface;
use MxBackup\Core\Contract\PlatformInterface;

final class FakePlatform implements PlatformInterface
{
    private $root;
    private $database;
    public $runs;
    public $mailer;

    public function __construct($root, DatabaseAdapterInterface $database)
    {
        $this->root = $root;
        $this->database = $database;
        $this->runs = new FakeRuns();
        $this->mailer = new FakeMailer();
    }
    public function getOption($key, $default = null) { return $default; }
    public function getSiteRoot() { return $this->root; }
    public function getCorePath() { return $this->root . '/core/'; }
    public function getPlatformVersion() { return '2.8.8-pl'; }
    public function now() { return time(); }
    public function log($level, $message, array $context = []) {}
    public function database() { return $this->database; }
    public function profiles() { return new FakeProfiles(); }
    public function runs() { return $this->runs; }
    public function mailer() { return $this->mailer; }
}
