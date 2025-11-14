<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers/mailer.php';
require_once __DIR__ . '/../helpers/password_reset_tokens.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$accountType = strtolower((string)($payload['accountType'] ?? ''));
$email = strtolower(trim((string)($payload['email'] ?? '')));

if (!in_array($accountType, ['traveler', 'operator', 'admin'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported account type']);
    exit;
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Provide a valid email address']);
    exit;
}

try {
    /** @var PDO $pdo */
    $pdo = require __DIR__ . '/../../config/db.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

cleanupPasswordResetRequests($pdo);

switch ($accountType) {
    case 'traveler':
        $table = 'Traveler';
        $idField = 'travelerID';
        $nameExpression = 'COALESCE(fullName, username, CONCAT("Traveler #", travelerID))';
        $accountLabel = 'traveler account';
        break;
    case 'operator':
        $table = 'TourismOperator';
        $idField = 'operatorID';
        $nameExpression = 'COALESCE(fullName, businessType, username, CONCAT("Business partner #", operatorID))';
        $accountLabel = 'business partner workspace';
        break;
    case 'admin':
        $table = 'Administrator';
        $idField = 'adminID';
        $nameExpression = 'COALESCE(fullName, username, CONCAT("Administrator #", adminID))';
        $accountLabel = 'administrator console';
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported account type']);
        exit;
}

$stmt = $pdo->prepare(
    sprintf('SELECT %s AS id, %s AS displayName FROM %s WHERE email = :email LIMIT 1', $idField, $nameExpression, $table)
);
$stmt->execute([':email' => $email]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    http_response_code(404);
    echo json_encode(['error' => 'Account not found']);
    exit;
}

$accountId = (int)$account['id'];
$displayName = trim((string)($account['displayName'] ?? '')) ?: 'there';
$otp = generateNumericOtp(6);

try {
    $request = createPasswordResetRequest($pdo, $accountType, $accountId, $email, $otp);
} catch (RuntimeException $e) {
    http_response_code(429);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$expiresAt = new DateTimeImmutable($request['expiresAt']);
$minutes = max(1, (int)floor(($expiresAt->getTimestamp() - time()) / 60));
$minutesLabel = $minutes === 1 ? 'minute' : 'minutes';
$brandName = 'Malaysia Sustainable Travel';
$otpDisplay = implode(' ', str_split($otp));
$greetingName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
$logoPath = __DIR__ . '/../../public_assets/branding/email-logo.png';
$logoCid = '';
$embeddedImages = [];
if (is_file($logoPath)) {
    try {
        $logoCid = 'mst-logo-' . bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $logoCid = 'mst-logo';
    }
    $embeddedImages[] = [
        'path' => $logoPath,
        'cid' => $logoCid,
        'name' => $brandName . ' logo',
        'mime' => 'image/png',
    ];
}
$logoMarkup = $logoCid !== ''
    ? sprintf(
        '<img src="cid:%s" alt="%s logo" style="height:48px;width:auto;display:block;" />',
        $logoCid,
        htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8')
    )
    : sprintf(
        '<div style="width:64px;height:64px;border-radius:18px;background:#0b3b26;color:#ffffff;font-weight:700;font-size:18px;display:flex;align-items:center;justify-content:center;">%s</div>',
        htmlspecialchars(substr($brandName, 0, 3), ENT_QUOTES, 'UTF-8')
    );
$appTimezoneName = trim((string)($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?? 'Asia/Kuala_Lumpur'));
try {
    $appTimezone = new DateTimeZone($appTimezoneName);
} catch (Throwable $e) {
    $appTimezone = new DateTimeZone('Asia/Kuala_Lumpur');
    $appTimezoneName = 'Asia/Kuala_Lumpur';
}
$expiresLocal = $expiresAt->setTimezone($appTimezone);
$timezoneLabel = $appTimezoneName === 'Asia/Kuala_Lumpur' ? 'Malaysia Time (MYT)' : $appTimezone->getName();
$expiresClock = sprintf('%s %s', $expiresLocal->format('g:i A'), $timezoneLabel);
$body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Password reset code</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f9f5;font-family:'Segoe UI', 'Helvetica Neue', Arial, sans-serif;color:#1a2f23;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f4f9f5;">
    <tr>
      <td align="center" style="padding:28px 16px;">
        <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" style="background:#ffffff;border-radius:28px;overflow:hidden;box-shadow:0 18px 40px rgba(11,59,38,0.08);">
          <tr>
            <td style="padding:32px 36px 18px;background:linear-gradient(135deg,#f1fbf6,#ffffff);border-bottom:1px solid #e1efe7;">
              <table role="presentation" width="100%">
                <tr>
                  <td style="width:72px;" valign="middle">
                    {$logoMarkup}
                  </td>
                  <td valign="middle" style="text-align:right;">
                    <div style="font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:#5c7a68;">{$brandName}</div>
                    <div style="font-size:21px;font-weight:600;color:#0b3b26;">Secure password reset</div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 36px 10px;font-size:16px;color:#2c4436;line-height:1.6;">
              <p style="margin:0 0 12px;">Hello {$greetingName},</p>
              <p style="margin:0;color:#4c6757;">
                Use the verification code below within the next {$minutes} {$minutesLabel} to confirm your identity and reset the password for your {$accountLabel}.
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 36px 30px;">
              <div style="background:#f4fbf7;border-radius:22px;padding:28px;border:1px dashed #bcd8c8;text-align:center;">
                <div style="font-size:13px;text-transform:uppercase;letter-spacing:0.35em;color:#6a8574;margin-bottom:12px;">One-time security code</div>
                <div style="font-size:44px;font-weight:700;color:#0b3b26;letter-spacing:12px;">{$otpDisplay}</div>
                <div style="margin-top:12px;font-size:13px;color:#4f6d5c;">Expires in {$minutes} {$minutesLabel} (around {$expiresClock})</div>
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:0 36px 26px;font-size:14px;color:#4e6656;line-height:1.6;">
              <p style="margin:0 0 8px;">If you didn’t request this, someone may have entered your email by mistake — you can safely ignore this message.</p>
              <p style="margin:0;">Need help? Reply to this email or visit our support centre and we’ll guide you through the process.</p>
            </td>
          </tr>
          <tr>
            <td style="padding:0 36px 32px;color:#5f7469;font-size:13px;line-height:1.6;">
              <p style="margin:0 0 4px;">Stay curious, stay responsible.</p>
              <p style="margin:0;font-weight:600;">The {$brandName} digital team</p>
            </td>
          </tr>
          <tr>
            <td style="padding:16px 24px 32px;background:#f7fbf8;font-size:11px;color:#7e9388;text-align:center;line-height:1.5;">
              You received this email because a password reset was requested for your {$accountLabel}. If this wasn’t you, no further action is required.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
$altBody = sprintf(
    "Hello %s,\nYour %s password reset code is: %s. It expires in %d %s.",
    $displayName,
    $brandName,
    $otp,
    $minutes,
    $minutesLabel
);

try {
    sendMail([
        'to' => $email,
        'subject' => sprintf('%s password reset code for %s', $brandName, $displayName),
        'body' => $body,
        'alt' => $altBody,
        'embedded' => $embeddedImages,
    ]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

echo json_encode([
    'ok' => true,
    'requestToken' => $request['requestToken'],
    'expiresAt' => $request['expiresAt'],
    'message' => 'OTP sent to your email address.',
]);
