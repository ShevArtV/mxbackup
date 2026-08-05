<?php

namespace MxBackup\Core\Delivery;

final class DeliveryResult
{
    private $sent;
    private $attached;
    private $message;

    public function __construct($sent, $attached, $message)
    {
        $this->sent = (bool)$sent;
        $this->attached = (bool)$attached;
        $this->message = (string)$message;
    }

    public function isSent() { return $this->sent; }
    public function isAttached() { return $this->attached; }
    public function getMessage() { return $this->message; }

    public function toArray()
    {
        return ['sent' => $this->sent, 'attached' => $this->attached, 'message' => $this->message];
    }
}
