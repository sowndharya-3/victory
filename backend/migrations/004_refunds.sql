-- SP-7 refunds (plan Task 16): refunds table + commission reversal/payout columns. Additive; one-shot like 001-003.
CREATE TABLE refunds (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  payment_id INT NOT NULL,
  requested_by_user_id INT NULL COMMENT 'NULL when an admin opened the refund',
  reviewed_by_admin_id INT NULL,
  status ENUM('requested','approved','processing','refunded','rejected','failed') NOT NULL DEFAULT 'requested',
  reason VARCHAR(500) NOT NULL,
  admin_note VARCHAR(500) NULL,
  amount DECIMAL(10,2) NOT NULL COMMENT 'full captured payment amount; never client-supplied',
  razorpay_refund_id VARCHAR(100) NULL,
  -- 1 while a refund for the order is live or done, NULL otherwise: UNIQUE(order_id, is_open) allows one.
  is_open TINYINT GENERATED ALWAYS AS (IF(status IN ('requested','approved','processing','refunded'), 1, NULL)) STORED,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_open_refund (order_id, is_open),
  UNIQUE KEY uq_refunds_rzp_refund (razorpay_refund_id),
  INDEX idx_refunds_status_created (status, created_at),
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (payment_id) REFERENCES payments(id)
);

ALTER TABLE commissions
  MODIFY COLUMN status ENUM('pending','payable','paid','reversed') NOT NULL DEFAULT 'pending',
  ADD COLUMN paid_at DATETIME NULL,
  ADD COLUMN payout_reference VARCHAR(100) NULL,
  ADD COLUMN clawback_due TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN reversed_at DATETIME NULL;
