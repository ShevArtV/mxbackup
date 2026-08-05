<?php

namespace MxBackup\Platform\Modx2;

use MxBackup\Core\Contract\MailerInterface;

final class Mailer implements MailerInterface
{
    private $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    public function send(array $recipients, $subject, $body, $attachment = null)
    {
        $this->modx->getService('mail', 'mail.modPHPMailer');
        if (!$this->modx->mail) return false;
        $this->modx->mail->set(\modMail::MAIL_BODY, nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')));
        $this->modx->mail->set(\modMail::MAIL_FROM, $this->modx->getOption('emailsender'));
        $this->modx->mail->set(\modMail::MAIL_FROM_NAME, $this->modx->getOption('site_name'));
        $this->modx->mail->set(\modMail::MAIL_SUBJECT, $subject);
        foreach ($recipients as $recipient) {
            $this->modx->mail->address('to', $recipient);
        }
        if ($attachment) {
            $this->modx->mail->attach($attachment);
        }
        $this->modx->mail->setHTML(true);
        $sent = (bool)$this->modx->mail->send();
        $this->modx->mail->reset();
        return $sent;
    }
}
