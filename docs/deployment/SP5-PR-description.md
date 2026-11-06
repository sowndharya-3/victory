# SP-5 pull request description (draft)

**Base:** `master`. **Head (source branch):** `sp5-finalize` (owner decision). It holds the SP-5 baseline (`93f8384` and earlier) plus the finalization commits listed below (33 implementation commits, the homepage-claims removal, and the documentation commits) (`git log 93f8384..sp5-finalize --oneline`).

**Do not merge or deploy. Live payments are disabled.** Nothing has been pushed or merged by this work.

## Summary

SP-5 adds orders and Razorpay payments, digital access codes, a secure page-by-page reader, hard-copy fulfilment and tracking, referral partners and commissions, and the admin panel. The finalization pass hardens it (security headers, client IP, refund reconciliation, PHP 8.4), applies the owner's business decisions (no general refunds; flat Rs 25 / Rs 25 commission with no hold, no clawback and no reversal), and adds the contact form, draft policy pages and UI fixes.

Not deployed. Not tested against real Razorpay. Live payments must stay disabled until the test-mode checklist passes.

## Finalization commits, grouped

### Security
- `74ec72b` production build fails when `VITE_API_URL` is missing
- `a7b67e5` accept the Authorization header from Apache and FastCGI
- `20dec63` proxy-aware client IP for rate limits (`TRUSTED_PROXIES`, IPv6 /64 buckets)
- `ec0e83d` Content-Security-Policy (Report-Only) and security headers for the static host
- `757d0cc` API security headers
- `f96fd20` verify payment currency before marking an order paid

### Backend
- `cc43957` validate book title and prices on update
- `67ae562` admins can edit book category and prices
- `31a1115` refund reconciliation for lost webhooks and dashboard refunds
- `c2363dd` PHP image on `php:8.4-fpm-trixie`
- `3536e54` commission clawback settlement, reverted by `9fb9308` (never shipped; no migration 009)
- `ddf90d3` customer refund-request endpoint removed (refunds are admin-only exceptions)
- `81d4ce4` commission payout hold removed
- `c7067e2` contact form API (`POST /api/contact`): emails the admin, nothing stored
- `f624688` refunds no longer reverse or claw back commissions; finalising a refund writes audit row `refund.commission_unchanged`

### Frontend
- `42705c8` footer section links work from every page
- `cb645f6` reader retries when a page is still rendering
- `8f4f0fa` admin edits book category and prices with inline field errors
- `e87b93a` admin clawback UI, removed by `18b6ab4`
- `426b13f` dead social links and placeholder footer text removed, commission wording aligned
- `781ab43` four draft policy pages from editable files in `src/content/policies`
- `d35a64a` unverified homepage claims removed (world-class wording, retired professors, 24x7 support, free one-to-one counselling, founder titles)
- `d0d6f26` no-refund notice on book page and checkout with required acknowledgement
- `babf112` contact form sends to the backend API with honeypot, inline errors, honest failure states
- `6877299` UI matches the backend contact error shape; refund-hold badge dropped
- `d2310d7` mobile menu no longer wraps into a second column; admin books table scrolls inside its wrapper
- `cab2b79` Track Order link in the header
- `9c61ea7` scoped admin stylesheet; archived books hidden by default (Show archived toggle)
- `7d346f4` admin refund copy no longer mentions commission reversal

### Decisions applied
- `1e4f95a` unverified "32+ Years Experience" claim (and the "30 years in universities + 2 years company experience" text) removed from the homepage

### Tests and docs
- `e86f8a4` test results and README notes for the finalize pass
- `9eebc85` migration rollback SQL (tested on a scratch database) and test results for the owner-decisions pass

## Decisions applied (owner)
- `sp5-finalize` is the PR head, base `master`.
- No general refunds for purchased books and no customer refund-request workflow. Genuine exceptions (duplicate payment, debited-but-failed, legal cases) are refunded manually by an admin only.
- Refunds do not reverse or claw back commission. Commission statuses are untouched. `clawback_due` is a legacy column that nothing sets or shows; the `reversed` status and columns stay unused. No migration 009. No payout hold. Rs 25 online and Rs 25 hard copy.
- Contact enquiries are emailed to the admin and not stored in the database; a failure is shown honestly to the visitor.
- Policy pages stay drafts until business approval.
- Razorpay live credentials are reserved for the final production deployment stage. The real test-mode run waits for `rzp_test_` keys in the staging `.env`.
- An admin "clear flag" action is not being built now; the cleanup plan is documented in [SP5-flag-cleanup-plan.md](SP5-flag-cleanup-plan.md).
- Dev test data cleanup waits for owner approval (a backup exists outside the repository).
- Hosting is a separate final stage; nothing about hosting was decided or deployed.

## Documentation

All in `docs/deployment/`: handover, pre-merge review, risk notes, frontend hosting, Razorpay checklist, environments guide, backup/restore/rollback guide, flag cleanup plan, this description.

## Tests

From `backend/TEST_RESULTS.md` (PHP 8.4.27, Docker stack, `APP_ENV=development`, fake gateway), all with 0 failed:

- PHP: 16 suites pass. address_validator 14, admin_authz 99, client_ip 32, codes 60, commission 50, contact 39, jwt 6, middleware 14, money_pricing 14, order_fulfillment 31, race 1, reader 55, reconciler 26, refund 77, signature_gateway 54, upload_validator 3.
- Shell: order_test.sh 289, book_test.sh 52, http_test.sh 23.
- `scripts/check_config.php` exits 0 (mail items WARN in dev).
- Migrations 001-008 apply from scratch; every rollback block in `ROLLBACK.md` was run newest first on a scratch database and the schema matched a fresh 001..N-1 database at each step.
- Contact API delivery verified against a local mail catcher.
- Frontend: `npm test` 192 tests in 34 files passing; `npm run build` passes with `VITE_API_URL` set.
- Browser E2E at 1280, 820 and 390 px on the fake gateway (mobile menu and admin tables checked at 390 and 820). Evidence is kept outside git by the owner and is not linked here.

## Not verified

- Real Razorpay test mode: not run, no `rzp_test_` keys yet. The real Razorpay Checkout popup is NOT TESTED. See [SP5-razorpay-test-checklist.md](SP5-razorpay-test-checklist.md) (every result row is "Not run").
- Real webhook delivery, real refund timing, fees and settlement; refund reconciliation against real Razorpay (scripted gateway only).
- Contact form with a real mail provider.
- Apache, nginx and Netlify/Cloudflare rules on a real host (Apache `.htaccess` checked only on a throwaway container).
- The Content-Security-Policy is Report-Only and has not been observed on live traffic.
- Any live deployment, HTTPS/HSTS, cron, backups, restore.
- Homepage claims still awaiting business confirmation (see the owner list in [SP5-premerge-review.md](SP5-premerge-review.md)).

## Owner actions

1. Review and approve the text of the four draft policy pages (`src/content/policies`).
2. Place `rzp_test_` keys in the staging `.env` so the test-mode checklist can run; live keys only at the final production stage.
3. Confirm shipping charge (U1) and GST (U2) with the accountant and set them in Admin, Settings.
4. Decide hosting (API on a server with HTTPS, Poppler, cron; frontend host), retention of stored Razorpay payloads, and the SMTP mailbox for the contact form.
5. Re-add a removed homepage claim only after the business verifies it. Removed so far: world-class, retired professors, 24x7 availability, free one-to-one counselling, and the founder titles (Founder Chairman, PhD in Education).
6. Approve book descriptions and categories.
7. Approve (or decline) the dev test data cleanup.
8. Remember when paying partners: payout is manual, there is no hold, and a refunded order's commission stays as it was (audit row `refund.commission_unchanged` lists these).

## Deploy notes

Back up first, run `backend/scripts/premigration_checks.sql`, then migrations 003 to 008 in order. Run `php scripts/check_config.php`. Configuration: [SP5-environments.md](SP5-environments.md). Backups and rollback: [SP5-backup-restore-rollback.md](SP5-backup-restore-rollback.md). Do not use the repository `docker-compose.yml` in production.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
