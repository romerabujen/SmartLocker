-- Add payment tracking and assignment backfill for simulated locker payments.
USE SmartLocker;

CREATE TABLE IF NOT EXISTS reservation_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservation_id BIGINT UNSIGNED NOT NULL,
  checkout_session_id VARCHAR(100) NULL,
  amount_pesos INT UNSIGNED NOT NULL,
  payer_name VARCHAR(150) NULL,
  payer_phone VARCHAR(30) NULL,
  receipt_email VARCHAR(255) NULL,
  payment_mode ENUM('simulation', 'paymongo') NOT NULL DEFAULT 'simulation',
  status ENUM('pending', 'paid', 'failed', 'expired', 'refunded') NOT NULL DEFAULT 'pending',
  expires_at DATETIME NOT NULL,
  paid_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reservation_payment (reservation_id),
  UNIQUE KEY uq_paymongo_checkout_session (checkout_session_id),
  KEY idx_reservation_payments_expiry (status, expires_at),
  CONSTRAINT fk_reservation_payments_reservation FOREIGN KEY (reservation_id) REFERENCES reservations (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE reservation_payments
  ADD COLUMN IF NOT EXISTS payment_mode ENUM('simulation', 'paymongo') NOT NULL DEFAULT 'simulation' AFTER amount_pesos,
  ADD COLUMN IF NOT EXISTS payer_name VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS payer_phone VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS receipt_email VARCHAR(255) NULL;

-- Paid reservations remain pending until an administrator approves them.