<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/reservation_service.php';

$user = requireRole('student');
$reservationId = (int) ($_GET['reservation_id'] ?? 0);
if ($reservationId < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid reservation is required.']);
    exit;
}

$connection = getDatabaseConnection();
try {
    reconcileExpiredReservations($connection);
    $statement = $connection->prepare(
        'SELECT r.id, r.locker_id, r.duration, r.status AS reservation_status,
                p.amount_pesos, p.status AS payment_status, p.payment_mode, p.expires_at,
            l.locker_number, ll.building, ll.floor, ll.area,
            u.first_name, u.last_name
         FROM reservations r
         INNER JOIN reservation_payments p ON p.reservation_id = r.id
         INNER JOIN lockers l ON l.id = r.locker_id
         INNER JOIN locker_locations ll ON ll.id = l.location_id
         INNER JOIN users u ON u.id = r.user_id
         WHERE r.id = :reservation_id AND r.user_id = :user_id
         LIMIT 1'
    );
    $statement->execute([':reservation_id' => $reservationId, ':user_id' => $user['id']]);
    $reservation = $statement->fetch();
    if (!$reservation) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment details could not be found.']);
        exit;
    }

    echo json_encode(['success' => true, 'reservation' => $reservation]);
} catch (Throwable $exception) {
    error_log('SmartLocker payment details failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load payment details.']);
}