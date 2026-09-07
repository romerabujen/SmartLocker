<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/reservation_service.php';
require_once __DIR__ . '/reservation_mail.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$action = trim((string) ($_POST['action'] ?? ''));
$connection = getDatabaseConnection();

try {
    if ($action === 'create_locker') {
        $building = trim((string) ($_POST['building'] ?? ''));
        $floor = trim((string) ($_POST['floor'] ?? ''));
        $area = trim((string) ($_POST['area'] ?? ''));
        $lockerNumber = trim((string) ($_POST['locker_number'] ?? ''));
        $size = trim((string) ($_POST['size'] ?? 'medium'));
        if ($building === '' || $floor === '' || $lockerNumber === '' || !in_array($size, ['small', 'medium', 'large'], true)) {
            throw new InvalidArgumentException('Building, floor, locker number, and size are required.');
        }
        $connection->beginTransaction();
        $duplicateLocker = $connection->prepare('SELECT 1 FROM lockers WHERE locker_number = :locker_number LIMIT 1');
        $duplicateLocker->execute([':locker_number' => $lockerNumber]);
        if ($duplicateLocker->fetchColumn()) {
            throw new InvalidArgumentException('That locker number is already registered.');
        }
        $locationStatement = $connection->prepare(
            'SELECT id FROM locker_locations WHERE building = :building AND floor = :floor
             AND ((area = :area_match) OR (area IS NULL AND :area_is_null = 1)) LIMIT 1'
        );
        $locationStatement->execute([
            ':building' => $building,
            ':floor' => $floor,
            ':area_match' => $area,
            ':area_is_null' => $area === '' ? 1 : 0,
        ]);
        $locationId = $locationStatement->fetchColumn();
        if (!$locationId) {
            $locationStatement = $connection->prepare(
                'INSERT INTO locker_locations (building, floor, area) VALUES (:building, :floor, :area)'
            );
            $locationStatement->execute([':building' => $building, ':floor' => $floor, ':area' => $area !== '' ? $area : null]);
            $locationId = $connection->lastInsertId();
        }
        $lockerStatement = $connection->prepare(
            'INSERT INTO lockers (location_id, locker_number, size) VALUES (:location_id, :locker_number, :size)'
        );
        $lockerStatement->execute([':location_id' => $locationId, ':locker_number' => $lockerNumber, ':size' => $size]);
        $connection->commit();
    } elseif ($action === 'edit_locker') {
        $lockerId = (int) ($_POST['locker_id'] ?? 0);
        $building = trim((string) ($_POST['building'] ?? ''));
        $floor = trim((string) ($_POST['floor'] ?? ''));
        $area = trim((string) ($_POST['area'] ?? ''));
        $lockerNumber = trim((string) ($_POST['locker_number'] ?? ''));
        $size = trim((string) ($_POST['size'] ?? 'medium'));
        if ($lockerId < 1 || $building === '' || $floor === '' || $lockerNumber === '' || !in_array($size, ['small', 'medium', 'large'], true)) {
            throw new InvalidArgumentException('Locker ID, building, floor, locker number, and size are required.');
        }

        $connection->beginTransaction();
        $lockerStatement = $connection->prepare('SELECT id FROM lockers WHERE id = :id FOR UPDATE');
        $lockerStatement->execute([':id' => $lockerId]);
        if (!$lockerStatement->fetch()) {
            throw new InvalidArgumentException('The locker could not be found.');
        }
        $duplicateStatement = $connection->prepare(
            'SELECT 1 FROM lockers WHERE locker_number = :locker_number AND id <> :id LIMIT 1'
        );
        $duplicateStatement->execute([':locker_number' => $lockerNumber, ':id' => $lockerId]);
        if ($duplicateStatement->fetchColumn()) {
            throw new InvalidArgumentException('That locker number is already registered.');
        }
        $locationStatement = $connection->prepare(
            'SELECT id FROM locker_locations WHERE building = :building AND floor = :floor
             AND ((area = :area_match) OR (area IS NULL AND :area_is_null = 1)) LIMIT 1'
        );
        $locationStatement->execute([
            ':building' => $building,
            ':floor' => $floor,
            ':area_match' => $area,
            ':area_is_null' => $area === '' ? 1 : 0,
        ]);
        $locationId = $locationStatement->fetchColumn();
        if (!$locationId) {
            $locationStatement = $connection->prepare(
                'INSERT INTO locker_locations (building, floor, area) VALUES (:building, :floor, :area)'
            );
            $locationStatement->execute([':building' => $building, ':floor' => $floor, ':area' => $area !== '' ? $area : null]);
            $locationId = $connection->lastInsertId();
        }
        $update = $connection->prepare(
            'UPDATE lockers SET location_id = :location_id, locker_number = :locker_number, size = :size WHERE id = :id'
        );
        $update->execute([
            ':location_id' => $locationId,
            ':locker_number' => $lockerNumber,
            ':size' => $size,
            ':id' => $lockerId,
        ]);
        $connection->commit();
    } elseif ($action === 'delete_locker') {
        $lockerId = (int) ($_POST['locker_id'] ?? 0);
        if ($lockerId < 1) {
            throw new InvalidArgumentException('Select a valid locker.');
        }
        $connection->beginTransaction();
        $lockerStatement = $connection->prepare('SELECT id FROM lockers WHERE id = :id FOR UPDATE');
        $lockerStatement->execute([':id' => $lockerId]);
        if (!$lockerStatement->fetch()) {
            throw new InvalidArgumentException('The locker could not be found.');
        }
        $references = [
            'reservations' => 'locker_id',
            'locker_assignments' => 'locker_id',
            'locker_devices' => 'locker_id',
            'access_logs' => 'locker_id',
            'maintenance_reports' => 'locker_id',
        ];
        foreach ($references as $table => $column) {
            $referenceStatement = $connection->prepare("SELECT 1 FROM {$table} WHERE {$column} = :locker_id LIMIT 1");
            $referenceStatement->execute([':locker_id' => $lockerId]);
            if ($referenceStatement->fetchColumn()) {
                throw new InvalidArgumentException('This locker cannot be deleted because it has existing history or assignments.');
            }
        }
        $connection->prepare('DELETE FROM lockers WHERE id = :id')->execute([':id' => $lockerId]);
        $connection->commit();
    } elseif ($action === 'update_locker_status') {
        $status = trim((string) ($_POST['status'] ?? ''));
        if (!in_array($status, ['available', 'occupied', 'maintenance', 'offline'], true)) {
            throw new InvalidArgumentException('Invalid locker status.');
        }
        $statement = $connection->prepare('UPDATE lockers SET status = :status WHERE id = :id');
        $statement->execute([':status' => $status, ':id' => (int) ($_POST['locker_id'] ?? 0)]);
    } elseif ($action === 'update_reservation') {
        $status = trim((string) ($_POST['status'] ?? ''));
        $reservationId = (int) ($_POST['reservation_id'] ?? 0);
        if (!in_array($status, ['approved', 'rejected', 'cancelled'], true)) {
            throw new InvalidArgumentException('Invalid reservation decision.');
        }
        $duration = trim((string) ($_POST['duration'] ?? ''));
        $durations = ['1_week' => '+1 week', '2_weeks' => '+2 weeks', '3_weeks' => '+3 weeks', '1_month' => '+1 month'];
        if ($status === 'approved' && !isset($durations[$duration])) {
            throw new InvalidArgumentException('Select a valid reservation duration.');
        }

        reconcileExpiredReservations($connection);
        $connection->beginTransaction();
        $reservationStatement = $connection->prepare(
            'SELECT id, locker_id, status FROM reservations WHERE id = :id FOR UPDATE'
        );
        $reservationStatement->execute([':id' => $reservationId]);
        $reservation = $reservationStatement->fetch();
        if (!$reservation || $reservation['status'] !== 'pending') {
            throw new InvalidArgumentException('Only pending reservations can be decided.');
        }

        $lockerStatement = $connection->prepare('SELECT id, status FROM lockers WHERE id = :id FOR UPDATE');
        $lockerStatement->execute([':id' => $reservation['locker_id']]);
        $locker = $lockerStatement->fetch();
        if (!$locker) {
            throw new InvalidArgumentException('The selected locker no longer exists.');
        }

        if ($status === 'approved') {
            $activeStatement = $connection->prepare(
                "SELECT 1 FROM reservations
                 WHERE locker_id = :locker_id AND status IN ('approved', 'active') AND id <> :id LIMIT 1"
            );
            $activeStatement->execute([':locker_id' => $locker['id'], ':id' => $reservationId]);
            if ($activeStatement->fetchColumn() || !in_array($locker['status'], ['available', 'pending'], true)) {
                throw new InvalidArgumentException('That locker is no longer available for approval.');
            }
            $startDate = new DateTimeImmutable('now');
            $expirationDate = $startDate->modify($durations[$duration]);
            $update = $connection->prepare(
                "UPDATE reservations
                 SET status = 'active', duration = :duration, approved_at = :approved_at,
                     starts_at = :starts_at, ends_at = :ends_at
                 WHERE id = :id"
            );
            $update->execute([
                ':duration' => $duration,
                ':approved_at' => $startDate->format('Y-m-d H:i:s'),
                ':starts_at' => $startDate->format('Y-m-d H:i:s'),
                ':ends_at' => $expirationDate->format('Y-m-d H:i:s'),
                ':id' => $reservationId,
            ]);
            $connection->prepare("UPDATE lockers SET status = 'reserved' WHERE id = :id")
                ->execute([':id' => $locker['id']]);
        } else {
            $update = $connection->prepare(
                "UPDATE reservations SET status = :status_set, cancelled_at = IF(:status_check = 'cancelled', NOW(), cancelled_at) WHERE id = :id"
            );
            $update->execute([':status_set' => $status, ':status_check' => $status, ':id' => $reservationId]);
            $connection->prepare(
                "UPDATE lockers SET status = CASE
                    WHEN EXISTS (SELECT 1 FROM reservations WHERE locker_id = :locker_check_id AND status = 'pending') THEN 'pending'
                    ELSE 'available' END
                 WHERE id = :locker_where_id AND status = 'pending'"
            )->execute([':locker_check_id' => $locker['id'], ':locker_where_id' => $locker['id']]);
        }
        $connection->commit();
        try {
            $notificationSent = sendReservationDecisionNotification($connection, $reservationId, $status === 'approved' ? 'active' : $status);
        } catch (Throwable $exception) {
            $notificationSent = false;
        }
    } elseif ($action === 'resolve_report') {
        $statement = $connection->prepare(
            "UPDATE maintenance_reports SET status = 'resolved', resolved_at = NOW(), resolution_notes = :notes WHERE id = :id"
        );
        $statement->execute([
            ':notes' => trim((string) ($_POST['resolution_notes'] ?? 'Resolved by administrator.')),
            ':id' => (int) ($_POST['report_id'] ?? 0),
        ]);
    } else {
        throw new InvalidArgumentException('Unknown administrator action.');
    }

    echo json_encode([
        'success' => true,
        'message' => isset($notificationSent) && !$notificationSent
            ? 'Update saved, but the email notification could not be sent.'
            : 'Update saved.',
    ]);
} catch (InvalidArgumentException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
} catch (PDOException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The administrator update could not be saved.']);
}
