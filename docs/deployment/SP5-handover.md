# SP-5 handover: orders, Razorpay, access codes, reader, refunds, referrals, admin panel

Branch `sp5-orders-codes` (from `master` 459a7bc), plus the finalization work on branch `sp5-finalize` (see
[SP5-PR-description.md](SP5-PR-description.md)). Status: implemented, verified locally (fake gateway), **not deployed**.
Hosting is a separate final stage; nothing here has been run against production or real Razorpay. This document contains no secrets: every key below is a variable **name** only.

**Read [SP5-premerge-review.md](SP5-premerge-review.md) next.** It lists what the pre-merge hardening pass fixed, every
item still open before production (policy pages, security headers, lost-refund-webhook handling, watermark limits,
hosting), the owner decisions (U1-U8; the refund, commission and email decisions are now answered) and the Razorpay test-mode
checklist ([SP5-razorpay-test-checklist.md](SP5-razorpay-test-checklist.md), **not yet run**). Configuration:
[SP5-environments.md](SP5-environments.md). Backup, restore and rollback:
[SP5-backup-restore-rollback.md](SP5-backup-restore-rollback.md).
**Live payments stay disabled until the checklist has passed with Razorpay test credentials.** Section 12 below lists
the known issues; rows marked *Fixed* are resolved on the finalize branch.

## 1. Features

### Completed in SP-5

- **Checkout** (`/checkout/:bookId`): digital or hard copy, server-computed breakdown (price, shipping, GST, total; all
  configurable, default ₹0 / 0 % inclusive), Indian address form for hard copies, optional referral code
  (also captured from `?ref=` links), Razorpay Checkout. Amounts are never trusted from the browser.
- **Payments**: signature check plus a server-side re-fetch from Razorpay on `/verify`; signed, idempotent webhook
  (`payment.captured`, `payment.failed`, `refund.processed`, `refund.failed`); cron reconciler for lost webhooks,
  expiry of abandoned orders after 24 h and a 7-day sweep of failed/cancelled orders that were captured after all.
- **Digital access codes** (`XXXX-XXXX-XXXX-XXXX`): issued on payment, shown on the result page and in My Books,
  redeemed by any logged-in customer (gift-able); stored as HMAC + last 4, plaintext AES-GCM encrypted only until
  redeemed. Customer and admin reissue/revoke. Rate-limited redemption.
- **My Books** (`/my-books`): owned books with access status, pending redemptions with "Show my code", redeem box.
- **Secure reader** (`/read/:bookId`): server-rendered JPEG pages (Poppler) per authorised user, next/prev/go-to,
  arrow keys, swipe, watermark `Licensed to {email} · {book id}` tiled over the page, no PDF ever sent to the browser.
- **Hard copies**: tracking code `VTT-XXXXXXXX`, public `/track` page (status, courier, tracking number only), admin
  fulfilment `confirmed → processing → shipped (courier required) → out_for_delivery → delivered`.
- **Refunds**: no general refunds for purchased books (owner decision; a no-refund notice with a required
  acknowledgement is shown on the book page and at checkout). There is no customer refund-request route or screen.
  Genuine exceptions (duplicate payment, debited-but-failed, legal cases) are handled manually by an admin with
  "Refund this order" (full refunds via Razorpay). Legacy `requested` refund rows can still be approved or rejected in
  Admin. A lost `refund.processed` webhook, or a refund made in the Razorpay dashboard, is picked up by the cron
  reconciler. On completion digital access is revoked, hard-copy fulfilment is cancelled if not shipped and the
  commission is left exactly as it was (no reversal, no clawback); finalising a refund writes the audit row
  `refund.commission_unchanged`.
- **Referral partners**: public application form (`/referral/register`), admin approve/reject (creates `VTP-XXXXXX`
  code), activate/deactivate, edit; flat commission per paid referral order (default ₹25 digital / ₹25 hard copy),
  `pending → payable → paid` with payout reference. No hold period and no payout block while a refund is open
  (owner decision); no clawback, reversal or settlement rules (`clawback_due` is a legacy column that nothing sets or shows; the `reversed` status and columns are unused).
- **Admin panel** (`/admin`): dashboard, books (now with description editing), customers, orders (search, filters,
  detail, fulfilment, code verify/revoke/reissue, refund), refunds, referral partners, commissions, pricing and
  commission settings. Books: title, description, category and both prices are editable (with field-level errors). Every admin action is written to `audit_logs`.
- **Navigation and admin UI**: the header has a Track Order link; admin pages use a scoped admin stylesheet; archived books are hidden by default behind a "Show archived" toggle; the mobile menu and admin tables were verified at 390 and 820 px.
- **Customer account** (`/account`): register/login, order list with fulfilment status, log out.
- **Contact form** (`POST /api/contact`): the home-page form emails the admin over SMTP (honeypot field, 5 per hour per
  IP, `502 email_failed` if mail cannot be sent, messages are not stored).
- **Draft policy pages** (`/terms`, `/privacy`, `/refund-policy`, `/digital-access`): text lives in `src/content/policies`
  and is a draft awaiting owner review; footer links point to them.

### Retained from SP-4 (unchanged behaviour)

Public books list/detail payload (`id,title,description,category,coverUrl,onlinePrice,hardcopyPrice` +
`indexUrl,previewPages`), admin book CRUD and uploads, private PDF storage, JWT auth for customers and admins,
CORS locked to `FRONTEND_ORIGIN`, the marketing home page.

## 2. Issues found and fixed

### During implementation (from the task reviews, `.superpowers/sdd/2026-10-27-sp5-orders-razorpay/progress.md`)

| Task | Review findings fixed |
|---|---|
| 1 Migration 003 | Email outbox/mailpit dropped (email deferred); access codes, tracking code, audit log, `book_access.revoked_at` added |
| 2 Pricing | Clean; bad settings values later validated by the settings endpoint (Task 20) |
| 3 Gateway/signatures | Clean; empty webhook/checkout secret now fails closed (Task 11) |
| 4 Address/referrals | Buyer's real email used for self-referral check |
| 6 Fulfilment | Forced-overlap concurrency test; duplicate payment id flagged `duplicate_payment`; webhook payload kept on amount mismatch |
| 7 Verify/me orders | Admin token 403 on customer routes, non-string JSON 400, missing secret guard in tests |
| 8 Webhook | 500s now logged; empty secret rejected; unknown-order capture logged |
| 9 Codes/tracking | Reissue rules (gifted code or admin revoke needs an admin), look-alike normalisation, failed-only rate limit |
| 10 Reconciler | Captured money on failed/cancelled orders swept (7 days); non-zero exit on errors; `flock` in cron |
| 16 Refunds | Late/lost `refund.processed` webhook finalises the right refund; `refund.failed` handled |
| 17 Reader backend | Approved; hardening items carried (section 9) |
| 18 Reader frontend | No refetch / rate-limit burn on fast paging; held-key and single-fetch tests |
| 19 Admin backend | Code revoke decided under the order lock; cancelling an unshipped paid hard copy requires a refund (409 `refund_required`); control characters rejected in courier/tracking |
| 20 Partners/commissions | Control characters / NBSP / invalid UTF-8 rejected; duplicate partner gmail 409; 16 KB body cap (413) |
| 21 Admin frontend | Commission totals rendered; form errors visible app-wide; stale list guard; refund confirm step; full code never shown in search |

### During this final verification (separate commits on this branch)

| Commit | Defect (found in the real browser) |
|---|---|
| `79b7eee` | Covers and index file were loaded from the frontend origin (`/uploads/...` relative paths), so every cover was broken whenever frontend and API are on different hosts. Now resolved against `VITE_API_URL`. |
| `f318a3f` | Admin book edit only exposed the title; the API accepts a description. Description textarea added. |
| `a0d9c02` | Reader watermark (review minor M6): only 3-4 diagonal lines fell on the page, large areas uncovered. Now ~200 tiles, rows about 150 px apart, whole page covered (screenshot kept locally, not committed: it shows paid book content). |
| `a32b4c9` | Hard-copy result page promised "We will email you when it ships" although email is deferred. |
| `8e9a389` | Customers had no way to log out. |
| `aad2327` | Responsive: header nav overflowed between 769 and ~1250 px; 8 pages had no side gutter; book detail cover rendered at 1075 px; admin nav links ran together. |

## 3. Database migrations (apply in this exact order)

**Back up first** (`mysqldump --single-transaction --routines vtee > vtee-before-sp5.sql`) and keep the dump
(details: [SP5-backup-restore-rollback.md](SP5-backup-restore-rollback.md)). Run
`backend/scripts/premigration_checks.sql` first (every query must return zero rows).
All six files (003-008) are additive (new tables, new nullable/defaulted columns, one index): no `DROP`, no `DELETE`, no data
rewrite. Existing users, books (ids 1 and 2), uploads and PDFs are preserved. Each file is one-shot: re-running it fails
on "already exists"/duplicate column and changes nothing.

| Order | File | Purpose |
|---|---|---|
| 1 | `backend/migrations/003_orders_payments.sql` | `orders`: paid_at, breakdown (subtotal/shipping/GST, mode), fulfilment status, courier, tracking number/code, shipped_at, referral snapshot, admin_flag; `payments.failure_reason`; `book_access.revoked_at`; new tables `payment_events` (webhook idempotency), `rate_limits`, `access_codes`, `audit_logs` |
| 2 | `backend/migrations/004_refunds.sql` | `refunds` table (one open refund per order enforced by a unique key) and `commissions` payout/reversal columns (`paid_at`, `payout_reference`, `clawback_due`, `reversed_at`) |
| 3 | `backend/migrations/005_books_page_count.sql` | `books.page_count` (reader page-count cache) |
| 4 | `backend/migrations/006_audit_logs_entity_index.sql` | Index `(entity_type, entity_id)` on `audit_logs` for the admin order detail |
| 5 | `backend/migrations/007_commission_payable_at.sql` | `commissions.payable_at` |
| 6 | `backend/migrations/008_partner_email_unique.sql` | Unique partner email and one open application per email (generated columns `gmail_key`, `open_gmail`) |

```bash
mysql -u <db_user> -p <db_name> < backend/migrations/003_orders_payments.sql
# then 004, 005, 006, 007, 008 in that order
```

`001_init.sql` and `002_book_management.sql` are already applied on the live database (SP-4); do not re-run them.
There is no migration 009 (the clawback-settlement migration was removed before release). The way back for each
migration is `backend/migrations/ROLLBACK.md` (tested on a scratch database); read the warnings in
[SP5-backup-restore-rollback.md](SP5-backup-restore-rollback.md) first.

## 4. Modified files

Backend: `backend/src/routes.php`, `Middleware/ErrorHandler.php`, `Controllers/AdminBookController.php` (modified);
new `Controllers/` (Checkout, Order, Webhook, AccessCode, Track, Reader, Refund, Referral, ReferralApplication,
Admin{Order,Customer,Dashboard,Code,Refund,Referral,Commission}), `Orders/`, `Razorpay/`, `Codes/`, `Reader/`,
`Refunds/`, `Referral/`, `Support/{Money,RateLimiter,Settings}.php`; `scripts/reconcile_orders.php`; migrations 003-008;
`backend/Dockerfile` (php:8.4-fpm-trixie, adds `poppler-utils`), `backend/nginx/default.conf` (404 rule for `book.pdf`/`pages/` under uploads),
`backend/.env.example`, `backend/README.md` (full API reference), tests under `backend/tests/`.

Frontend: `src/App.jsx`, `src/api/{client,books,orders,admin,reader}.js`, `src/components/checkout/*`,
`src/components/reader/ReaderViewer.jsx`, `src/components/home/Header.jsx`, `src/context/SessionContext.jsx`,
`src/pages/{Account,BookDetail,Books,Checkout,MyBooks,OrderResult,Reader,ReferralRegister,TrackOrder}Page.jsx`,
`src/pages/admin/*`, `src/utils/{razorpay,referral,orderLabels}.js`, `src/styles/site.css`. Mock data
`src/data/books.js`, `src/data/referralPartners.js` removed. Full list: `git diff --stat 459a7bc..sp5-orders-codes`.

## 5. Environment variables (names only; values live in `backend/.env` on the server, never in git)

| Name | Notes |
|---|---|
| `APP_ENV` | `production` on the live server (refuses the fake gateway) |
| `RAZORPAY_GATEWAY` | `live` (default when unset); `fake` is dev/test only |
| `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET` | From the Razorpay dashboard (test keys first, then live) |
| `RAZORPAY_WEBHOOK_SECRET` | Same value as entered in the dashboard webhook; empty is rejected |
| `CODE_SECRET` | ≥ 32 random bytes; **rotating it invalidates every unredeemed code** |
| `JWT_SECRET` | ≥ 32 random bytes (already set for SP-4; keep the same value or every login expires) |
| `FRONTEND_ORIGIN` | Exact frontend origin for CORS, e.g. `https://<site-domain>` (one origin, no trailing slash) |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | MySQL connection |
| `TRUSTED_PROXIES` | Optional. Proxy IPs/CIDRs allowed to set `X-Forwarded-For` for rate limits |
| `CONTACT_TO_EMAIL`, `MAIL_FROM`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_SECURE` | Contact form mail (`tls` default, `ssl`, or `none` for a local mail catcher only) |
| `VITE_API_URL` | Frontend **build-time** variable: the public API base URL. The production build fails without it |

Names, purposes and validation rules per environment: [SP5-environments.md](SP5-environments.md). Transactional email
(order confirmation, code, shipping) is still not implemented; only the contact form sends mail.

## 6. Server deployment steps (PHP 8.4 + MySQL 8 + nginx)

1. **Packages**: PHP 8.4 FPM with `pdo_mysql`, `openssl`, `mbstring`, `json`; `poppler-utils` (`pdfinfo`, `pdftoppm`;
   check `pdftoppm -v`). `backend/Dockerfile` uses `php:8.4-fpm-trixie` (Debian 13). The previous image is kept as
   `vtee-php:8.2-trixie-rollback` for rollback.
2. **Code**: upload `backend/` (not `tests/`, not `.env.example` values). The **web root is `backend/public`**.
   `backend/storage/` must be **outside the web root** (it holds the full PDFs and rendered pages).
3. **Writable directories** (owned by the PHP-FPM user): `backend/storage/` (incl. `storage/books/{id}/pages/`) and
   `backend/public/uploads/`. Both are gitignored, so create them on first deploy if absent.
4. **PHP limits** (`backend/php/uploads.ini`): `upload_max_filesize = 50M`, `post_max_size = 55M`,
   `display_errors = Off`; log errors to a file.
5. **nginx** (see `backend/nginx/default.conf`): `root .../backend/public`; `client_max_body_size 50M`;
   `try_files $uri /index.php?$query_string`; and keep
   `location ~* ^/uploads/books/[^/]+/(book\.pdf|pages/) { return 404; }` plus the dot-file deny. HTTPS is required
   (Razorpay webhooks).
6. **PHP-FPM workers**: the reader renders pages synchronously (up to 30 s per first render of a page). Use at least
   `pm.max_children = 10`; the dev stack's 5 workers can be exhausted by a broken PDF (see section 9).
7. **Real client IP for rate limits**: limits key on `REMOTE_ADDR` unless `TRUSTED_PROXIES` is set. Behind a
   proxy/CDN/load balancer either configure nginx `real_ip_header X-Forwarded-For; set_real_ip_from <proxy ranges>;`
   (preferred; commented example in `backend/nginx/default.conf`) or set `TRUSTED_PROXIES`, otherwise every visitor
   shares one bucket (redeem 20/15 min per IP, track 10/min, referral validate 30/min, public referral application
   5/hour, contact form 5/hour). `X-Forwarded-For` is trusted only from the listed proxies. IPv6 visitors are
   bucketed per /64.
8. **Migrations**: section 3 (backup first).
9. **`.env`**: section 5, `APP_ENV=production`, `RAZORPAY_GATEWAY=live`; then `php scripts/check_config.php` must
   exit 0. Do not use the repository `docker-compose.yml` in production (see [SP5-environments.md](SP5-environments.md)).
10. **Cron** (every 15 minutes, never overlapping):
    ```cron
    */15 * * * * cd /path/to/backend && flock -n /tmp/vtee-reconcile.lock php scripts/reconcile_orders.php >> /var/log/vtee-reconcile.log 2>&1
    ```
    Exit 0 ok, 2 some orders errored (see log), 1 fatal. It also finalises refunds whose webhook was lost, flags
    refunds made outside the app, and purges old `rate_limits` rows.
11. **Admin user**: `php scripts/seed_admin.php <username> <strong-password>` on the server (≥ 16 random chars; never
    commit or email it; shell history: prefix with a space or clear it). Admin URL: `https://<site-domain>/admin`.

## 7. Frontend build and upload

```bash
npm ci && VITE_API_URL=https://<live-api-url> npm run build
```

The build fails if `VITE_API_URL` is missing or points at localhost. Upload the contents of `dist/` (including `.htaccess`) to the site's web root. The SPA fallback for deep links
(`/admin`, `/my-books`, `/read/2`, `/track`) ships in the build for Apache (`.htaccess`) and Netlify/Cloudflare
(`_redirects`); nginx and the rest: see [frontend-hosting.md](frontend-hosting.md). `FRONTEND_ORIGIN` on the API
must equal this site's origin exactly.

## 8. Loading the two books through the live Admin Panel

Only if they are not already on the live site (check `/books` first; **do not create duplicates**). Use
**Admin → Books → Add Book**, then the file buttons in the row, then **Publish**. Saved local copies (dev stack):

| Book | Title | Prices | Cover | Index | Full PDF (private) |
|---|---|---|---|---|---|
| 1 | போக்சோ சட்டம் (category "Law (Tamil)") | ₹99 / ₹250 | `backend/public/uploads/books/1/cover.jpg` | `backend/public/uploads/books/1/index.pdf` | `backend/storage/books/1/book.pdf` (169 pages) |
| 2 | POCSO Act: An Easy Complete Guide for All ("Law (English)") | ₹99 / ₹250 | `backend/public/uploads/books/2/cover.jpg` | `backend/public/uploads/books/2/index.pdf` | `backend/storage/books/2/book.pdf` (176 pages) |

Title, description, category and both prices are editable in the admin UI (the API validates them: non-empty title,
prices above zero for published books). Description/category text needs the owner's approval (section 11).

## 9. Razorpay dashboard webhook

Settings → Webhooks → Add: URL `https://<live-api-url>/api/webhooks/razorpay`; secret = `RAZORPAY_WEBHOOK_SECRET`;
active events **`payment.captured`, `payment.failed`, `refund.processed`, `refund.failed`**. Enable auto-capture.
Do it first in test mode with test keys, run one digital and one hard-copy purchase and one admin exception refund,
then switch to live keys and a live-mode webhook (only with the owner's approval).

## 10. Testing results

Full detail: `backend/TEST_RESULTS.md` (last section: owner decisions pass, PHP 8.4.27, Docker stack, fake gateway,
`APP_ENV=development`). All suites: 0 failed.

- Backend PHP suites: address_validator 14, admin_authz 99, client_ip 32, codes 60, commission 50, contact 39, jwt 6,
  middleware 14, money_pricing 14, order_fulfillment 31, race 1, reader 55, reconciler 26, refund 77,
  signature_gateway 54, upload_validator 3. Shell suites: http_test 23, book_test 52, order_test 289.
  `scripts/check_config.php` exits 0 (mail items WARN in dev).
- Frontend: `npm test` 192 tests in 34 files, `npm run build` ok (with `VITE_API_URL` set).
- Migrations 001-008 applied from scratch and every rollback block run newest first on a scratch database.
- Earlier real-browser runs (Playwright/Chromium, fake gateway, both real books) passed; the later finalization changes
  were covered by the automated suites above.
- **Not run**: real Razorpay test mode (no keys in the project; see the checklist, all rows "Not run"); Razorpay's own
  Checkout popup (browser runs used a stub `checkout.js`); real SMTP delivery to a production mailbox; live
  deployment; the Apache/nginx/Netlify host rules on a real host.

## 11. Business decisions (owner)

Confirmed by the owner (applied in code; do not treat as open):

| Topic | Decision |
|---|---|
| Refunds | No general refunds for purchased books. No customer refund-request workflow (route and screen removed). Genuine exceptions (duplicate payment, debited-but-failed, legal cases) are handled manually by an admin with an admin refund. This replaces the earlier D1-D10 refund and cancellation options. |
| Commission | Rs 25 for an online (digital) order and Rs 25 for a hard-copy order. No hold period. Admin-approved exception refunds do not reverse or claw back commission: commission statuses are untouched and an audit row `refund.commission_unchanged` is written. `clawback_due` (migration 004) is a legacy column that nothing sets or shows; `reversed` status and columns stay unused. No migration 009. |
| Contact | The contact form emails the admin (`POST /api/contact`); enquiries are not stored in the database (email only) and a delivery failure is shown honestly to the visitor. |
| PR source | `sp5-finalize` is the PR head, base `master`. |
| Policy pages | Remain drafts until business approval. |
| Razorpay live credentials | Reserved for the final production deployment stage; the real test-mode run waits for `rzp_test_` keys in the staging `.env`. |
| Flag cleanup | No admin "clear flag" action is being built now; plan documented in [SP5-flag-cleanup-plan.md](SP5-flag-cleanup-plan.md). |
| Dev test data | Cleanup waits for owner approval (backup exists outside the repository). |
| Homepage | The "32+ Years Experience" claim and the "30 years in universities + 2 years company experience" text were removed. |
| Hosting | A separate final stage; not decided or started. |

Still open:

| # | Decision | Current default |
|---|---|---|
| U1 | Shipping charge for hard copies (flat/free/per state) | Rs 0 (Admin, Settings) |
| U2 | GST rates digital/printed, inclusive or exclusive, GST on shipping, GST invoice | 0 %, inclusive |
| U6 | Razorpay account: KYC, test + live keys, webhook secret, payment methods, auto-capture | Not provided |
| U7 | Hosting: HTTPS API URL, Poppler install, cron, PHP-FPM workers, supported base image | Not started |
| - | Review and approval of the four draft policy pages (text in `src/content/policies`) | Draft |
| - | Approval of book descriptions and categories | Current text on books 1 and 2 |
| - | Unverified homepage claims | Removed on the owner's instruction: world-class, retired professors, 24x7 availability, free one-to-one counselling, and the founder titles (Founder Chairman, PhD in Education). The business can re-add any claim it later verifies |
| - | Transactional email (order confirmation, code, shipping) | Not implemented; nothing is emailed about orders; the UI does not promise email |
| U8 | Guest checkout | Login required |
| - | Retention of `payment_events.payload` (personal data) and the privacy text (DPDP Act 2023) | Not decided |

## 12. Known issues

| Severity | Issue | Note |
|---|---|---|
| *Fixed* | Base image was EOL Debian bullseye | `php:8.4-fpm-trixie`; MySQL 8.0 end-of-life still needs a plan for the production host |
| *Fixed* | Reader renderer could tie up FPM workers; no size cap; non-atomic re-upload; spoofable page count | Lock deadline, resource limits, atomic upload, anchored parsing. Still set `pm.max_children >= 10` |
| *Fixed* | Duplicate-partner race | Migration 008 (run `backend/scripts/premigration_checks.sql` first) |
| *Fixed* | Lost `refund.processed` webhook and dashboard refunds were ignored | Refund reconciliation in the cron script; unmatched or partial external refunds are flagged for an admin |
| *Fixed* | Book category and prices not editable; no validation on update | Editable in Admin with validation; currency of the payment is verified |
| *Fixed* | `API_BASE_URL` fell back to localhost silently | Production build fails without `VITE_API_URL` |
| *Fixed* | No security headers on the API / no CSP | API headers in `backend/nginx/default.conf`; CSP in Report-Only mode via `public/_headers` and `public/.htaccess` |
| *Fixed* | `Authorization` header stripped on Apache/FastCGI | Accepted from the server variables Apache and FastCGI use; `backend/public/.htaccess` ships |
| Low | Admin cancel after shipping moves no money; a refund must be issued separately | Document for admins |
| Low | A deactivated partner still earns on orders created before deactivation | Business rule; commissions are never clawed back by decision |
| Low | `/api/track/:code` returns courier + tracking number to anyone holding the code | By design; random 8-char codes, rate limited |
| Low | `amount_mismatch` orders stay pending and are re-polled every cron run | Admin resolves manually (README) |
| Low | Refund list and partner list unpaginated; customer detail shows newest 200 orders | Paginate when volume grows |
| Low | Refund failure overwrites the admin note | Keep note history if needed |
| Low | ErrorHandler logs PDOException messages (may include values such as an email in a duplicate key) | Sanitise logged messages |
| Low | Reader index iframe and all 5xx on `/access` shown as "not available yet"; no customer reissue button | UI polish |
| Low | No partner edit UI (API exists) | UI follow-up |
| Info | Test scripts assume a POSIX shell with `openssl`; on Windows run them from Git Bash | n/a |
