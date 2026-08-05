<?php

namespace MxBackup\Core\Delivery;

use MxBackup\Core\Contract\MailerInterface;

final class MailDelivery
{
    private $mailer;

    public function __construct(MailerInterface $mailer)
    {
        $this->mailer = $mailer;
    }

    public function deliver(array $mailConfig, $archivePath, array $report)
    {
        if (empty($mailConfig['enabled'])) {
            return new DeliveryResult(false, false, 'Email выключен.');
        }
        $recipients = $this->recipients(isset($mailConfig['to']) ? $mailConfig['to'] : '');
        if (!$recipients) {
            return new DeliveryResult(false, false, 'Не заданы получатели.');
        }

        $limit = max(0, (float)(isset($mailConfig['max_attachment_mb']) ? $mailConfig['max_attachment_mb'] : 10)) * 1024 * 1024;
        $size = is_file($archivePath) ? filesize($archivePath) : 0;
        $attachment = $size > 0 && $size <= $limit ? $archivePath : null;
        $subject = 'mxBackup: ' . (isset($report['status']) ? $report['status'] : 'finished');
        $body = "Резервная копия создана.\nПуть: {$archivePath}\nРазмер: {$size} байт.\n";
        if (!$attachment) {
            $body .= "Архив не приложен: превышен лимит attachment или файл недоступен.\n";
        }
        $sent = $this->mailer->send($recipients, $subject, $body, $attachment);
        return new DeliveryResult($sent, (bool)$attachment && $sent, $sent ? 'Письмо отправлено.' : 'Ошибка отправки письма.');
    }

    private function recipients($value)
    {
        $values = is_array($value) ? $value : preg_split('/[,;\s]+/', (string)$value, -1, PREG_SPLIT_NO_EMPTY);
        $result = [];
        foreach ($values as $email) {
            $email = trim((string)$email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result[] = $email;
            }
        }
        return array_values(array_unique($result));
    }
}
