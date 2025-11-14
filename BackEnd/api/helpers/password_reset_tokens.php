<?php
declare(strict_types=1);

function ensurePasswordResetTable(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS password_reset_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    accountType VARCHAR(16) NOT NULL,
    accountId INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    requestToken VARCHAR(64) NOT NULL,
    otpHash VARCHAR(255) NOT NULL,
    otpExpiresAt DATETIME NOT NULL,
    otpAttempts INT UNSIGNED NOT NULL DEFAULT 0,
    verifiedAt DATETIME DEFAULT NULL,
    resetTokenHash VARCHAR(255) DEFAULT NULL,
    resetTokenExpiresAt DATETIME DEFAULT NULL,
    completedAt DATETIME DEFAULT NULL,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_request (requestToken),
    INDEX idx_account (accountType, accountId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    $pdo->exec($sql);
    $ensured = true;
}

function cleanupPasswordResetRequests(PDO $pdo): void
{
    ensurePasswordResetTable($pdo);
    $pdo->exec(
        "DELETE FROM password_reset_requests
         WHERE (otpExpiresAt < DATE_SUB(NOW(), INTERVAL 1 HOUR) AND verifiedAt IS NULL)
            OR (resetTokenExpiresAt IS NOT NULL AND resetTokenExpiresAt < DATE_SUB(NOW(), INTERVAL 1 DAY))
            OR (completedAt IS NOT NULL AND completedAt < DATE_SUB(NOW(), INTERVAL 1 DAY))"
    );
}

function generateNumericOtp(int $length = 6): string
{
    $length = max(4, min(8, $length));
    $min = (int)pow(10, $length - 1);
    $max = (int)pow(10, $length) - 1;
    return (string)random_int($min, $max);
}

/**
 * @return array{requestToken:string, expiresAt:string}
 */
function createPasswordResetRequest(
    PDO $pdo,
    string $accountType,
    int $accountId,
    string $email,
    string $otp,
    int $ttlSeconds = 600
): array {
    ensurePasswordResetTable($pdo);

    $cooldownSeconds = 60;
    $cooldownStmt = $pdo->prepare(
        "SELECT createdAt FROM password_reset_requests
         WHERE accountType = :type AND accountId = :id AND completedAt IS NULL
         ORDER BY createdAt DESC LIMIT 1"
    );
    $cooldownStmt->execute([
        ':type' => $accountType,
        ':id' => $accountId,
    ]);
    $recent = $cooldownStmt->fetch(PDO::FETCH_ASSOC);
    if ($recent) {
        $created = new DateTimeImmutable($recent['createdAt']);
        $diff = (new DateTimeImmutable('now'))->getTimestamp() - $created->getTimestamp();
        if ($diff < $cooldownSeconds) {
            $remaining = $cooldownSeconds - $diff;
            throw new RuntimeException(sprintf('Please wait %d seconds before requesting another OTP.', $remaining));
        }
    }

    $requestToken = bin2hex(random_bytes(16));
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
    $expiresAt = (new DateTimeImmutable(sprintf('+%d seconds', $ttlSeconds)))->format('Y-m-d H:i:s');

    $deleteStmt = $pdo->prepare(
        "DELETE FROM password_reset_requests
         WHERE accountType = :type AND accountId = :id AND completedAt IS NULL"
    );
    $deleteStmt->execute([
        ':type' => $accountType,
        ':id' => $accountId,
    ]);

    $insert = $pdo->prepare(
        "INSERT INTO password_reset_requests
         (accountType, accountId, email, requestToken, otpHash, otpExpiresAt)
         VALUES (:type, :id, :email, :token, :hash, :expires)"
    );
    $insert->execute([
        ':type' => $accountType,
        ':id' => $accountId,
        ':email' => $email,
        ':token' => $requestToken,
        ':hash' => $otpHash,
        ':expires' => $expiresAt,
    ]);

    return [
        'requestToken' => $requestToken,
        'expiresAt' => $expiresAt,
    ];
}

/**
 * @return array{resetToken:string, expiresAt:string}
 */
function verifyPasswordResetOtp(
    PDO $pdo,
    string $accountType,
    int $accountId,
    string $requestToken,
    string $otp,
    int $maxAttempts = 5
): array {
    ensurePasswordResetTable($pdo);

    $select = $pdo->prepare(
        "SELECT id, otpHash, otpExpiresAt, otpAttempts
         FROM password_reset_requests
         WHERE accountType = :type
           AND accountId = :id
           AND requestToken = :token
           AND completedAt IS NULL
         LIMIT 1"
    );
    $select->execute([
        ':type' => $accountType,
        ':id' => $accountId,
        ':token' => $requestToken,
    ]);
    $row = $select->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('OTP session not found. Please request a new code.');
    }

    $now = new DateTimeImmutable('now');
    if (new DateTimeImmutable($row['otpExpiresAt']) < $now) {
        throw new RuntimeException('The OTP has expired. Request a new code.');
    }

    $attempts = (int)$row['otpAttempts'];
    if ($attempts >= $maxAttempts) {
        throw new RuntimeException('Too many incorrect attempts. Request a new OTP.');
    }

    if (!password_verify($otp, $row['otpHash'])) {
        $increment = $pdo->prepare(
            "UPDATE password_reset_requests SET otpAttempts = otpAttempts + 1 WHERE id = :id"
        );
        $increment->execute([':id' => $row['id']]);
        throw new RuntimeException('Incorrect OTP. Please check the code and try again.');
    }

    $resetToken = bin2hex(random_bytes(24));
    $resetTokenHash = password_hash($resetToken, PASSWORD_DEFAULT);
    $resetExpires = $now->modify('+15 minutes')->format('Y-m-d H:i:s');

    $update = $pdo->prepare(
        "UPDATE password_reset_requests
         SET verifiedAt = NOW(),
             resetTokenHash = :resetHash,
             resetTokenExpiresAt = :resetExpires,
             otpHash = '',
             otpAttempts = :attempts
         WHERE id = :id"
    );
    $update->execute([
        ':resetHash' => $resetTokenHash,
        ':resetExpires' => $resetExpires,
        ':attempts' => $attempts,
        ':id' => $row['id'],
    ]);

    return [
        'resetToken' => $resetToken,
        'expiresAt' => $resetExpires,
    ];
}

/**
 * @return array|false
 */
function validatePasswordResetTokens(
    PDO $pdo,
    string $accountType,
    int $accountId,
    string $requestToken,
    string $resetToken
) {
    ensurePasswordResetTable($pdo);
    $stmt = $pdo->prepare(
        "SELECT id, resetTokenHash, resetTokenExpiresAt, verifiedAt
         FROM password_reset_requests
         WHERE accountType = :type
           AND accountId = :id
           AND requestToken = :token
           AND completedAt IS NULL
         LIMIT 1"
    );
    $stmt->execute([
        ':type' => $accountType,
        ':id' => $accountId,
        ':token' => $requestToken,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['resetTokenExpiresAt']) {
        return false;
    }

    if (!$row['verifiedAt'] || !$row['resetTokenHash']) {
        return false;
    }

    if (new DateTimeImmutable($row['resetTokenExpiresAt']) < new DateTimeImmutable('now')) {
        return false;
    }

    if (!password_verify($resetToken, $row['resetTokenHash'])) {
        return false;
    }

    return $row;
}

function markPasswordResetCompleted(PDO $pdo, int $rowId): void
{
    $stmt = $pdo->prepare(
        "UPDATE password_reset_requests
         SET completedAt = NOW(),
             resetTokenHash = '',
             resetTokenExpiresAt = NULL
         WHERE id = :id"
    );
    $stmt->execute([':id' => $rowId]);
}
