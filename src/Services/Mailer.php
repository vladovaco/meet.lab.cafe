<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use PHPMailer\PHPMailer\PHPMailer;

/** Odosielanie e-mailov cez SMTP alebo PHP mail() (PHPMailer). */
final class Mailer
{
    public static function enabled(): bool
    {
        return (bool) Config::get('mail.enabled');
    }

    /**
     * @param list<array{email:string,name?:string}> $to
     * @param list<array{name:string,content:string,mime?:string}> $attachments
     */
    public static function send(array $to, string $subject, string $html, string $text, array $attachments = []): void
    {
        if (!self::enabled()) {
            throw new \RuntimeException('Odosielanie e-mailov je vypnuté (MAIL_ENABLED=false).');
        }
        $cfg = Config::get('mail');
        $m = new PHPMailer(true);
        $m->CharSet = PHPMailer::CHARSET_UTF8;
        $m->Encoding = PHPMailer::ENCODING_BASE64;
        if (!empty($cfg['host'])) {
            $m->isSMTP();
            $m->Host = (string) $cfg['host'];
            $m->Port = (int) $cfg['port'];
            $m->SMTPAuth = !empty($cfg['user']);
            $m->Username = (string) ($cfg['user'] ?? '');
            $m->Password = (string) ($cfg['pass'] ?? '');
            $m->SMTPSecure = match ((string) $cfg['secure']) {
                'ssl'  => PHPMailer::ENCRYPTION_SMTPS,
                'none', '' => '',
                default => PHPMailer::ENCRYPTION_STARTTLS,
            };
            $m->SMTPAutoTLS = (string) $cfg['secure'] !== 'none';
            $m->Timeout = 30;
        } else {
            $m->isMail();
        }
        $m->setFrom((string) $cfg['from'], (string) $cfg['from_name']);
        foreach ($to as $r) {
            $m->addAddress($r['email'], $r['name'] ?? '');
        }
        $m->Subject = $subject;
        $m->isHTML(true);
        $m->Body = $html;
        $m->AltBody = $text;
        foreach ($attachments as $a) {
            $m->addStringAttachment($a['content'], $a['name'], PHPMailer::ENCODING_BASE64, $a['mime'] ?? 'text/plain');
        }
        $m->send();
    }
}
