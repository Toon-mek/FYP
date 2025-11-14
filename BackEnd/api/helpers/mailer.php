<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/../../vendor/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../../vendor/PHPMailer/SMTP.php';
require_once __DIR__ . '/../../vendor/PHPMailer/Exception.php';
require_once __DIR__ . '/../../bootstrap/env.php';

/**
 * Returns the SMTP configuration derived from environment variables.
 */
function resolveMailerConfig(): array
{
    $projectRootEnv = dirname(__DIR__, 3) . '/.env';
    $backendEnv = dirname(__DIR__, 2) . '/.env';
    loadEnvFromFile($projectRootEnv);
    loadEnvFromFile($backendEnv);

    $host = getenv('MAILER_HOST') ?: ($_ENV['MAILER_HOST'] ?? '');
    $username = getenv('MAILER_USERNAME') ?: ($_ENV['MAILER_USERNAME'] ?? '');
    $password = getenv('MAILER_PASSWORD') ?: ($_ENV['MAILER_PASSWORD'] ?? '');
    $port = getenv('MAILER_PORT') ?: ($_ENV['MAILER_PORT'] ?? '');
    $from = getenv('MAILER_FROM_ADDRESS') ?: ($_ENV['MAILER_FROM_ADDRESS'] ?? '');
    $fromName = getenv('MAILER_FROM_NAME') ?: ($_ENV['MAILER_FROM_NAME'] ?? 'Malaysia Sustainable Travel');
    $encryption = getenv('MAILER_ENCRYPTION') ?: ($_ENV['MAILER_ENCRYPTION'] ?? 'tls');

    return [
        'host' => $host ?: 'smtp.gmail.com',
        'username' => $username,
        'password' => $password,
        'port' => (int)($port ?: 587),
        'from' => $from ?: $username,
        'from_name' => $fromName,
        'encryption' => $encryption ?: 'tls',
        'reply_to' => getenv('MAILER_REPLY_TO') ?: ($_ENV['MAILER_REPLY_TO'] ?? ''),
    ];
}

/**
 * Sends an email using PHPMailer + Gmail SMTP.
 *
 * @param array{
 *  to:string,
 *  subject:string,
 *  body:string,
 *  alt?:string,
 *  reply_to?:string,
 *  embedded?:array<int, array{path:string,cid:string,name?:string,mime?:string}>
 * } $mail
 */
function sendMail(array $mail): void
{
    $config = resolveMailerConfig();

    if (!$config['username'] || !$config['password']) {
        throw new RuntimeException('Email credentials are not configured.');
    }

    $mailer = new PHPMailer(true);

    try {
        $mailer->isSMTP();
        $mailer->Host = $config['host'];
        $mailer->SMTPAuth = true;
        $mailer->Username = $config['username'];
        $mailer->Password = $config['password'];
        $encryption = strtolower((string)$config['encryption']);
        if ($encryption === 'ssl') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'none' || $encryption === '') {
            $mailer->SMTPSecure = false;
            $mailer->SMTPAutoTLS = false;
        } else {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mailer->Port = $config['port'] ?: 587;

        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom($config['from'], $config['from_name']);
        $mailer->addAddress($mail['to']);

        $replyTo = $mail['reply_to'] ?? $config['reply_to'] ?? '';
        if (is_string($replyTo) && trim($replyTo) !== '') {
            $mailer->addReplyTo($replyTo);
        }

        if (!empty($mail['embedded']) && is_array($mail['embedded'])) {
            foreach ($mail['embedded'] as $image) {
                $path = $image['path'] ?? '';
                $cid = $image['cid'] ?? '';
                if (!is_string($path) || !is_string($cid) || $path === '' || $cid === '') {
                    continue;
                }
                if (!is_file($path)) {
                    continue;
                }
                $name = $image['name'] ?? basename($path);
                $mime = $image['mime'] ?? (mime_content_type($path) ?: 'application/octet-stream');
                $mailer->addEmbeddedImage($path, $cid, $name, PHPMailer::ENCODING_BASE64, $mime);
            }
        }

        $mailer->isHTML(true);
        $mailer->Subject = $mail['subject'];
        $mailer->Body = $mail['body'];
        $mailer->AltBody = isset($mail['alt']) && is_string($mail['alt']) && $mail['alt'] !== ''
            ? $mail['alt']
            : strip_tags($mail['body']);

        $mailer->send();
    } catch (MailException $e) {
        throw new RuntimeException('Unable to send email: ' . $e->getMessage(), (int)$e->getCode(), $e);
    }
}
