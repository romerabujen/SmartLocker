-- Backfill active locker assignments from existing approved reservations.
-- Safe to run repeatedly: active assignments are not duplicated.
USE SmartLocker;

INSERT INTO locker_assignments (locker_id, user_id, assigned_at, expires_at, status)
SELECT r.locker_id, r.user_id, COALESCE(r.starts_at, r.requested_at), r.ends_at, 'active'
FROM reservations r
LEFT JOIN locker_assignments a
  ON a.locker_id = r.locker_id
 AND a.user_id = r.user_id
 AND a.status = 'active'
WHERE r.status IN ('approved', 'active')
  AND a.id IS NULL;