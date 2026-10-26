<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/security.php';
requireRole('admin');

$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
$toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $to);

if (!$fromDate || !$toDate || $fromDate->format('Y-m-d') !== $from || $toDate->format('Y-m-d') !== $to
    || $toDate < $fromDate || $fromDate->diff($toDate)->days > 365) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Choose a valid date range of up to 366 days.']);
    exit;
}

try {
    $connection = getDatabaseConnection();
    $range = static function (string $column): string {
        return "{$column} >= :range_from AND {$column} < DATE_ADD(:range_to, INTERVAL 1 DAY)";
    };
    $parameters = [':range_from' => $from, ':range_to' => $to];

    $lockerStatus = $connection->query(
        'SELECT status, COUNT(*) AS total FROM lockers GROUP BY status ORDER BY status'
    )->fetchAll();
    $reservationStatus = $connection->prepare(
        'SELECT status, COUNT(*) AS total FROM reservations
         WHERE ' . $range('requested_at') . ' GROUP BY status ORDER BY status'
    );
    $reservationStatus->execute($parameters);
    $reservationStatus = $reservationStatus->fetchAll();

    $accessSummary = $connection->prepare(
        'SELECT COUNT(*) AS total, COALESCE(SUM(was_successful = 1), 0) AS successful,
                COALESCE(SUM(was_successful = 0), 0) AS denied
         FROM access_logs WHERE ' . $range('occurred_at')
    );
    $accessSummary->execute($parameters);
    $accessSummary = $accessSummary->fetch();

    $maintenanceSummary = $connection->prepare(
        'SELECT COUNT(*) AS total,
                COALESCE(SUM(status IN (\'open\', \'investigating\')), 0) AS unresolved,
                COALESCE(SUM(status IN (\'resolved\', \'closed\')), 0) AS resolved
         FROM maintenance_reports WHERE ' . $range('reported_at')
    );
    $maintenanceSummary->execute($parameters);
    $maintenanceSummary = $maintenanceSummary->fetch();

    $paymentSummary = $connection->prepare(
        'SELECT COUNT(*) AS paid_transactions, COALESCE(SUM(amount_pesos), 0) AS total_pesos
         FROM reservation_payments WHERE status = \'paid\' AND ' . $range('paid_at')
    );
    $paymentSummary->execute($parameters);
    $paymentSummary = $paymentSummary->fetch();

    $loginSummary = $connection->prepare(
        'SELECT COUNT(*) AS total, COALESCE(SUM(was_successful = 1), 0) AS successful,
                COALESCE(SUM(was_successful = 0), 0) AS failed
         FROM login_activity WHERE ' . $range('attempted_at')
    );
    $loginSummary->execute($parameters);
    $loginSummary = $loginSummary->fetch();

    $topLockers = $connection->prepare(
        'SELECT lockers.locker_number, locker_locations.building, locker_locations.floor,
                COUNT(*) AS attempts, COALESCE(SUM(access_logs.was_successful = 1), 0) AS successful
         FROM access_logs
         INNER JOIN lockers ON lockers.id = access_logs.locker_id
         INNER JOIN locker_locations ON locker_locations.id = lockers.location_id
         WHERE ' . $range('access_logs.occurred_at') . '
         GROUP BY lockers.id, lockers.locker_number, locker_locations.building, locker_locations.floor
         ORDER BY attempts DESC, lockers.locker_number
         LIMIT 10'
    );
    $topLockers->execute($parameters);

    $auditLogs = $connection->prepare(
        'SELECT id, admin_id, action, target_type, target_id, details, ip_address, occurred_at
         FROM admin_audit_logs WHERE ' . $range('occurred_at') . '
         ORDER BY occurred_at DESC, id DESC LIMIT 250'
    );
    $auditLogs->execute($parameters);

    $counts = [
        'students' => (int) $connection->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'lockers' => (int) $connection->query('SELECT COUNT(*) FROM lockers')->fetchColumn(),
        'active_assignments' => (int) $connection->query("SELECT COUNT(*) FROM locker_assignments WHERE status = 'active'")->fetchColumn(),
        'reservations' => array_sum(array_map(static fn (array $row): int => (int) $row['total'], $reservationStatus)),
        'access_attempts' => (int) $accessSummary['total'],
        'maintenance_reports' => (int) $maintenanceSummary['total'],
        'paid_transactions' => (int) $paymentSummary['paid_transactions'],
        'revenue_pesos' => (int) $paymentSummary['total_pesos'],
    ];

    echo json_encode([
        'success' => true,
        'from' => $from,
        'to' => $to,
        'counts' => $counts,
        'locker_status' => $lockerStatus,
        'reservation_status' => $reservationStatus,
        'access' => $accessSummary,
        'maintenance' => $maintenanceSummary,
        'payments' => $paymentSummary,
        'logins' => $loginSummary,
        'top_lockers' => $topLockers->fetchAll(),
        'audit_logs' => $auditLogs->fetchAll(),
    ]);
} catch (PDOException $exception) {
    error_log('Admin report query failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load reports. Apply the reservations, payment, and admin audit database migrations, then try again.',
    ]);
}
