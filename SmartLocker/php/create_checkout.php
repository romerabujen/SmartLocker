<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/reservation_service.php';

$user = requireRole('student');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$lockerId = (int) ($_POST['locker_id'] ?? 0);
$duration = trim((string) ($_POST['duration'] ?? ''));
$packages = [
    '1_week' => ['label' => '1 Week', 'amount' => 50, 'interval' => '+1 week'],
    '1_month' => ['label' => '1 Month', 'amount' => 100, 'interval' => '+1 month'],
    '3_months' => ['label' => '3 Months', 'amount' => 150, 'interval' => '+3 months'],
];
if ($lockerId < 1 || !isset($packages[$duration])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Choose an available locker and a valid rental term.']);
    exit;
}
$connection = getDatabaseConnection();
try {
    reconcileExpiredReservations($connection);
    $connection->beginTransaction();

    $lockerStatement = $connection->prepare('SELECT id, status FROM lockers WHERE id = :id FOR UPDATE');
    $lockerStatement->execute([':id' => $lockerId]);
    $locker = $lockerStatement->fetch();
    if (!$locker || $locker['status'] !== 'available') {
        throw new InvalidArgumentException('That locker is no longer available.');
    }

    $lockerConflict = $connection->prepare(
        "SELECT 1 FROM reservations WHERE locker_id = :locker_id AND status IN ('pending', 'approved', 'active') LIMIT 1"
    );
    $lockerConflict->execute([':locker_id' => $lockerId]);
    if ($lockerConflict->fetchColumn()) {
        throw new InvalidArgumentException('That locker already has a pending or active reservation.');
    }

    $studentConflict = $connection->prepare(
        "SELECT 1 FROM reservations WHERE user_id = :user_id AND status IN ('pending', 'approved', 'active') LIMIT 1"
    );
    $studentConflict->execute([':user_id' => $user['id']]);
    if ($studentConflict->fetchColumn()) {
        throw new InvalidArgumentException('You already have a pending or active reservation.');
    }

    $insertReservation = $connection->prepare(
        "INSERT INTO reservations (locker_id, user_id, requested_at, duration, status)
         VALUES (:locker_id, :user_id, NOW(), :duration, 'pending')"
    );
    $insertReservation->execute([
        ':locker_id' => $lockerId,
        ':user_id' => $user['id'],
        ':duration' => $duration,
    ]);
    $reservationId = (int) $connection->lastInsertId();

    $insertPayment = $connection->prepare(
        "INSERT INTO reservation_payments (reservation_id, amount_pesos, payment_mode, status, expires_at)
         VALUES (:reservation_id, :amount, 'simulation', 'pending', DATE_ADD(NOW(), INTERVAL 24 HOUR))"
    );
    $insertPayment->execute([
        ':reservation_id' => $reservationId,
        ':amount' => $packages[$duration]['amount'],
    ]);
    $connection->prepare("UPDATE lockers SET status = 'pending' WHERE id = :id")
        ->execute([':id' => $lockerId]);
    $connection->commit();

    echo json_encode([
        'success' => true,
        'payment_url' => '../payment/payment.html?reservation_id=' . $reservationId,
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
    error_log('SmartLocker payment preparation failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to prepare payment. Please try again.']);
}