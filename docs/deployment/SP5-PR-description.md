# SP-5 pull request description (draft)

**Base:** `master`.
**Head:** `sp5-orders-codes` is the baseline of this description (commit `93f8384` and earlier). The 27 finalization commits listed below (`git log 93f8384..sp5-finalize --oneline`) live on `sp5-finalize`.

> **PR source decision pending.** The owner has to choose whether `sp5-finalize` is pushed and used as the PR head (the PR then contains everything below), or whether the PR is opened from `sp5-orders-codes` first and the finalization commits follow separately. Nothing has been pushed or merged by this work.

## Summary

SP-5 adds orders and Razorpay payments, digital access codes, a secure page-by-page reader, hard-copy fulfilment and tracking, referral partners and commissions, and the admin panel. The finalization pass hardens it (security headers, client IP, refund reconciliation, PHP 8.4), applies the owner's business decisions (no general refunds, flat commission without hold or clawback rules), and adds the contact form and draft policy pages.

Not deployed. Not tested against real Razorpay. Live payments must stay disabled until the test-mode checklist passes.

## Finalization commits (27), grouped

### Security
- `ec0e83d` content security policy (Report-Only) and security headers for the static host (`public/_headers`, `.htaccess`)
- `757d0cc` API security headers (nginx: CSP `default-src 'none'`, nosniff, no-referrer, no-store)
- `20dec63` proxy-aware client IP for rate limits (`TRUSTED_PROXIES`, IPv6 /64 buckets)
- `a7b67e5` accept the Authorization header from Apache and FastCGI servers
- `74ec72b` production build fails when `VITE_API_URL` is missing or points at localhost
- `f96fd20` verify payment currency before marking an order paid

### Backend
- `31a1115` refund reconciliation: the cron reconciler finalises refunds whose webhook was lost, records dashboard refunds, flags partial or unmatched external refunds for an admin
- `cc43957` validate book title and prices on update
- `67ae562` admins can edit book category and prices
- `c2363dd` PHP image moved to `php:8.4-fpm-trixie`
- `c7067e2` contact form API (`POST /api/contact`): emails the admin over SMTP, honeypot, 5 per hour per IP, `502 email_failed` on failure, nothing stored
- `ddf90d3` customer refund-request endpoint removed
- `81d4ce4` commission payout hold removed
- `3536e54` commission clawback settlement added, then reverted by `9fb9308` (owner decision); migration 009 never shipped
- `9eebc85` docs: migration rollback SQL (`backend/migrations/ROLLBACK.md`, tested on a scratch database) and test results
- `e86f8a4` docs: test results and README notes

### Frontend
- `42705c8` footer section links work from every page
- `cb645f6` reader retries when a page is still rendering
- `8f4f0fa` admin edits book category and prices with inline field errors
- `426b13f` footer: dead social links and placeholder text removed, commission wording aligned
- `781ab43` four draft policy pages (`/terms`, `/privacy`, `/refund-policy`, `/digital-access`) from editable files in `src/content/policies`, linked from the footer
- `d0d6f26` no-refund notice on the book page and at checkout, with a required acknowledgement
- `babf112` contact form sends to the backend API: honeypot, inline errors, honest failure states
- `6877299` UI matches the backend contact error shape; removed refund-hold badge
- `e87b93a` admin clawback settlement UI, then removed by `18b6ab4`

### Decisions applied (owner)
- No general refunds for purchased books; no customer refund-request workflow (`ddf90d3`, `d0d6f26`, `781ab43`). Genuine exceptions (duplicate payment, debited-but-failed, legal cases) are refunded manually by an admin.
- Commission Rs 25 online + Rs 25 hard copy; no hold period (`81d4ce4`); no clawback or settlement rules (`9fb9308`, `18b6ab4`). The legacy `clawback_due` flag from migration 004 stays as a flag only.
- Contact form emails the admin; messages are not stored (`c7067e2`, `babf112`).
- Hosting is a separate final stage; nothing about hosting was decided or deployed.

## Documentation (this docs branch)

Updated: handover, pre-merge review, risk notes, frontend hosting, Razorpay checklist. New: environments guide, backup/restore/rollback guide, this description. All in `docs/deployment/`.

## Tests

From `backend/TEST_RESULTS.md` (PHP 8.4.27, Docker stack, `APP_ENV=development`, fake gateway), all with 0 failed:

- PHP suites: address_validator 14, admin_authz 99, client_ip 32, codes 60, commission 50, contact 39, jwt 6, middleware 14, money_pricing 14, order_fulfillment 31, race 1, reader 55, reconciler 26, refund 77, signature_gateway 54, upload_validator 3.
- Shell suites: http_test 23, book_test 52, order_test 289.
- `scripts/check_config.php` exits 0 (mail items WARN in dev).
- Migrations 001-008 apply from scratch; every rollback block in `ROLLBACK.md` was run newest first on a scratch database and the schema matched a fresh 001..N-1 database at each step.
- Contact API delivery verified against a local mail catcher.
- Frontend: `npm test` 191 tests in 34 files passing; `npm run build` passes with `VITE_API_URL` set.

## Not verified

- Real Razorpay test mode: not run, no credentials in the project. See [SP5-razorpay-test-checklist.md](SP5-razorpay-test-checklist.md) (every result row is "Not run").
- Razorpay's real Checkout popup, real webhook delivery, real refund timing, fees and settlement.
- Refund reconciliation against real Razorpay (tested with a scripted gateway only).
- Contact form with a real mail provider.
- Apache, nginx and Netlify/Cloudflare rules on a real host (Apache `.htaccess` checked only on a throwaway container).
- The Content-Security-Policy is Report-Only and has not been observed on live traffic.
- Any live deployment, HTTPS/HSTS, cron, backups, restore.

## Owner actions

1. Choose the PR source branch (see the note at the top).
2. Review and approve the text of the four draft policy pages (`src/content/policies`).
3. Provide Razorpay test keys and a staging environment; later, approve live keys.
4. Confirm shipping charge (U1) and GST (U2) with the accountant and set them in Admin, Settings.
5. Decide hosting (API on a server with HTTPS, Poppler, cron; frontend host), retention of stored Razorpay payloads, and the SMTP mailbox for the contact form.
6. Approve book descriptions and categories.
7. Keep the default of no hold on commissions in mind when paying partners: payout is manual, and a refunded order's commission is reversed or flagged.

## Deploy notes

Back up first, run `backend/scripts/premigration_checks.sql`, then migrations 003 to 008 in order. Run `php scripts/check_config.php`. Configuration: [SP5-environments.md](SP5-environments.md). Backups and rollback: [SP5-backup-restore-rollback.md](SP5-backup-restore-rollback.md). Do not use the repository `docker-compose.yml` in production.
