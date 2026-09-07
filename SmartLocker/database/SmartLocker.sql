CREATE DATABASE IF NOT EXISTS SmartLocker
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE SmartLocker;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  last_name VARCHAR(100) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  middle_initial VARCHAR(1) NULL,
  student_id VARCHAR(30) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  profile_picture VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_users_umak_email CHECK (email LIKE '%@umak.edu.ph')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS locker_locations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  building VARCHAR(100) NOT NULL,
  floor VARCHAR(50) NOT NULL,
  area VARCHAR(100) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_location (building, floor, area)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lockers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  location_id INT UNSIGNED NOT NULL,
  locker_number VARCHAR(30) NOT NULL,
  status ENUM('available', 'pending', 'reserved', 'occupied', 'maintenance', 'offline') NOT NULL DEFAULT 'available',
  size ENUM('small', 'medium', 'large') NOT NULL DEFAULT 'medium',
  description VARCHAR(255) NULL,
  installed_at DATE NULL,
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_locker_number (locker_number),
  KEY idx_lockers_status (status),
  CONSTRAINT fk_lockers_location FOREIGN KEY (location_id) REFERENCES locker_locations (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS locker_devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  locker_id INT UNSIGNED NOT NULL,
  device_identifier VARCHAR(100) NOT NULL UNIQUE,
  firmware_version VARCHAR(50) NULL,
  status ENUM('online', 'offline', 'maintenance') NOT NULL DEFAULT 'offline',
  last_heartbeat_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_devices_locker (locker_id),
  CONSTRAINT fk_devices_locker FOREIGN KEY (locker_id) REFERENCES lockers (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS locker_assignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  locker_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  released_at DATETIME NULL,
  status ENUM('active', 'expired', 'released') NOT NULL DEFAULT 'active',
  notes VARCHAR(255) NULL,
  KEY idx_assignments_user_status (user_id, status),
  KEY idx_assignments_locker_status (locker_id, status),
  CONSTRAINT fk_assignments_locker FOREIGN KEY (locker_id) REFERENCES lockers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_assignments_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  locker_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  duration VARCHAR(20) NULL,
  status ENUM('pending', 'approved', 'active', 'rejected', 'completed', 'cancelled', 'expired') NOT NULL DEFAULT 'pending',
  purpose VARCHAR(255) NULL,
  approved_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_reservations_user_status (user_id, status),
  KEY idx_reservations_locker_dates (locker_id, starts_at, ends_at),
  KEY idx_reservations_locker_status (locker_id, status),
  CONSTRAINT fk_reservations_locker FOREIGN KEY (locker_id) REFERENCES lockers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_reservations_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_reservation_dates CHECK (ends_at > starts_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS access_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  locker_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  device_id INT UNSIGNED NULL,
  event_type ENUM('unlock_requested', 'unlocked', 'opened', 'closed', 'access_denied', 'forced_open') NOT NULL,
  access_method ENUM('web', 'mobile', 'rfid', 'admin', 'system') NOT NULL DEFAULT 'web',
  was_successful BOOLEAN NOT NULL DEFAULT TRUE,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  details VARCHAR(255) NULL,
  ip_address VARCHAR(45) NULL,
  KEY idx_access_logs_locker_time (locker_id, occurred_at),
  KEY idx_access_logs_user_time (user_id, occurred_at),
  KEY idx_access_logs_event_time (event_type, occurred_at),
  CONSTRAINT fk_access_logs_locker FOREIGN KEY (locker_id) REFERENCES lockers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_access_logs_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_access_logs_device FOREIGN KEY (device_id) REFERENCES locker_devices (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_reports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  locker_id INT UNSIGNED NOT NULL,
  reported_by INT UNSIGNED NULL,
  issue_type ENUM('lock', 'door', 'screen', 'network', 'power', 'other') NOT NULL DEFAULT 'other',
  priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',
  description TEXT NOT NULL,
  status ENUM('open', 'investigating', 'resolved', 'closed') NOT NULL DEFAULT 'open',
  reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  resolution_notes TEXT NULL,
  KEY idx_maintenance_status (status, priority),
  KEY idx_maintenance_locker (locker_id),
  CONSTRAINT fk_maintenance_locker FOREIGN KEY (locker_id) REFERENCES lockers (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_maintenance_reporter FOREIGN KEY (reported_by) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_activity (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  identifier VARCHAR(255) NOT NULL,
  was_successful BOOLEAN NOT NULL DEFAULT FALSE,
  failure_reason VARCHAR(100) NULL,
  ip_address VARCHAR(45) NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_activity_user_time (user_id, attempted_at),
  KEY idx_login_activity_identifier_time (identifier, attempted_at),
  CONSTRAINT fk_login_activity_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_verification_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  verified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_verification_user (user_id),
  KEY idx_verification_expiry (expires_at, verified_at),
  CONSTRAINT fk_verification_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  identifier VARCHAR(255) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_at DATETIME NULL,
  last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_login_attempt (identifier, ip_address),
  KEY idx_login_attempt_user (user_id),
  CONSTRAINT fk_login_attempt_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  pending_password_hash VARCHAR(255) NULL,
  confirmation_token_hash CHAR(64) NULL UNIQUE,
  confirmation_expires_at DATETIME NULL,
  confirmed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_password_reset_user (user_id),
  CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE password_reset_tokens
  ADD COLUMN IF NOT EXISTS pending_password_hash VARCHAR(255) NULL AFTER used_at,
  ADD COLUMN IF NOT EXISTS confirmation_token_hash CHAR(64) NULL AFTER pending_password_hash,
  ADD COLUMN IF NOT EXISTS confirmation_expires_at DATETIME NULL AFTER confirmation_token_hash,
  ADD COLUMN IF NOT EXISTS confirmed_at DATETIME NULL AFTER confirmation_expires_at;

CREATE UNIQUE INDEX IF NOT EXISTS uq_password_reset_confirmation_token
  ON password_reset_tokens (confirmation_token_hash);