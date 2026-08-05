<?php

namespace MxBackup\Core\Contract;

interface PlatformInterface
{
    public function getOption($key, $default = null);
    public function getSiteRoot();
    public function getCorePath();
    public function getPlatformVersion();
    public function now();
    public function log($level, $message, array $context = []);
    public function database();
    public function profiles();
    public function runs();
    public function mailer();
}
