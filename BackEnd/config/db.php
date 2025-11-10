<?php
$host = $_ENV['DB_HOST'] ?? 'localhost';
$db   = $_ENV['DB_NAME'] ?? 'sustainable_travel';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$port = (int)($_ENV['DB_PORT'] ?? 3306);
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES => false,
];

try {
  $pdo = new PDO($dsn, $user, $pass, $options);
$configuredTimezone = $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?? '';
$appTimezone = is_string($configuredTimezone) && trim($configuredTimezone) !== ''
  ? trim($configuredTimezone)
  : 'Asia/Kuala_Lumpur';
  try {
    $tz = new DateTimeZone($appTimezone);
    $now = new DateTimeImmutable('now', $tz);
    $offsetSeconds = $tz->getOffset($now);
    $hours = intdiv($offsetSeconds, 3600);
    $minutes = abs(intdiv($offsetSeconds % 3600, 60));
    $formattedOffset = sprintf('%+03d:%02d', $hours, $minutes);
    $pdo->exec(sprintf("SET time_zone = '%s'", $formattedOffset));
  } catch (Throwable $e) {
    // ignore if timezone cannot be set
  }
} catch (PDOException $e) {
  http_response_code(500);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Database connection failed']);
  exit;
}

return $pdo;
