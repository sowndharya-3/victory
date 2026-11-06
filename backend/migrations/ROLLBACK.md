# Migration rollback SQL

The migrations are one-shot and additive. This file is the way back, **newest first**. Every block is destructive
for the data in the columns/tables it removes: take a `mysqldump` first, stop the API, and roll back in this order
(never skip one). The newest migration (008) was tested on a scratch database; see "Tested" below.

Run a block with e.g.
`docker compose exec -T mysql mysql -u root -prootpass vtee < block.sql` (or paste it into a client).

## 009_commission_clawback_settled (removed)

009 was reverted before release (owner decision: no clawback settlement rules) and the file no longer exists.
It was only ever applied to the local dev database, which already had these two columns dropped. If another
database ran it, drop its columns:

```sql
ALTER TABLE commissions DROP COLUMN clawback_settled_at, DROP COLUMN clawback_reference;
```

`check_config.php` no longer looks for them; leaving them in place is harmless (nothing reads or writes them).

## 008_partner_email_unique

Loses nothing but the two unique guards (the generated columns are derived from `gmail`).

```sql
ALTER TABLE referral_applications DROP INDEX uq_open_application, DROP COLUMN open_gmail;
ALTER TABLE referral_partners DROP INDEX uq_partner_gmail, DROP COLUMN gmail_key;
```

## 007_commission_payable_at

Loses the "marked payable at" timestamps (statuses stay).

```sql
ALTER TABLE commissions DROP COLUMN payable_at;
```

## 006_audit_logs_entity_index

```sql
ALTER TABLE audit_logs DROP INDEX idx_audit_entity;
```

## 005_books_page_count

The reader recomputes the cached page count lazily.

```sql
ALTER TABLE books DROP COLUMN page_count;
```

## 004_refunds

Loses every refund row and the payout/reversal columns. `reversed` commissions have no place in the old enum, so
they are turned into `pending` first (decide with the business; this only exists to keep the ALTER from failing).

```sql
DROP TABLE refunds;
UPDATE commissions SET status = 'pending' WHERE status = 'reversed';
ALTER TABLE commissions
  DROP COLUMN paid_at,
  DROP COLUMN payout_reference,
  DROP COLUMN clawback_due,
  DROP COLUMN reversed_at,
  MODIFY COLUMN status ENUM('pending','payable','paid') NOT NULL DEFAULT 'pending';
```

## 003_orders_payments

Loses the order breakdown/fulfilment columns, payment gateway ids' uniqueness, access codes, payment events,
rate-limit counters and the audit log. Do not run on a database with real orders unless you mean it.

```sql
DROP TABLE access_codes;
DROP TABLE audit_logs;
DROP TABLE payment_events;
DROP TABLE rate_limits;
DELETE FROM settings WHERE `key` IN ('shipping_charge_hardcopy', 'shipping_charge_digital', 'gst_percent_digital', 'gst_percent_hardcopy', 'gst_mode');
ALTER TABLE book_access DROP COLUMN revoked_at;
ALTER TABLE payments
  DROP INDEX uq_payments_rzp_order,
  DROP INDEX uq_payments_rzp_payment,
  DROP COLUMN failure_reason;
ALTER TABLE orders
  ADD INDEX user_id (user_id),
  DROP INDEX uq_orders_tracking_code,
  DROP INDEX idx_orders_user_created,
  DROP INDEX idx_orders_status_created,
  DROP COLUMN paid_at,
  DROP COLUMN subtotal_amount,
  DROP COLUMN shipping_amount,
  DROP COLUMN gst_amount,
  DROP COLUMN gst_percent,
  DROP COLUMN gst_mode,
  DROP COLUMN fulfilment_status,
  DROP COLUMN courier,
  DROP COLUMN tracking_number,
  DROP COLUMN tracking_code,
  DROP COLUMN shipped_at,
  DROP COLUMN referral_code_snapshot,
  DROP COLUMN admin_flag;
```

(`ADD INDEX user_id` restores the index MySQL had created implicitly for the `user_id` foreign key and dropped when `idx_orders_user_created` covered it; without it the DROP fails with error 1553.)

## 002_book_management

Loses the archive flags, index-file paths and all preview-page rows.

```sql
DROP TABLE book_preview_pages;
ALTER TABLE books DROP COLUMN index_file_path, DROP COLUMN archived;
```

## 001_init

Drops everything (the whole schema). Child tables first because of the foreign keys.

```sql
DROP TABLE commissions;
DROP TABLE referral_mappings;
DROP TABLE hardcopy_addresses;
DROP TABLE book_access;
DROP TABLE payments;
DROP TABLE orders;
DROP TABLE referral_partners;
DROP TABLE referral_applications;
DROP TABLE settings;
DROP TABLE books;
DROP TABLE users;
DROP TABLE admins;
```

## Tested

On 2026-11-05, on an empty scratch database `vtee_scratch_rb`: 001 to 008 applied in order, the schema compared
equal to the dev database, the 008 block above ran cleanly (both unique keys and both generated columns gone,
all other tables untouched), 008 re-applied, and the database was dropped. See `backend/TEST_RESULTS.md`.
