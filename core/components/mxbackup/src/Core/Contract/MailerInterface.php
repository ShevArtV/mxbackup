<?php

namespace MxBackup\Core\Contract;

interface MailerInterface
{
    public function send(array $recipients, $subject, $body, $attachment = null);
}
