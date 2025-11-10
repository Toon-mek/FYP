<?php
declare(strict_types=1);

function ensureListingRemovalHistoryTable(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS ListingRemovalHistory (
    removalID INT AUTO_INCREMENT PRIMARY KEY,
    listingID INT NOT NULL,
    operatorID INT NOT NULL,
    businessName VARCHAR(191) NOT NULL,
    categoryName VARCHAR(120) DEFAULT NULL,
    location VARCHAR(191) DEFAULT NULL,
    priceRange VARCHAR(50) DEFAULT NULL,
    status VARCHAR(20) DEFAULT NULL,
    visibilityState VARCHAR(20) DEFAULT NULL,
    removalReason TEXT DEFAULT NULL,
    removedBy INT DEFAULT NULL,
    removedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    snapshot LONGTEXT DEFAULT NULL,
    imagesSnapshot LONGTEXT DEFAULT NULL,
    INDEX idx_history_operator (operatorID),
    INDEX idx_history_listing (listingID),
    INDEX idx_history_removed_at (removedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL;

    $pdo->exec($sql);
    $ensured = true;
}

function ensureHistoryImagesColumn(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    try {
        $pdo->query('SELECT imagesSnapshot FROM ListingRemovalHistory LIMIT 1');
    } catch (Throwable) {
        $pdo->exec('ALTER TABLE ListingRemovalHistory ADD COLUMN imagesSnapshot LONGTEXT DEFAULT NULL');
    }
    $ensured = true;
}

function archiveListingRemoval(PDO $pdo, array $listingRow, ?int $removedBy, string $reason, array $images = []): void
{
    ensureListingRemovalHistoryTable($pdo);
    ensureHistoryImagesColumn($pdo);
    $snapshot = null;
    try {
        $snapshot = json_encode($listingRow, JSON_UNESCAPED_UNICODE);
    } catch (Throwable) {
        $snapshot = null;
    }

    $imagesSnapshot = null;
    try {
        $imagesSnapshot = json_encode($images, JSON_UNESCAPED_UNICODE);
    } catch (Throwable) {
        $imagesSnapshot = null;
    }

    $insert = $pdo->prepare(
        'INSERT INTO ListingRemovalHistory
            (listingID, operatorID, businessName, categoryName, location, priceRange, status, visibilityState, removalReason, removedBy, snapshot, imagesSnapshot)
         VALUES
            (:listingId, :operatorId, :businessName, :categoryName, :location, :priceRange, :status, :visibilityState, :reason, :removedBy, :snapshot, :imagesSnapshot)'
    );

    $insert->execute([
        ':listingId' => (int) ($listingRow['listingID'] ?? 0),
        ':operatorId' => (int) ($listingRow['operatorID'] ?? 0),
        ':businessName' => (string) ($listingRow['businessName'] ?? 'Listing'),
        ':categoryName' => $listingRow['categoryName'] ?? null,
        ':location' => $listingRow['location'] ?? null,
        ':priceRange' => $listingRow['priceRange'] ?? null,
        ':status' => $listingRow['status'] ?? 'Removed',
        ':visibilityState' => $listingRow['visibilityState'] ?? null,
        ':reason' => $reason,
        ':removedBy' => $removedBy ?: null,
        ':snapshot' => $snapshot,
        ':imagesSnapshot' => $imagesSnapshot,
    ]);
}
