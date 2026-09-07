<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/reservation_service.php';
require_once __DIR__ . '/reservation_mail.php';

$user = requireRole('student');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$lockerId = (int) ($_POST['locker_id'] ?? 0);
if ($lockerId < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Select a valid locker.']);
    exit;
}

$connection = getDatabaseConnection();
try {
    reconcileExpiredReservations($connection);
    $connection->beginTransaction();

    $lockerStatement = $connection->prepare(
        'SELECT id, status FROM lockers WHERE id = :id FOR UPDATE'
    );
    $lockerStatement->execute([':id' => $lockerId]);
    $locker = $lockerStatement->fetch();
    if (!$locker || $locker['status'] !== 'available') {
        throw new InvalidArgumentException('That locker is no longer available.');
    }

    $conflictStatement = $connection->prepare(
        "SELECT 1 FROM reservations
         WHERE locker_id = :locker_id AND status IN ('pending', 'approved', 'active')
         LIMIT 1"
    );
    $conflictStatement->execute([':locker_id' => $lockerId]);
    if ($conflictStatement->fetchColumn()) {
        throw new InvalidArgumentException('That locker already has a pending or active reservation.');
    }

    $studentConflict = $connection->prepare(
        "SELECT 1 FROM reservations WHERE user_id = :user_id AND status IN ('pending', 'approved', 'active') LIMIT 1"
    );
    $studentConflict->execute([':user_id' => $user['id']]);
    if ($studentConflict->fetchColumn()) {
        throw new InvalidArgumentException('You already have a pending or active reservation.');
    }

    $insert = $connection->prepare(
        "INSERT INTO reservations (locker_id, user_id, requested_at, status)
         VALUES (:locker_id, :user_id, NOW(), 'pending')"
    );
    $insert->execute([':locker_id' => $lockerId, ':user_id' => $user['id']]);
    $reservationId = (int) $connection->lastInsertId();
    $connection->prepare("UPDATE lockers SET status = 'pending' WHERE id = :id")
        ->execute([':id' => $lockerId]);
    $connection->commit();

    try {
        $notificationSent = sendReservationRequestNotifications($connection, $reservationId);
    } catch (Throwable $exception) {
        $notificationSent = false;
    }
    echo json_encode([
        'success' => true,
        'message' => $notificationSent
            ? 'Reservation request submitted for admin approval.'
            : 'Reservation request submitted. Admin email notification could not be sent.',
    ]);
} catch (InvalidArgumentException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The reservation request could not be saved.']);
}