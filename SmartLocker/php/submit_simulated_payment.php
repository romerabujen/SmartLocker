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

$reservationId = (int) ($_POST['reservation_id'] ?? 0);
$payerName = trim((string) ($_POST['payer_name'] ?? ''));
$payerPhone = trim((string) ($_POST['payer_phone'] ?? ''));
$amountPesos = filter_var($_POST['amount_pesos'] ?? null, FILTER_VALIDATE_INT);
$receiptEmail = trim((string) ($_POST['receipt_email'] ?? ''));
if ($reservationId < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid reservation is required.']);
    exit;
}
if ($payerName === '' || strlen($payerName) > 150 || $payerPhone === '' || strlen($payerPhone) > 30
    || !preg_match('/^[0-9+(). -]{7,30}$/', $payerPhone)
    || $amountPesos === false || $amountPesos === null || $amountPesos < 1
    || ($receiptEmail !== '' && (strlen($receiptEmail) > 255
        || !filter_var($receiptEmail, FILTER_VALIDATE_EMAIL)
        || !preg_match('/@umak\.edu\.ph$/i', $receiptEmail)))) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a valid name, mobile number, amount, and optional @umak.edu.ph receipt email.']);
    exit;
}

$packages = [
    '1_week' => ['amount' => 50, 'interval' => '+1 week'],
    '1_month' => ['amount' => 100, 'interval' => '+1 month'],
    '3_months' => ['amount' => 150, 'interval' => '+3 months'],
];
$connection = getDatabaseConnection();
try {
    reconcileExpiredReservations($connection);
    $connection->beginTransaction();

    $reservationStatement = $connection->prepare(
        'SELECT r.id, r.locker_id, r.user_id, r.duration, r.status AS reservation_status,
                p.id AS payment_id, p.amount_pesos, p.status AS payment_status,
            p.payment_mode, p.expires_at, u.first_name, u.email,
            l.locker_number
         FROM reservations r
         INNER JOIN reservation_payments p ON p.reservation_id = r.id
         INNER JOIN users u ON u.id = r.user_id
         INNER JOIN lockers l ON l.id = r.locker_id
         WHERE r.id = :reservation_id AND r.user_id = :user_id
         FOR UPDATE'
    );
    $reservationStatement->execute([':reservation_id' => $reservationId, ':user_id' => $user['id']]);
    $reservation = $reservationStatement->fetch();
    if (!$reservation) {
        throw new InvalidArgumentException('Payment details could not be found.');
    }

    if ($reservation['reservation_status'] === 'pending' && $reservation['payment_status'] === 'paid') {
        $connection->commit();
        echo json_encode(['success' => true, 'message' => 'Your payment is already submitted and is being verified by an administrator.']);
        exit;
    }

    $duration = (string) $reservation['duration'];
    if ($reservation['reservation_status'] !== 'pending'
        || $reservation['payment_status'] !== 'pending'
        || $reservation['payment_mode'] !== 'simulation'
        || !isset($packages[$duration])
        || (int) $reservation['amount_pesos'] !== $packages[$duration]['amount']
        || $amountPesos !== $packages[$duration]['amount']) {
        throw new InvalidArgumentException('This payment cannot be submitted. Start a new locker reservation.');
    }
    if (strtotime((string) $reservation['expires_at']) <= time()) {
        throw new InvalidArgumentException('This payment session has expired. Start a new locker reservation.');
    }

    $lockerStatement = $connection->prepare('SELECT id, status FROM lockers WHERE id = :id FOR UPDATE');
    $lockerStatement->execute([':id' => $reservation['locker_id']]);
    $locker = $lockerStatement->fetch();
    if (!$locker || $locker['status'] !== 'pending') {
        throw new InvalidArgumentException('This locker is no longer available. Start a new locker reservation.');
    }

    $connection->prepare(
        "UPDATE reservation_payments
         SET status = 'paid', paid_at = NOW(), payer_name = :payer_name,
             payer_phone = :payer_phone, receipt_email = :receipt_email
         WHERE id = :id AND status = 'pending'"
    )->execute([
        ':payer_name' => $payerName,
        ':payer_phone' => $payerPhone,
        ':receipt_email' => $receiptEmail !== '' ? $receiptEmail : null,
        ':id' => $reservation['payment_id'],
    ]);
    $connection->commit();

    $receiptSent = sendPaymentReceiptNotification(
        $receiptEmail !== '' ? $receiptEmail : (string) $reservation['email'],
        $payerName,
        $payerPhone,
        (int) $reservation['amount_pesos'],
        (string) $reservation['locker_number'],
        $duration,
        $reservationId
    );
    echo json_encode([
        'success' => true,
        'message' => 'Your payment is being verified. You will receive a notification after an administrator reviews it.'
            . ($receiptSent ? ' A receipt has been emailed to you.' : ' The receipt email could not be sent; please check your email address or contact support.'),
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
    error_log('SmartLocker simulated payment submission failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to submit payment. Please try again.']);
}