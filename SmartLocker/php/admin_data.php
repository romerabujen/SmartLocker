<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/reservation_service.php';
requireRole('admin');

$connection = getDatabaseConnection();
reconcileExpiredReservations($connection);

$counts = [
    'users' => (int) $connection->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'lockers' => (int) $connection->query('SELECT COUNT(*) FROM lockers')->fetchColumn(),
    'available_lockers' => (int) $connection->query("SELECT COUNT(*) FROM lockers WHERE status = 'available'")->fetchColumn(),
    'maintenance_lockers' => (int) $connection->query("SELECT COUNT(*) FROM lockers WHERE status = 'maintenance'")->fetchColumn(),
    'pending_reservations' => (int) $connection->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn(),
    'open_reports' => (int) $connection->query("SELECT COUNT(*) FROM maintenance_reports WHERE status IN ('open', 'investigating')")->fetchColumn(),
];

$lockers = $connection->query(
    'SELECT lockers.id, lockers.locker_number, lockers.status, lockers.size,
            locker_locations.building, locker_locations.floor, locker_locations.area,
            locker_devices.status AS device_status
     FROM lockers
     INNER JOIN locker_locations ON locker_locations.id = lockers.location_id
     LEFT JOIN locker_devices ON locker_devices.locker_id = lockers.id
    ORDER BY locker_locations.building, locker_locations.floor, lockers.locker_number'
)->fetchAll();

$assignedLockers = $connection->query(
        "SELECT locker_assignments.id, locker_assignments.locker_id, locker_assignments.assigned_at,
            locker_assignments.expires_at, locker_assignments.status, lockers.locker_number,
            locker_assignments.notes, lockers.status AS locker_status,
            users.first_name, users.last_name, users.student_id,
            locker_locations.building, locker_locations.floor, locker_locations.area
     FROM locker_assignments
     INNER JOIN lockers ON lockers.id = locker_assignments.locker_id
     INNER JOIN users ON users.id = locker_assignments.user_id
     INNER JOIN locker_locations ON locker_locations.id = lockers.location_id
     WHERE locker_assignments.status = 'active'
     ORDER BY locker_assignments.assigned_at DESC"
)->fetchAll();

$reservations = $connection->query(
        "SELECT reservations.id, reservations.requested_at, reservations.approved_at,
            reservations.starts_at, reservations.ends_at, reservations.duration, reservations.status,
            reservations.purpose, lockers.locker_number, users.first_name, users.last_name, users.student_id
     FROM reservations
     INNER JOIN lockers ON lockers.id = reservations.locker_id
     INNER JOIN users ON users.id = reservations.user_id
    ORDER BY reservations.requested_at DESC
    LIMIT 200"
)->fetchAll();

$reports = $connection->query(
    "SELECT maintenance_reports.id, maintenance_reports.issue_type, maintenance_reports.priority,
            maintenance_reports.description, maintenance_reports.status, maintenance_reports.reported_at,
            lockers.locker_number, users.first_name, users.last_name
     FROM maintenance_reports
     INNER JOIN lockers ON lockers.id = maintenance_reports.locker_id
     LEFT JOIN users ON users.id = maintenance_reports.reported_by
     WHERE maintenance_reports.status IN ('open', 'investigating')
     ORDER BY FIELD(maintenance_reports.priority, 'urgent', 'high', 'normal', 'low'), maintenance_reports.reported_at ASC
     LIMIT 100"
)->fetchAll();

$accessLogs = $connection->query(
    'SELECT access_logs.event_type, access_logs.access_method, access_logs.was_successful,
            access_logs.occurred_at, access_logs.details, lockers.locker_number,
            users.student_id
     FROM access_logs
     INNER JOIN lockers ON lockers.id = access_logs.locker_id
     LEFT JOIN users ON users.id = access_logs.user_id
     ORDER BY access_logs.occurred_at DESC
     LIMIT 25'
)->fetchAll();

$recentUsers = $connection->query(
    'SELECT first_name, last_name, student_id, email, created_at
     FROM users ORDER BY created_at DESC LIMIT 10'
)->fetchAll();

echo json_encode([
    'success' => true,
    'counts' => $counts,
    'lockers' => $lockers,
    'assigned_lockers' => $assignedLockers,
    'reservations' => $reservations,
    'reports' => $reports,
    'access_logs' => $accessLogs,
    'recent_users' => $recentUsers,
]);
