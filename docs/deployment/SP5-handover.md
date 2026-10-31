# SP-5 handover: orders, Razorpay, access codes, reader, refunds, referrals, admin panel

Branch `sp5-orders-codes` (from `master` 459a7bc). Status: implemented, verified locally (fake gateway), **not deployed**.
Live deployment and the live-site check need hosting access (U7) and the owner's approval; nothing here has been run
against production. This document contains no secrets: every key below is a variable **name** only.

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
- **Refunds**: customer refund request (API), admin approve/reject or admin-initiated refund; full refunds via
  Razorpay; on completion digital access is revoked, hard-copy fulfilment cancelled if not shipped, commission reversed
  (or `clawback_due` if already paid out).
- **Referral partners**: public application form (`/referral/register`), admin approve/reject (creates `VTP-XXXXXX`
  code), activate/deactivate, edit; flat commission per paid referral order (default ₹25 digital / ₹25 hard copy),
  `pending → payable → paid` with payout reference, hold while a refund is open.
- **Admin panel** (`/admin`): dashboard, books (now with description editing), customers, orders (search, filters,
  detail, fulfilment, code verify/revoke/reissue, refund), refunds, referral partners, commissions, pricing and
  commission settings. Every admin action is written to `audit_logs`.
- **Customer account** (`/account`): register/login, order list with fulfilment status, log out (added in this task).

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
| 20 Partners/commissions | Payout held with 409 `refund_open`; control characters / NBSP / invalid UTF-8 rejected; duplicate partner gmail 409; 16 KB body cap (413) |
| 21 Admin frontend | Commission totals rendered; form errors visible app-wide; stale list guard; refund confirm step; full code never shown in search |

### During this final verification (separate commits on this branch)

| Commit | Defect (found in the real browser) |
|---|---|
| `79b7eee` | Covers and index file were loaded from the frontend origin (`/uploads/...` relative paths), so every cover was broken whenever frontend and API are on different hosts. Now resolved against `VITE_API_URL`. |
| `f318a3f` | Admin book edit only exposed the title; the API accepts a description. Description textarea added. |
| `a0d9c02` | Reader watermark (review minor M6): only 3-4 diagonal lines fell on the page, large areas uncovered. Now ~200 tiles, rows about 150 px apart, whole page covered (screenshot `reader-watermark-full-page.jpg`). |
| `a32b4c9` | Hard-copy result page promised "We will email you when it ships" although email is deferred. |
| `8e9a389` | Customers had no way to log out. |
| `aad2327` | Responsive: header nav overflowed between 769 and ~1250 px; 8 pages had no side gutter; book detail cover rendered at 1075 px; admin nav links ran together. |

## 3. Database migrations (apply in this exact order)

**Back up first** (`mysqldump --single-transaction --routines vtee > vtee-before-sp5.sql`) and keep the dump.
All five files are additive (new tables, new nullable/defaulted columns, one index): no `DROP`, no `DELETE`, no data
rewrite. Existing users, books (ids 1 and 2), uploads and PDFs are preserved. Each file is one-shot: re-running it fails
on "already exists"/duplicate column and changes nothing.

| Order | File | Purpose |
|---|---|---|
| 1 | `backend/migrations/003_orders_payments.sql` | `orders`: paid_at, breakdown (subtotal/shipping/GST, mode), fulfilment status, courier, tracking number/code, shipped_at, referral snapshot, admin_flag; `payments.failure_reason`; `book_access.revoked_at`; new tables `payment_events` (webhook idempotency), `rate_limits`, `access_codes`, `audit_logs` |
| 2 | `backend/migrations/004_refunds.sql` | `refunds` table (one open refund per order enforced by a unique key) and `commissions` payout/reversal columns (`paid_at`, `payout_reference`, `clawback_due`, `reversed_at`) |
| 3 | `backend/migrations/005_books_page_count.sql` | `books.page_count` (reader page-count cache) |
| 4 | `backend/migrations/006_audit_logs_entity_index.sql` | Index `(entity_type, entity_id)` on `audit_logs` for the admin order detail |
| 5 | `backend/migrations/007_commission_payable_at.sql` | `commissions.payable_at` |

```bash
mysql -u <db_user> -p <db_name> < backend/migrations/003_orders_payments.sql
# then 004, 005, 006, 007 in that order
```

`001_init.sql` and `002_book_management.sql` are already applied on the live database (SP-4); do not re-run them.

## 4. Modified files

Backend: `backend/src/routes.php`, `Middleware/ErrorHandler.php`, `Controllers/AdminBookController.php` (modified);
new `Controllers/` (Checkout, Order, Webhook, AccessCode, Track, Reader, Refund, Referral, ReferralApplication,
Admin{Order,Customer,Dashboard,Code,Refund,Referral,Commission}), `Orders/`, `Razorpay/`, `Codes/`, `Reader/`,
`Refunds/`, `Referral/`, `Support/{Money,RateLimiter,Settings}.php`; `scripts/reconcile_orders.php`; migrations 003-007;
`backend/Dockerfile` (adds `poppler-utils`), `backend/nginx/default.conf` (404 rule for `book.pdf`/`pages/` under uploads),
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
| `VITE_API_URL` | Frontend **build-time** variable: the public API base URL |

These are the only variables the code reads (`grep getenv` over `backend/src`, `backend/scripts`; `import.meta.env`
over `src`). No SMTP variables: email is deferred.

## 6. Server deployment steps (PHP 8.2 + MySQL 8 + nginx)

1. **Packages**: PHP 8.2 FPM with `pdo_mysql`, `openssl`, `mbstring`, `json`; `poppler-utils` (`pdfinfo`, `pdftoppm`;
   check `pdftoppm -v`). The dev `backend/Dockerfile` pins Debian bullseye (EOL, uses archive.debian.org): use a
   supported base (bookworm) for a production image.
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
7. **Real client IP for rate limits**: limits key on `REMOTE_ADDR`. Behind a proxy/CDN/load balancer configure nginx
   `real_ip_header X-Forwarded-For; set_real_ip_from <proxy ranges>;` (or the CDN's header) so `REMOTE_ADDR` is the
   visitor, otherwise every visitor shares one bucket (redeem 20/15 min per IP, track 10/min, referral validate 30/min,
   **public referral application 5/hour**). Never trust `X-Forwarded-For` from the open internet. IPv6 visitors get
   one bucket per address; an attacker with a /64 can rotate addresses (consider keying IPv6 on /64 later).
8. **Migrations**: section 3 (backup first).
9. **`.env`**: section 5, `APP_ENV=production`, `RAZORPAY_GATEWAY=live`.
10. **Cron** (every 15 minutes, never overlapping):
    ```cron
    */15 * * * * cd /path/to/backend && flock -n /tmp/vtee-reconcile.lock php scripts/reconcile_orders.php >> /var/log/vtee-reconcile.log 2>&1
    ```
    Exit 0 ok, 2 some orders errored (see log), 1 fatal. It also purges old `rate_limits` rows.
11. **Admin user**: `php scripts/seed_admin.php <username> <strong-password>` on the server (≥ 16 random chars; never
    commit or email it; shell history: prefix with a space or clear it). Admin URL: `https://<site-domain>/admin`.

## 7. Frontend build and upload

```bash
VITE_API_URL=https://<live-api-url> npm ci && npm run build
```

Upload the contents of `dist/` to the site's web root. Configure an SPA fallback so deep links (`/admin`,
`/my-books`, `/read/2`, `/track`) serve `index.html` (nginx `try_files $uri /index.html;`, or an `.htaccess`
rewrite on Apache/Hostinger). `FRONTEND_ORIGIN` on the API must equal this site's origin exactly.

## 8. Loading the two books through the live Admin Panel

Only if they are not already on the live site (check `/books` first; **do not create duplicates**). Use
**Admin → Books → Add Book**, then the file buttons in the row, then **Publish**. Saved local copies (dev stack):

| Book | Title | Prices | Cover | Index | Full PDF (private) |
|---|---|---|---|---|---|
| 1 | போக்சோ சட்டம் (category "Law (Tamil)") | ₹99 / ₹250 | `backend/public/uploads/books/1/cover.jpg` | `backend/public/uploads/books/1/index.pdf` | `backend/storage/books/1/book.pdf` (169 pages) |
| 2 | POCSO Act: An Easy Complete Guide for All ("Law (English)") | ₹99 / ₹250 | `backend/public/uploads/books/2/cover.jpg` | `backend/public/uploads/books/2/index.pdf` | `backend/storage/books/2/book.pdf` (176 pages) |

Category is not editable in the admin UI yet (title, description and prices are); set it via
`PUT /api/admin/books/:id` if needed. Description/category text needs the owner's approval (section 11).

## 9. Razorpay dashboard webhook

Settings → Webhooks → Add: URL `https://<live-api-url>/api/webhooks/razorpay`; secret = `RAZORPAY_WEBHOOK_SECRET`;
active events **`payment.captured`, `payment.failed`, `refund.processed`, `refund.failed`**. Enable auto-capture.
Do it first in test mode with test keys, run one digital and one hard-copy purchase and one refund, then switch to
live keys and a live-mode webhook.

## 10. Testing results

Full detail and screenshot list: `backend/TEST_RESULTS.md` (section "Final verification").

- Automated: all 14 PHP suites, `order_test.sh` 277/277, `book_test.sh` 25/25, `http_test.sh` 23/23, frontend
  144/144, `npm run build` ok, zero failures.
- Real browser (Playwright/Chromium, local stack, fake gateway, both real books): flows (a)-(g) and the form-error
  checks passed; responsive 1280/820/390 px with no horizontal scroll after the fixes in section 2.
- **Not run**: real Razorpay test mode (keys required, U6); Razorpay's own Checkout popup (the browser runs used a
  stub `checkout.js` and server-signed fake payments); a customer refund-request UI (none exists, the request was made
  through the API); live deployment.

## 11. Open business decisions (owner)

| # | Decision | Current default |
|---|---|---|
| U1 | Shipping charge for hard copies (flat/free/per state) | ₹0 (Admin → Settings) |
| U2 | GST rates digital/printed, inclusive or exclusive, GST on shipping, GST invoice | 0 %, inclusive |
| U3 | Refund policy: who, time windows, opened reader forfeits refund, returns, shipping refunded | Admin discretion, full refunds only |
| U4 | Commission hold period before payable, first purchase only, payout frequency | No hold (but blocked while a refund is open), manual payout |
| U6 | Razorpay account: KYC, test + live keys, webhook secret, payment methods, auto-capture | Not provided |
| U7 | Hosting: HTTPS API URL, Poppler install, cron, PHP-FPM workers, supported base image | Dev only |
| - | Approval of book descriptions and categories | Current text on books 1 and 2 |
| - | Email notifications (order confirmation, code, shipping) | Deferred; nothing is emailed; the UI no longer promises email |

## 12. Known issues / pre-deploy hardening

| Severity | Issue | Suggested fix |
|---|---|---|
| High (prod) | Base image `php:8.2-fpm-bullseye` is EOL (packages from archive.debian.org) | Build on bookworm; re-check `poppler-utils` |
| Medium | Reader renderer: blocking `flock` with no timeout + 30 s render; a corrupt PDF can tie up FPM workers (up to ~150 s with 5 workers) | `LOCK_NB` + deadline, remember failed pages; raise `pm.max_children` |
| Medium | No render size cap (huge page sizes render huge JPEGs) | `pdftoppm -scale-to 1600` or a page-size check |
| Medium | Re-uploading a PDF is not atomic; stale `page_count` race | Temp file + rename; `UPDATE ... WHERE page_count IS NULL` |
| Medium | No customer refund-request UI (API exists: `POST /api/me/orders/:id/refund-request`) | Add a "Request refund" button on Account orders, or document that customers contact support and admins use "Refund this order" |
| Low | `pdfinfo` "Pages" regex takes the first match (a crafted Title could spoof it) | Use the last match / anchor with `\z` |
| Low | Admin cancel after shipping moves no money; refund must be issued separately | Document for admins; optional prompt to refund |
| Low | Residual payout race: a refund opened just after the hold check leaves the commission `paid` + `clawback_due` | Re-check under a lock, or accept and handle clawback manually |
| Low | Duplicate-partner race (no unique index on partner gmail / application id) | Add UNIQUE indexes in a migration 008 |
| Low | Deactivated partner still earns on orders created before deactivation; `clawbackDue` never clears | Business rule (U4); add "clawback settled" action |
| Low | `/api/track/:code` returns courier + tracking number to anyone holding the code (by design) | Accepted; codes are random 8 chars, rate limited |
| Low | `amount_mismatch` orders stay pending and are re-polled every cron run | Admin resolves manually (README) |
| Low | Refund list and partner list unpaginated; customer detail shows newest 200 orders | Paginate when volume grows |
| Low | Refund failure overwrites the admin note; no refund-request rate limit | Keep note history; add a limit |
| Low | ErrorHandler logs PDOException messages (may include values such as an email in a duplicate key) | Sanitise logged messages |
| Low | Reader index iframe and all 5xx on `/access` shown as "not available yet"; no customer reissue button | UI polish |
| Low | No partner edit UI (API exists); category not editable in admin books | UI follow-up |
| Info | Order numbers / payment ids shown to admins include the fake gateway prefix only in dev | n/a |
| Info | Test scripts assume a POSIX shell with `openssl`; on Windows run them from Git Bash | n/a |
