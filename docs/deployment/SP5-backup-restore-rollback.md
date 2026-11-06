# SP-5 backup, restore and rollback

Procedures only. None of this has been run on a production server (hosting is a separate final stage). Test the backup and restore once on the real host before go-live. Replace every `<placeholder>`; never put passwords on the command line in shared shell history (use a protected option file, for example `~/.my.cnf` with mode 600, or `--defaults-extra-file`).

What holds data that cannot be recreated:

| What | Where | Why it matters |
|---|---|---|
| MySQL database | the `DB_NAME` database | users, orders, payments, access codes (hashed and encrypted), refunds, commissions, audit log |
| Private book PDFs | `backend/storage/books/<id>/book.pdf` | the product itself; not in git; the rendered `pages/` cache can be rebuilt |
| Uploaded covers, previews, index files | `backend/public/uploads/` | not in git |
| `backend/.env` | server only | the secrets. `CODE_SECRET` and `JWT_SECRET` are needed to use a restored database: without `CODE_SECRET` unredeemed access codes cannot be shown or redeemed |

## 1. Database backup

```bash
mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 \
  --defaults-extra-file=<protected option file> <db_name> | gzip > vtee-<yyyymmdd-hhmm>.sql.gz
```

- `--single-transaction` takes a consistent snapshot of InnoDB tables without locking out customers.
- **Before every migration or deploy**, take a fresh named dump (`vtee-before-<change>.sql.gz`) in addition to the schedule.
- **Schedule**: nightly at a quiet hour, plus before each deploy. Keep at least 14 daily copies, then weekly copies for 3 months (adjust to the owner's retention decision; the database holds personal data, so the privacy text should state retention).
- **Off-server storage**: copy each dump to a different machine or provider account than the one running the database (object storage, a second server, or an encrypted external disk). A backup on the same disk is not a backup. Use a credential that can write but not delete, if the provider supports it.
- **Encryption**: encrypt before it leaves the server, for example `gpg --encrypt --recipient <backup key id>` or `age -r <public key>`. Keep the decryption key somewhere other than the server and the backup store. The dumps contain customer emails, addresses and phone numbers.
- **Check each run**: the command exited 0, the file is not near-empty, and `gunzip -c <file> | tail -n 3` ends with a `-- Dump completed` line. Alert on failure.
- Record the migration level with the dump (for example the output of `php scripts/check_config.php`, which lists migrations 001-008 as PASS/FAIL).

## 2. Private PDFs and uploads backup

```bash
tar -czf vtee-files-<yyyymmdd>.tar.gz -C <backend dir> storage/books public/uploads
```

- `storage/books/<id>/pages/` is a render cache and can be excluded (`--exclude='storage/books/*/pages'`); it is rebuilt on demand.
- Back up after any book upload or replacement, and at least weekly. Same rules as the database: off-server, encrypted, access restricted. The PDFs are the paid product.
- Keep the original source files of the books outside the server as well (the owner's own copies).
- Back up `backend/.env` separately into the owner's password manager or another secret store, never into the same place as the dumps.

## 3. Restore procedure

Restore into a **scratch database first**, verify, then (if needed) into the real one.

1. Fetch and decrypt the dump and the files archive on a safe machine.
2. Create an empty database and a user for it (not the production one):

   ```bash
   mysql -e "CREATE DATABASE vtee_restore_check CHARACTER SET utf8mb4;"
   gunzip -c vtee-<stamp>.sql.gz | mysql <options> vtee_restore_check
   ```

3. Verify the database:
   - the import finished without errors;
   - `SELECT COUNT(*) FROM orders;`, `payments`, `access_codes`, `users`, `books` look like the live numbers at backup time;
   - the newest `orders.created_at` and `payments` rows match the time of the backup;
   - point a copy of the app at it (`DB_NAME=vtee_restore_check`, same `CODE_SECRET`) and run `php scripts/check_config.php`: all migrations 001-008 PASS;
   - log in with a test account; open a paid order and an access code (admin code verify) to confirm the codes decrypt with the same `CODE_SECRET`.
4. Restore files: `tar -xzf vtee-files-<stamp>.tar.gz -C <backend dir>`; fix ownership to the PHP-FPM user; `check_config.php` must PASS the storage items. Open one book in the reader; a missing page cache re-renders on demand.
5. **Restoring over production** (only after the above): stop the API (or put up a maintenance page), keep a dump of the current state first (even if damaged), import, run `check_config.php`, start the API, run the post-restore reconciler by hand (`php scripts/reconcile_orders.php`) so payments and refunds that happened in Razorpay after the backup are recorded, and review `payment_events` and orders from the gap period by hand against the Razorpay dashboard.
6. Drop the scratch database when finished. Delete decrypted copies of dumps from working machines.

## 4. Application rollback

Keep the previous release available so a bad deploy can be undone in minutes.

- **Backend code**: deploy each release into its own directory (for example `releases/<tag>`) and point the live path (`current`) at it with a symlink. Rollback is switching the symlink back to the previous directory and reloading PHP-FPM (so opcache drops the old code). Keep `.env`, `storage/` and `public/uploads` outside the release directories (shared directories linked in), so a swap does not touch data.
- **Frontend**: keep the previous `dist/` folder next to the current one and swap them (rename or re-point). Remember the frontend is built per environment with `VITE_API_URL`; the old `dist/` points at the same API. Hosts that keep deploy history (Netlify, Cloudflare Pages) can roll back there.
- **PHP image**: the Dockerfile uses `php:8.4-fpm-trixie`. The previous image is kept as `vtee-php:8.2-trixie-rollback`. To roll back the runtime only, run the PHP service from that tag. The application runs on 8.2 as well as 8.4 only as far as the suites showed on 8.2 (the last 8.2 results are in `backend/TEST_RESULTS.md`); the code written for the finalization pass was tested on 8.4 only. Treat an image rollback as a short-term measure and prefer rolling back the whole release.
- After any rollback run `php scripts/check_config.php` and open the site, a book page and the admin login.
- **Database changes are not undone by swapping code.** If the new release ran migrations, check whether the old code still works with the new schema (migrations 003 to 008 are additive, so older code normally still runs) before deciding whether the database must also go back.

## 5. Migration rollback

The way back for each migration is `backend/migrations/ROLLBACK.md` (blocks for 008 down to 001, newest first, tested on a scratch database on 2026-11-05).

**Warnings (read before using it):**

- **Take a `mysqldump` first and keep it.** Every block deletes the columns, tables or rows its migration added: 004 removes every refund row; 003 removes orders' breakdown and fulfilment data, access codes, payment events and the audit log; 002 removes preview pages and archive flags.
- Stop the API before rolling back, and roll back strictly newest first; never skip one.
- There is no migration 009. The clawback-settlement migration was removed before release. If a database somewhere ran it, `ROLLBACK.md` has the two-column drop.
- Prefer restoring the pre-migration dump over running rollback blocks when the migration was applied minutes ago and no customer data has been written since.

## 6. What NOT to roll back once live payments exist

After the first real (live-mode) payment, the database is the record of money and entitlements. Do not:

- run the 003 or 004 rollback blocks, restore a dump from before a payment, or drop `orders`, `payments`, `payment_events`, `refunds`, `commissions`, `access_codes`, `book_access` or `audit_logs`. Customers who paid would lose their books and the business would lose its record of refunds and commissions; Razorpay and the database would no longer agree.
- change or lose `CODE_SECRET` (all unredeemed codes stop working) or `JWT_SECRET` (everybody is logged out; harmless but disruptive) as part of a rollback.
- replace the `.env` with an older copy that has a different `RAZORPAY_WEBHOOK_SECRET` or keys from another mode.
- switch back from live to test keys to "undo" a problem while real orders are in flight.

If a bad release must be reverted after live payments: roll back the **code** (section 4), keep the database, fix forward. If the database itself must be restored from a backup, follow section 3 step 5 (stop, keep the current state, restore, reconcile against Razorpay by hand) and tell the owner which orders fall in the gap.
