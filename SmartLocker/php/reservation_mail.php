<?php
declare(strict_types=1);

require_once __DIR__ . '/verification_mail.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

function sendReservationNotification(string $email, string $subject, string $message): bool
{
    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = (string) constant('SMTP_HOST');
    $mailer->SMTPAuth = true;
    $mailer->Username = (string) constant('SMTP_USERNAME');
    $mailer->Password = (string) constant('SMTP_PASSWORD');
    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Port = (int) constant('SMTP_PORT');
    $mailer->setFrom((string) constant('MAIL_FROM'), 'SmartLocker');
    $mailer->addAddress($email);
    $mailer->Subject = $subject;
    $mailer->Body = $message;

    try {
        return $mailer->send();
    } catch (Exception $exception) {
        return false;
    }
}

function sendReservationRequestNotifications(PDO $connection, int $reservationId): bool
{
    $statement = $connection->prepare(
        'SELECT u.first_name, u.last_name, u.student_id, u.email,
                r.requested_at, l.locker_number,
                ll.building, ll.floor, ll.area
         FROM reservations r
         INNER JOIN users u ON u.id = r.user_id
         INNER JOIN lockers l ON l.id = r.locker_id
         INNER JOIN locker_locations ll ON ll.id = l.location_id
         WHERE r.id = :reservation_id
         LIMIT 1'
    );
    $statement->execute([':reservation_id' => $reservationId]);
    $reservation = $statement->fetch();
    if (!$reservation) {
        return false;
    }

    $adminEmails = $connection->query(
        "SELECT email FROM admins WHERE email IS NOT NULL AND email <> ''"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!$adminEmails) {
        return false;
    }

    $location = implode(' / ', array_filter([
        $reservation['building'],
        $reservation['floor'],
        $reservation['area'],
    ], static fn ($value): bool => $value !== null && $value !== ''));
    $loginUrl = rtrim((string) constant('APP_BASE_URL'), '/') . '/pages/login/login.html';
    $message = "A new locker reservation request needs review.\n\n"
        . "Student: {$reservation['first_name']} {$reservation['last_name']}\n"
        . "Student ID: {$reservation['student_id']}\n"
        . "Student email: {$reservation['email']}\n"
        . "Locker: {$reservation['locker_number']}\n"
        . "Location: {$location}\n"
        . "Requested at: {$reservation['requested_at']}\n\n"
        . "Log in to SmartLocker to approve or reject this request:\n{$loginUrl}";

    $sent = true;
    foreach ($adminEmails as $adminEmail) {
        $sent = sendReservationNotification(
            (string) $adminEmail,
            'New SmartLocker reservation request',
            $message
        ) && $sent;
    }
    return $sent;
}

function sendReservationDecisionNotification(PDO $connection, int $reservationId, string $status): bool
{
    $statement = $connection->prepare(
        'SELECT u.first_name, u.email, r.duration, r.starts_at, r.ends_at,
                l.locker_number, ll.building, ll.floor, ll.area
         FROM reservations r
         INNER JOIN users u ON u.id = r.user_id
         INNER JOIN lockers l ON l.id = r.locker_id
         INNER JOIN locker_locations ll ON ll.id = l.location_id
         WHERE r.id = :reservation_id AND r.status = :status
         LIMIT 1'
    );
    $statement->execute([':reservation_id' => $reservationId, ':status' => $status]);
    $reservation = $statement->fetch();
    if (!$reservation) {
        return false;
    }

    $location = implode(' / ', array_filter([
        $reservation['building'],
        $reservation['floor'],
        $reservation['area'],
    ], static fn ($value): bool => $value !== null && $value !== ''));
    $loginUrl = rtrim((string) constant('APP_BASE_URL'), '/') . '/pages/login/login.html';
    $approved = $status === 'active';
    $subject = $approved
        ? 'Your SmartLocker reservation was approved'
        : 'Your SmartLocker reservation was rejected';
    $message = "Hello {$reservation['first_name']},\n\n"
        . ($approved
            ? "Your locker reservation has been approved.\n\n"
            : "Your locker reservation request was rejected. You may choose another available locker.\n\n")
        . "Locker: {$reservation['locker_number']}\n"
        . "Location: {$location}\n"
        . ($approved
            ? "Duration: {$reservation['duration']}\nStart date: {$reservation['starts_at']}\nExpiration date: {$reservation['ends_at']}\n"
            : '')
        . "\nLog in to SmartLocker:\n{$loginUrl}\n\nThank you,\nSmartLocker Administration";

    return sendReservationNotification($reservation['email'], $subject, $message);
}
