<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../helpers/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$emailInput = is_array($payload) ? ($payload['email'] ?? '') : '';
$nameInput = is_array($payload) ? ($payload['name'] ?? '') : '';

$email = filter_var(trim((string) $emailInput), FILTER_VALIDATE_EMAIL);
$name = trim((string) $nameInput);

if (!$email) {
    http_response_code(422);
    echo json_encode(['error' => 'Please provide a valid email address.']);
    exit;
}

$displayName = $name !== '' ? $name : 'Eco Explorer';
$subject = 'Welcome to Malaysia Sustainable Travel';

$heroTitle = 'Your mindful adventure starts here';
$intro = 'Thanks for joining Malaysia Sustainable Travel. Each month we send two emails: one with fresh eco-stays and another with grassroots volunteer stories.';
$featureList = [
    '🌿 New community-led homestays and river clean-ups across Malaysia.',
    '🗺️ Sample itineraries from the Trip Planner AI, ready to copy into your dashboard.',
    '💚 Practical tips on keeping your footprint light while uplift­ing local artisans.',
];
$ctaText = 'Login now';
$ctaUrl = 'http://localhost:5173/login';

$body = <<<HTML
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <title>{$subject}</title>
    <style>
      body { background:#f4fbf7; margin:0; font-family: 'Segoe UI', Helvetica, Arial, sans-serif; color:#0b2c1d;}
      .email-wrapper { max-width:640px; margin:0 auto; padding:32px 24px; }
      .card { background:#ffffff; border-radius:24px; padding:32px; box-shadow:0 12px 35px rgba(33, 73, 57, 0.15); }
      h1 { margin:0 0 12px; font-size:28px; }
      p { line-height:1.6; margin:0 0 16px; color:#355143; }
      ul { padding-left:18px; margin:0 0 24px; color:#1f3c2c; }
      .cta { display:inline-block; background:#f6c445; color:#0e2b19; text-decoration:none; padding:12px 24px; border-radius:999px; font-weight:600; }
      .footer { margin-top:24px; font-size:12px; color:#6c8575; text-align:center; }
    </style>
  </head>
  <body>
    <div class="email-wrapper">
      <div class="card">
        <p>Hi {$displayName},</p>
        <h1>{$heroTitle}</h1>
        <p>{$intro}</p>
        <ul>
HTML;

foreach ($featureList as $item) {
    $itemText = htmlspecialchars($item, ENT_QUOTES, 'UTF-8');
    $body .= "<li>{$itemText}</li>";
}

$safeCta = htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8');
$safeCtaText = htmlspecialchars($ctaText, ENT_QUOTES, 'UTF-8');

$body .= <<<HTML
        </ul>
        <p>Save the dashboard link below so you can plan, journal, and revisit new releases any time.</p>
        <p style="text-align:center;">
          <a class="cta" href="{$safeCta}" target="_blank" rel="noopener noreferrer">{$safeCtaText}</a>
        </p>
        <p>See you in the mangroves,<br/>Malaysia Sustainable Travel Team</p>
      </div>
      <p class="footer">You received this email because you subscribed on Malaysia Sustainable Travel. Unsubscribe anytime inside your profile settings.</p>
    </div>
  </body>
</html>
HTML;

try {
    sendMail([
        'to' => $email,
        'subject' => $subject,
        'body' => $body,
        'alt' => strip_tags("{$heroTitle}\n{$intro}\n" . implode("\n", $featureList) . "\nDashboard: {$ctaUrl}"),
    ]);

    echo json_encode(['success' => true, 'message' => 'Email sent successfully.']);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()]);
}
