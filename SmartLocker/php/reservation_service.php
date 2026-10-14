<?php
declare(strict_types=1);

function reconcileExpiredReservations(PDO $connection): void
{
    $connection->beginTransaction();
    try {
        $connection->exec(
            "UPDATE reservation_payments SET status = 'expired'
             WHERE status = 'pending' AND expires_at <= NOW()"
        );
        $connection->exec(
            "UPDATE reservations r
             INNER JOIN reservation_payments p ON p.reservation_id = r.id
             SET r.status = 'cancelled', r.cancelled_at = NOW()
             WHERE p.status = 'expired' AND r.status = 'pending'"
        );

        $expired = $connection->query(
            "SELECT id, locker_id FROM reservations
             WHERE status IN ('approved', 'active') AND ends_at IS NOT NULL AND ends_at <= NOW()
             FOR UPDATE"
        )->fetchAll();

        if ($expired) {
            $ids = array_column($expired, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $statement = $connection->prepare(
                "UPDATE reservations SET status = 'expired' WHERE id IN ($placeholders)"
            );
            $statement->execute($ids);
        }

        $connection->exec(
            "UPDATE locker_assignments
             SET status = 'expired', released_at = NOW()
             WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= NOW()"
        );

        $connection->exec(
            "UPDATE lockers l
             SET status = CASE
                 WHEN EXISTS (SELECT 1 FROM reservations p WHERE p.locker_id = l.id AND p.status = 'pending') THEN 'pending'
                 WHEN EXISTS (SELECT 1 FROM reservations a WHERE a.locker_id = l.id AND a.status IN ('approved', 'active')) THEN 'reserved'
                 WHEN l.status IN ('pending', 'reserved', 'occupied') THEN 'available'
                 ELSE l.status
             END
             WHERE l.status IN ('pending', 'reserved')"
        );
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}