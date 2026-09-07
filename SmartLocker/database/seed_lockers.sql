-- Seed 10 available lockers per floor.
-- Safe to run repeatedly: existing locker numbers are skipped.
USE SmartLocker;

DROP PROCEDURE IF EXISTS seed_smart_lockers;
DELIMITER $$

CREATE PROCEDURE seed_smart_lockers()
BEGIN
  DECLARE building_name VARCHAR(100);
  DECLARE building_code VARCHAR(20);
  DECLARE floor_count INT;
  DECLARE floor_number INT DEFAULT 1;
  DECLARE locker_number INT;
  DECLARE location_id_value INT UNSIGNED;

  -- Building 1, Building 2, and Building 3 each have four floors.
  SET floor_number = 1;
  WHILE floor_number <= 4 DO
    SET building_name = 'Building 1';
    SET building_code = 'B1';
    INSERT INTO locker_locations (building, floor, area)
    SELECT building_name, CONCAT('Floor ', floor_number), 'Main locker area'
    WHERE NOT EXISTS (
      SELECT 1 FROM locker_locations
      WHERE building = building_name AND floor = CONCAT('Floor ', floor_number) AND area = 'Main locker area'
    );
    SELECT id INTO location_id_value FROM locker_locations
    WHERE building = building_name AND floor = CONCAT('Floor ', floor_number) AND area = 'Main locker area' LIMIT 1;
    SET locker_number = 1;
    WHILE locker_number <= 10 DO
      INSERT IGNORE INTO lockers (location_id, locker_number, status, size)
      VALUES (location_id_value, CONCAT(building_code, '-F', LPAD(floor_number, 2, '0'), '-L', LPAD(locker_number, 2, '0')), 'available', 'medium');
      SET locker_number = locker_number + 1;
    END WHILE;

    SET building_name = 'Building 2';
    SET building_code = 'B2';
    INSERT INTO locker_locations (building, floor, area)
    SELECT building_name, CONCAT('Floor ', floor_number), 'Main locker area'
    WHERE NOT EXISTS (
      SELECT 1 FROM locker_locations
      WHERE building = building_name AND floor = CONCAT('Floor ', floor_number) AND area = 'Main locker area'
    );
    SELECT id INTO location_id_value FROM locker_locations
    WHERE building = building_name AND floor = CONCAT('Floor ', floor_number) AND area = 'Main locker area' LIMIT 1;
    SET locker_number = 1;
    WHILE locker_number <= 10 DO
      INSERT IGNORE INTO lockers (location_id, locker_number, status, size)
      VALUES (location_id_value, CONCAT(building_code, '-F', LPAD(floor_number, 2, '0'), '-L', LPAD(locker_number, 2, '0')), 'available', 'medium');
      SET locker_number = locker_number + 1;
    END WHILE;

    SET building_name = 'Building 3';
    SET building_code = 'B3';
    INSERT INTO locker_locations (building, floor, area)
    SELECT building_name, CONCAT('Floor ', floor_number), 'Main locker area'
    WHERE NOT EXISTS (
      SELECT 1 FROM locker_locations
      WHERE building = building_name AND floor = CONCAT('Floor ', floor_number) AND area = 'Main locker area'
    );
    SELECT id INTO location_id_value FROM locker_locations
    WHERE building = building_name AND floor = CONCAT('Floor ', floor_number) AND area = 'Main locker area' LIMIT 1;
    SET locker_number = 1;
    WHILE locker_number <= 10 DO
      INSERT IGNORE INTO lockers (location_id, locker_number, status, size)
      VALUES (location_id_value, CONCAT(building_code, '-F', LPAD(floor_number, 2, '0'), '-L', LPAD(locker_number, 2, '0')), 'available', 'medium');
      SET locker_number = locker_number + 1;
    END WHILE;
    SET floor_number = floor_number + 1;
  END WHILE;

  -- Admin Building has six floors.
  SET building_name = 'Admin Building';
  SET building_code = 'ADMIN';
  SET floor_number = 1;
  WHILE floor_number <= 6 DO
    INSERT INTO locker_locations (building, floor, area)
    SELECT building_name, CONCAT('Floor ', floor_number), 'Main locker area'
    WHERE NOT EXISTS (
      SELECT 1 FROM locker_locations
      WHERE building = building_name AND floor = CONCAT('Floor ', floor_number) AND area = 'Main locker area'
    );
    SELECT id INTO location_id_value FROM locker_locations
    WHERE building = building_name AND floor = CONCAT('Floor ', floor_number) AND area = 'Main locker area' LIMIT 1;
    SET locker_number = 1;
    WHILE locker_number <= 10 DO
      INSERT IGNORE INTO lockers (location_id, locker_number, status, size)
      VALUES (location_id_value, CONCAT(building_code, '-F', LPAD(floor_number, 2, '0'), '-L', LPAD(locker_number, 2, '0')), 'available', 'medium');
      SET locker_number = locker_number + 1;
    END WHILE;
    SET floor_number = floor_number + 1;
  END WHILE;

  -- HPSB has twelve floors.
  SET building_name = 'HPSB';
  SET building_code = 'HPSB';
  SET floor_number = 1;
  WHILE floor_number <= 12 DO
    INSERT INTO locker_locations (building, floor, area)
    SELECT building_name, CONCAT('Floor ', floor_number), 'Main locker area'
    WHERE NOT EXISTS (
      SELECT 1 FROM locker_locations
      WHERE building = building_name AND floor = CONCAT('Floor ', floor_number) AND area = 'Main locker area'
    );
    SELECT id INTO location_id_value FROM locker_locations
    WHERE building = building_name AND floor = CONCAT('Floor ', floor_number) AND area = 'Main locker area' LIMIT 1;
    SET locker_number = 1;
    WHILE locker_number <= 10 DO
      INSERT IGNORE INTO lockers (location_id, locker_number, status, size)
      VALUES (location_id_value, CONCAT(building_code, '-F', LPAD(floor_number, 2, '0'), '-L', LPAD(locker_number, 2, '0')), 'available', 'medium');
      SET locker_number = locker_number + 1;
    END WHILE;
    SET floor_number = floor_number + 1;
  END WHILE;
END$$

DELIMITER ;
CALL seed_smart_lockers();
DROP PROCEDURE seed_smart_lockers;

SELECT ll.building, COUNT(*) AS locker_count
FROM lockers l
INNER JOIN locker_locations ll ON ll.id = l.location_id
GROUP BY ll.building
ORDER BY ll.building;
