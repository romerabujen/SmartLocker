<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/reservation_service.php';

$user = requireRole('student');
$connection = getDatabaseConnection();

try {
    reconcileExpiredReservations($connection);

    $lockers = $connection->query(
        "SELECT l.id, l.locker_number, l.status, l.size, l.description,
                ll.building, ll.floor, ll.area,
                CASE
                    WHEN EXISTS (SELECT 1 FROM reservations p WHERE p.locker_id = l.id AND p.status = 'pending') THEN 'pending'
                    WHEN EXISTS (SELECT 1 FROM reservations a WHERE a.locker_id = l.id AND a.status IN ('approved', 'active')) THEN 'reserved'
                    ELSE l.status
                END AS current_status
         FROM lockers l
         INNER JOIN locker_locations ll ON ll.id = l.location_id
         ORDER BY ll.building, ll.floor, l.locker_number"
    )->fetchAll();

    $statement = $connection->prepare(
        "SELECT r.id, r.locker_id, r.requested_at, r.approved_at, r.starts_at, r.ends_at,
                r.duration, r.status, l.locker_number,
                ll.building, ll.floor, ll.area
         FROM reservations r
         INNER JOIN lockers l ON l.id = r.locker_id
         INNER JOIN locker_locations ll ON ll.id = l.location_id
         WHERE r.user_id = :user_id
         ORDER BY r.requested_at DESC"
    );
    $statement->execute([':user_id' => $user['id']]);

    echo json_encode(['success' => true, 'lockers' => $lockers, 'reservations' => $statement->fetchAll()]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load locker reservations.']);
}