<?php
declare(strict_types=1);

const PASSWORD_HINT_TABLE = 'password_last_digit_hint';

function ensurePasswordHintTable(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $sql = sprintf(
        'CREATE TABLE IF NOT EXISTS %s (
            accountType VARCHAR(16) NOT NULL,
            accountId INT NOT NULL,
            hintHash VARCHAR(255) DEFAULT NULL,
            updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (accountType, accountId)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        PASSWORD_HINT_TABLE
    );

    $pdo->exec($sql);
    $ensured = true;
}

function derivePasswordLastDigit(string $password): ?string
{
    if ($password === '') {
        return null;
    }
    $lastChar = substr($password, -1);
    return ctype_digit($lastChar) ? $lastChar : null;
}

function storePasswordLastDigit(PDO $pdo, string $accountType, int $accountId, string $password): void
{
    ensurePasswordHintTable($pdo);

    $digit = derivePasswordLastDigit($password);
    $hash = $digit !== null ? password_hash($digit, PASSWORD_DEFAULT) : null;

    $sql = sprintf(
        'INSERT INTO %s (accountType, accountId, hintHash)
         VALUES (:type, :id, :hash)
         ON DUPLICATE KEY UPDATE hintHash = VALUES(hintHash), updatedAt = CURRENT_TIMESTAMP',
        PASSWORD_HINT_TABLE
    );
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':type', $accountType);
    $stmt->bindValue(':id', $accountId, PDO::PARAM_INT);
    if ($hash === null) {
        $stmt->bindValue(':hash', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':hash', $hash, PDO::PARAM_STR);
    }
    $stmt->execute();
}

function verifyPasswordLastDigit(PDO $pdo, string $accountType, int $accountId, string $digit): bool
{
    if ($digit === '' || !ctype_digit($digit) || strlen($digit) !== 1) {
        return false;
    }

    ensurePasswordHintTable($pdo);

    $sql = sprintf(
        'SELECT hintHash FROM %s WHERE accountType = :type AND accountId = :id LIMIT 1',
        PASSWORD_HINT_TABLE
    );
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':type', $accountType);
    $stmt->bindValue(':id', $accountId, PDO::PARAM_INT);
    $stmt->execute();

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }

    $hash = $row['hintHash'] ?? null;
    if (!$hash) {
        return false;
    }

    return password_verify($digit, $hash);
}
