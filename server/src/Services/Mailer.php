<?php

namespace Chap\Services;

use Chap\Models\Setting;

/**
 * Simple Mailer wrapper.
 *
 * Uses PHPMailer when SMTP is configured.
 */
class Mailer
{
    public static function isConfigured(): bool
    {
        $from = trim((string)Setting::get('mail.from_address', ''));
        $host = trim((string)Setting::get('mail.host', ''));
        return $from !== '' && $host !== '';
    }

    /**
     * Send an email.
     *
     * @throws \RuntimeException
     */
    public static function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): void
    {
        if (!self::isConfigured()) {
            throw new \RuntimeException('Email is not configured');
        }

        // Lazy-load PHPMailer classes (installed via composer).
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            throw new \RuntimeException('PHPMailer is not installed');
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        $fromName = (string)Setting::get('mail.from_name', 'Chap');
        $fromAddress = (string)Setting::get('mail.from_address', '');

        $host = (string)Setting::get('mail.host', '');
        $port = (int)Setting::get('mail.port', 587);
        $username = (string)Setting::get('mail.username', '');
        $password = (string)Setting::get('mail.password', '');
        $encryption = (string)Setting::get('mail.encryption', 'tls'); // tls|ssl|none
        $auth = (string)Setting::get('mail.auth', '1');

        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = ($auth === '1' || strtolower($auth) === 'true');

        if ($mail->SMTPAuth) {
            $mail->Username = $username;
            $mail->Password = $password;
        }

        if ($encryption === 'none' || $encryption === '') {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        } elseif ($encryption === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody ?? strip_tags($htmlBody);

        $mail->send();
    }
}
