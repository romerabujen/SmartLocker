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

function sendLockerAssignmentNotification(PDO $connection, int $assignmentId, string $event, string $reason): bool
{
    $statement = $connection->prepare(
        'SELECT u.first_name, u.email, a.status AS assignment_status,
                l.locker_number, ll.building, ll.floor, ll.area
         FROM locker_assignments a
         INNER JOIN users u ON u.id = a.user_id
         INNER JOIN lockers l ON l.id = a.locker_id
         INNER JOIN locker_locations ll ON ll.id = l.location_id
         WHERE a.id = :assignment_id
         LIMIT 1'
    );
    $statement->execute([':assignment_id' => $assignmentId]);
    $assignment = $statement->fetch();
    if (!$assignment) {
        return false;
    }

    $location = implode(' / ', array_filter([
        $assignment['building'],
        $assignment['floor'],
        $assignment['area'],
    ], static fn ($value): bool => $value !== null && $value !== ''));
    $loginUrl = rtrim((string) constant('APP_BASE_URL'), '/') . '/pages/login/login.html';
    $maintenance = $event === 'maintenance_started' || $event === 'maintenance_completed';
    if ($event === 'maintenance_completed') {
        $subject = 'Your SmartLocker is ready to use again';
        $message = "Hello {$assignment['first_name']},\n\n"
            . "Maintenance for your SmartLocker has been completed. You may use the locker again.\n\n";
    } else {
        $subject = $maintenance
            ? 'Your SmartLocker will undergo maintenance'
            : 'Your SmartLocker assignment has been ended';
        $message = "Hello {$assignment['first_name']},\n\n"
            . ($maintenance
                ? "Your locker has been tagged for maintenance. Please vacate it within 24 hours. We will notify you once the maintenance is complete.\n\n"
                : "Your locker assignment has been ended. Please vacate the locker within 24 hours.\n\n");
    }

    $message .= "Locker: {$assignment['locker_number']}\n"
        . "Location: {$location}\n"
        . "Reason: {$reason}\n\n"
        . "Log in to SmartLocker:\n{$loginUrl}\n\nThank you,\nSmartLocker Administration";

    return sendReservationNotification($assignment['email'], $subject, $message);
}
