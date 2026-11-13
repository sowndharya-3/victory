-- SP-5: orders, payments, access codes, audit log. Additive; one-shot like 001/002.
ALTER TABLE orders
  ADD COLUMN paid_at DATETIME NULL,
  ADD COLUMN subtotal_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  ADD COLUMN shipping_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  ADD COLUMN gst_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  ADD COLUMN gst_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  ADD COLUMN gst_mode ENUM('inclusive','exclusive') NOT NULL DEFAULT 'inclusive',
  ADD COLUMN fulfilment_status ENUM('not_applicable','confirmed','processing','shipped','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'not_applicable',
  ADD COLUMN courier VARCHAR(100) NULL,
  ADD COLUMN tracking_number VARCHAR(100) NULL,
  ADD COLUMN tracking_code VARCHAR(20) NULL,
  ADD COLUMN shipped_at DATETIME NULL,
  ADD COLUMN referral_code_snapshot VARCHAR(30) NULL,
  ADD COLUMN admin_flag VARCHAR(100) NULL,
  ADD UNIQUE KEY uq_orders_tracking_code (tracking_code),
  ADD INDEX idx_orders_user_created (user_id, created_at),
  ADD INDEX idx_orders_status_created (status, created_at);

ALTER TABLE payments
  ADD COLUMN failure_reason VARCHAR(255) NULL,
  ADD UNIQUE KEY uq_payments_rzp_order (razorpay_order_id),
  ADD UNIQUE KEY uq_payments_rzp_payment (razorpay_payment_id);

ALTER TABLE book_access
  ADD COLUMN revoked_at DATETIME NULL;

CREATE TABLE payment_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id VARCHAR(100) NOT NULL UNIQUE,
  event_type VARCHAR(60) NOT NULL,
  payload JSON NULL,
  processed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE rate_limits (
  bucket VARCHAR(190) NOT NULL,
  window_start DATETIME NOT NULL,
  hits INT NOT NULL DEFAULT 1,
  PRIMARY KEY (bucket, window_start)
);

CREATE TABLE access_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  user_id INT NOT NULL COMMENT 'buyer',
  book_id INT NOT NULL,
  code_hash CHAR(64) NOT NULL UNIQUE,
  code_last4 CHAR(4) NOT NULL,
  code_enc VARBINARY(255) NULL COMMENT 'AES-256-GCM plaintext, nulled once redeemed',
  status ENUM('active','redeemed','revoked') NOT NULL DEFAULT 'active',
  redeemed_by_user_id INT NULL,
  redeemed_at DATETIME NULL,
  revoked_at DATETIME NULL,
  revoked_reason VARCHAR(255) NULL,
  replaced_by_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_access_codes_order (order_id),
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (book_id) REFERENCES books(id)
);

CREATE TABLE audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NULL,
  user_id INT NULL,
  action VARCHAR(60) NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT NULL,
  detail JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO settings (`key`, `value`) VALUES
  ('shipping_charge_hardcopy', '0'),
  ('shipping_charge_digital', '0'),
  ('gst_percent_digital', '0'),
  ('gst_percent_hardcopy', '0'),
  ('gst_mode', 'inclusive');
