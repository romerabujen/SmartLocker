-- Add administrator change history used by the admin audit log.
USE SmartLocker;

CREATE TABLE IF NOT EXISTS admin_audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NOT NULL,
  action VARCHAR(80) NOT NULL,
  target_type VARCHAR(50) NOT NULL,
  target_id VARCHAR(50) NULL,
  details VARCHAR(1000) NOT NULL,
  ip_address VARCHAR(45) NULL,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_admin_audit_logs_time (occurred_at),
  KEY idx_admin_audit_logs_admin_time (admin_id, occurred_at),
  KEY idx_admin_audit_logs_target (target_type, target_id)
) ENGINE=InnoDB;
