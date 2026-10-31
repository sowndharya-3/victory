-- Read-only pre-migration checks for SP-5 (migrations 003-008). Run against PRODUCTION after taking a backup
-- (mysqldump --single-transaction --routines vtee > vtee-before-sp5.sql) and BEFORE applying any migration:
--   mysql -u <user> -p vtee < backend/scripts/premigration_checks.sql
-- Every query must return an empty result. A non-empty result means a migration would fail half-way
-- (003 is several ALTER statements: one failing after another succeeded leaves the schema partly migrated
-- and the file cannot simply be re-run). Fix the data first; never edit an applied migration.
-- Only columns that exist before 003 are referenced, so the file runs on the pre-SP-5 schema too.

-- 003: UNIQUE uq_payments_rzp_order (razorpay_order_id) on the live payments table (NULLs are allowed to repeat)
SELECT 'payments.razorpay_order_id duplicate' AS problem, razorpay_order_id, COUNT(*) AS n, GROUP_CONCAT(id) AS ids
FROM payments WHERE razorpay_order_id IS NOT NULL GROUP BY razorpay_order_id HAVING n > 1;

-- 003: UNIQUE uq_payments_rzp_payment (razorpay_payment_id)
SELECT 'payments.razorpay_payment_id duplicate' AS problem, razorpay_payment_id, COUNT(*) AS n, GROUP_CONCAT(id) AS ids
FROM payments WHERE razorpay_payment_id IS NOT NULL GROUP BY razorpay_payment_id HAVING n > 1;

-- Orphans (foreign keys normally prevent these; they appear only if data was loaded with FOREIGN_KEY_CHECKS=0)
SELECT 'payments without order' AS problem, p.id FROM payments p LEFT JOIN orders o ON o.id = p.order_id WHERE o.id IS NULL;
SELECT 'orders without user' AS problem, o.id FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE u.id IS NULL;
SELECT 'orders without book' AS problem, o.id FROM orders o LEFT JOIN books b ON b.id = o.book_id WHERE b.id IS NULL;
SELECT 'book_access without user/book' AS problem, a.id FROM book_access a
  LEFT JOIN users u ON u.id = a.user_id LEFT JOIN books b ON b.id = a.book_id WHERE u.id IS NULL OR b.id IS NULL;
SELECT 'commissions without order' AS problem, c.id FROM commissions c LEFT JOIN orders o ON o.id = c.order_id WHERE o.id IS NULL;
SELECT 'referral_partners with missing application' AS problem, p.id FROM referral_partners p
  LEFT JOIN referral_applications a ON a.id = p.application_id WHERE p.application_id IS NOT NULL AND a.id IS NULL;

-- 008: UNIQUE on LOWER(TRIM(gmail)) of referral_partners
SELECT 'referral_partners gmail duplicate (case/space-insensitive)' AS problem, LOWER(TRIM(gmail)) AS g, COUNT(*) AS n, GROUP_CONCAT(id) AS ids
FROM referral_partners GROUP BY g HAVING n > 1;

-- 008: at most one pending/approved application per LOWER(TRIM(gmail))
SELECT 'open referral_applications gmail duplicate' AS problem, LOWER(TRIM(gmail)) AS g, COUNT(*) AS n, GROUP_CONCAT(id) AS ids
FROM referral_applications WHERE application_status IN ('pending', 'approved') GROUP BY g HAVING n > 1;
