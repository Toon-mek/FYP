<?php
declare(strict_types=1);

/**
 * Records a successful login event and marks the session as active.
 *
 * @return int|null The inserted log identifier or null when the action could not be completed.
 */
function get_login_table_meta(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME, EXTRA
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $stmt->execute([':table' => $table]);

    $columns = [];
    $autoIncrement = false;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columnName = $row['COLUMN_NAME'];
        $columns[$columnName] = true;
        if (stripos((string) $row['EXTRA'], 'auto_increment') !== false) {
            $autoIncrement = true;
        }
    }

    $cache[$table] = [
        'columns' => $columns,
        'has_logout_timestamp' => isset($columns['logoutTimestamp']),
        'has_is_active' => isset($columns['isActive']),
        'auto_increment' => $autoIncrement,
    ];

    return $cache[$table];
}

function record_login_event(
    PDO $pdo,
    string $accountType,
    int $accountId,
    string $status = 'Success',
    ?string $ipAddress = null,
    ?string $deviceInfo = null
): ?int {
    $map = [
        'admin' => ['table' => 'AdminLoginLog', 'column' => 'adminID'],
        'operator' => ['table' => 'OperatorLoginLog', 'column' => 'operatorID'],
        'traveler' => ['table' => 'TravelerLoginLog', 'column' => 'travelerID'],
    ];

    $normalisedType = strtolower($accountType);
    if ($accountId <= 0 || !isset($map[$normalisedType])) {
        return null;
    }

    $ipAddress = $ipAddress !== null ? substr($ipAddress, 0, 45) : null;
    $deviceInfo = $deviceInfo !== null ? substr($deviceInfo, 0, 255) : null;
    $status = substr($status !== '' ? $status : 'Success', 0, 20);

    $table = $map[$normalisedType]['table'];
    $column = $map[$normalisedType]['column'];

    try {
        $meta = get_login_table_meta($pdo, $table);
        if (empty($meta['columns'])) {
            return null;
        }
        $columns = [$column, 'loginTimestamp', 'loginStatus', 'ipAddress', 'deviceInfo'];
        $valueFragments = [':accountId', 'NOW()', ':status', ':ipAddress', ':deviceInfo'];
        $parameters = [
            ':accountId' => $accountId,
            ':status' => $status,
            ':ipAddress' => $ipAddress,
            ':deviceInfo' => $deviceInfo,
        ];

        if ($meta['has_is_active']) {
            $columns[] = 'isActive';
            $valueFragments[] = ':isActive';
            $parameters[':isActive'] = 1;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $valueFragments)
        );

        $stmt = $pdo->prepare(
            $sql
        );

        $stmt->execute($parameters);

        $lastInsertId = $pdo->lastInsertId();
        if ($lastInsertId && $lastInsertId !== '0') {
            return (int) $lastInsertId;
        }

        if ($meta['auto_increment']) {
            return (int) $pdo->lastInsertId();
        }

        $logIdStmt = $pdo->prepare(
            sprintf(
                'SELECT logID FROM %s WHERE %s = :accountId ORDER BY loginTimestamp DESC LIMIT 1',
                $table,
                $column
            )
        );
        $logIdStmt->execute([':accountId' => $accountId]);
        $logId = $logIdStmt->fetchColumn();

        return $logId !== false ? (int) $logId : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Marks the most recent active login entry as logged out.
 *
 * @return int|null The affected log identifier or null if none was updated.
 */
function close_active_login(PDO $pdo, string $accountType, int $accountId, ?int $logId = null): ?int
{
    $map = [
        'admin' => ['table' => 'AdminLoginLog', 'column' => 'adminID'],
        'operator' => ['table' => 'OperatorLoginLog', 'column' => 'operatorID'],
        'traveler' => ['table' => 'TravelerLoginLog', 'column' => 'travelerID'],
    ];

    $normalisedType = strtolower($accountType);
    if ($accountId <= 0 || !isset($map[$normalisedType])) {
        return null;
    }

    $table = $map[$normalisedType]['table'];
    $column = $map[$normalisedType]['column'];

    try {
        $meta = get_login_table_meta($pdo, $table);
        if (empty($meta['columns']) || !$meta['has_logout_timestamp']) {
            return null;
        }

        if ($logId === null) {
            $select = $pdo->prepare(
                sprintf(
                    'SELECT logID FROM %s WHERE %s = :accountId AND logoutTimestamp IS NULL
                     ORDER BY loginTimestamp DESC LIMIT 1',
                    $table,
                    $column
                )
            );
            $select->execute([':accountId' => $accountId]);
            $logId = $select->fetchColumn();
            if ($logId === false) {
                return null;
            }
            $logId = (int) $logId;
        }

        $setClause = 'logoutTimestamp = NOW()';
        if ($meta['has_is_active']) {
            $setClause .= ', isActive = 0';
        }
        $update = $pdo->prepare(
            sprintf('UPDATE %s SET %s WHERE logID = :logId', $table, $setClause)
        );
        $update->execute([':logId' => $logId]);

        return $update->rowCount() > 0 ? $logId : null;
    } catch (Throwable $e) {
        return null;
    }
}
