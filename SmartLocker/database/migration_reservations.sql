-- Safe to run more than once against an existing SmartLocker database.
-- Existing reservation history is preserved.
USE SmartLocker;

DROP PROCEDURE IF EXISTS migrate_reservations;
DELIMITER $$

CREATE PROCEDURE migrate_reservations()
BEGIN
  DECLARE index_exists INT DEFAULT 0;
  DECLARE column_exists INT DEFAULT 0;

  SELECT COUNT(*) INTO index_exists
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'lockers'
    AND index_name = 'idx_lockers_location';
  IF index_exists = 0 THEN
    ALTER TABLE lockers ADD KEY idx_lockers_location (location_id);
  END IF;

  SELECT COUNT(*) INTO index_exists
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'lockers'
    AND index_name = 'uq_locker_number';
  IF index_exists > 0 THEN
    ALTER TABLE lockers DROP INDEX uq_locker_number;
  END IF;

  ALTER TABLE lockers
    ADD UNIQUE KEY uq_locker_number (locker_number),
    MODIFY status ENUM('available', 'pending', 'reserved', 'occupied', 'maintenance', 'offline') NOT NULL DEFAULT 'available';

  SELECT COUNT(*) INTO column_exists
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'reservations'
    AND column_name = 'requested_at';
  IF column_exists = 0 THEN
    ALTER TABLE reservations
      ADD COLUMN requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER user_id;
  END IF;

  SELECT COUNT(*) INTO column_exists
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'reservations'
    AND column_name = 'duration';
  IF column_exists = 0 THEN
    ALTER TABLE reservations ADD COLUMN duration VARCHAR(20) NULL AFTER ends_at;
  END IF;

  ALTER TABLE reservations
    MODIFY starts_at DATETIME NULL,
    MODIFY ends_at DATETIME NULL,
    MODIFY status ENUM('pending', 'approved', 'active', 'rejected', 'completed', 'cancelled', 'expired') NOT NULL DEFAULT 'pending';

  SELECT COUNT(*) INTO index_exists
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'reservations'
    AND index_name = 'idx_reservations_locker_status';
  IF index_exists = 0 THEN
    ALTER TABLE reservations ADD KEY idx_reservations_locker_status (locker_id, status);
  END IF;
END$$

DELIMITER ;
CALL migrate_reservations();
DROP PROCEDURE migrate_reservations;

-- Existing rows remain valid. Pending rows use their legacy dates until reviewed.