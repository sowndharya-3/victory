# SP-5 pre-merge review: outstanding items, decisions and test-mode checklist

Companion to [SP5-handover.md](SP5-handover.md). The handover describes what was built and how to deploy it. This file lists what is still open before production, what the owner must decide, and what can only be verified with real Razorpay test credentials.

**Status of the branch:** `sp5-orders-codes`, ready for review into `master` (the repository default branch; there is no `main`). Not merged, not deployed. **Live payments must stay disabled** until section 4 has been completed with Razorpay test credentials.

Meaning of the columns: *Owner* = a business decision or an account/hosting action. *Eng* = a code or configuration change. *Deploy* = blocks a production deploy. *Follow* = can ship after go-live.

## 1. Fixed in the pre-merge hardening pass

| Finding | Fix |
|---|---|
| Fake payment gateway could be switched on in production by accident (forgeable signatures give free books) | The fake gateway is allowed only for explicit development `APP_ENV` values; any other value fails closed. Placeholder, short or default secrets are refused outside development. `.env.example` now defaults to the real gateway. |
| No login brute-force protection | Per-account and per-IP throttling on `/api/auth/login` and `/api/admin/login`, with a generic response that does not reveal whether an account exists. |
| Weak or missing secrets were only noticed at the first payment | `backend/scripts/check_config.php`: a deploy-time check that prints PASS/FAIL per item and never prints secret values. |
| Migration 003 can half-apply on a live database with duplicate rows | `backend/scripts/premigration_checks.sql`: read-only checks that must return zero rows. Run it, and take a backup, before applying migrations. |
| PDF page renderer could stall the API (blocking lock, no size cap, weak page-count parsing, non-atomic re-upload) | Lock with a deadline, page-size and resource limits, tested timeout, safe generic errors, anchored page-count parsing, atomic re-upload and guarded `page_count` write. |
| Docker base image was end-of-life Debian bullseye | Moved to `php:8.2-fpm-trixie` (Debian 13). The full backend suite was run on the new image (results in `backend/TEST_RESULTS.md`). |
| Two partners could share one email; duplicate applications could race | Database-enforced unique partner email and one pending-or-approved application per email (migration 008), with 409 responses instead of 500. |
| Deep links such as `/admin` returned 404 on a static host | `.htaccess` (Apache), `_redirects` (Netlify/Cloudflare) and an nginx snippet; unknown paths show a "Page not found" page. See [frontend-hosting.md](frontend-hosting.md). |
| Stray `run` npm dependency in `package.json` | Reverted. It was never imported and looks like an accidental `npm i run`. |
| Three screenshots showed development access codes | Redacted. The codes no longer exist in the database. |
| `.gitignore` gaps (`.env*`, `*.zip`, `.playwright-mcp/`, private screenshots) | Added. |

## 2. Still open

### Blocks a production deploy

| # | Item | Who |
|---|---|---|
| D-1 | **Policy pages** are missing: Refund/Cancellation, Terms, Privacy, Shipping, Contact. Razorpay live activation checks the website for them. The refund-policy text depends on the decisions in section 3. | Owner (text) + Eng (pages) |
| D-2 | **Razorpay account and test-mode run** (U6): KYC, test and live keys, webhook secret, auto-capture on, and the checklist in section 4 passing. | Owner |
| D-3 | **Hosting** (U7): HTTPS API host, Poppler installed, cron for `reconcile_orders.php` with `flock` and alerting on failure, `pm.max_children >= 10`, real client IP behind any proxy/CDN, `storage/` outside the web root. If the API runs on Apache or shared hosting, `backend/public` has no front-controller `.htaccess` and the nginx rules (404 for `book.pdf`/`pages/`, dot-file deny) have no Apache equivalent; the `Authorization` header may also be stripped. Decide the API host before go-live. | Owner + Eng |
| D-4 | **Shipping and GST** (U1/U2) must be confirmed by the accountant and set in Admin, Settings. Defaults are shipping 0 and GST 0% inclusive, so no GST is collected or shown. | Owner |
| D-5 | Run `check_config.php` and `premigration_checks.sql` on the production server, back up the database, then apply migrations in order. | Eng |
| D-6 | Seed exactly one admin with a strong password and confirm the development admin is not copied to production. | Eng |
| D-7 | Decide retention for `payment_events.payload` (full Razorpay payloads including email, phone, last-4/VPA, IP) and cover it in the privacy notice (DPDP Act 2023). | Owner |
| D-8 | The cron reconciler is mandatory: it is the only fallback for lost `payment.captured` webhooks. | Eng |

### Can follow go-live

| # | Item | Who | Notes |
|---|---|---|---|
| F-1 | **Lost refund webhook.** Nothing re-checks refunds if `refund.processed` is lost, a refund stays `processing`, or a refund is made in the Razorpay dashboard (logged and ignored). The order stays `paid`, access stays live, the commission stays payable. | Eng | Do this before volume. Add a refund reconciler to the cron. |
| F-2 | **Watermark limits.** The watermark is a browser overlay. Page images are served clean and are the same for every user, so a buyer can script-download a whole book (rate limit 240 pages per 5 minutes) with no traceable mark. It deters casual sharing only. | Owner (accept, or pay for per-user burned-in marks) + Eng | The design accepted this risk. |
| F-3 | **Security headers.** The API sends no HSTS, CSP or `frame-ancestors`. The frontend rules add nosniff, Referrer-Policy and X-Frame-Options only. Tokens live in `localStorage`, so any XSS would expose the admin token. | Eng | Add a CSP (start in report-only mode) before go-live if possible. |
| F-4 | Rate limits key on `REMOTE_ADDR`. Behind a proxy/CDN all visitors share one bucket; IPv6 /64 rotation is possible. | Eng + Owner (hosting) | Becomes a deploy blocker if the API sits behind a CDN. |
| F-5 | Commission on a hard copy cancelled after shipping stays payable. `clawback_due` has no settle action. A deactivated partner still earns on orders created before deactivation. Residual payout race if a refund opens after the hold check. | Owner (U4) + Eng | See decision D8 below. |
| F-6 | Duplicate payment on an already-paid order is flagged (`admin_flag`) but has no admin refund UI; it can only be refunded from the Razorpay dashboard. | Eng | |
| F-7 | Email (U5) is deferred: refunds, revocations and shipments are silent to the customer. The policy text must say status is shown in Account and My Books. | Owner | |
| F-8 | No customer refund-request screen (the API exists). Build it only after decisions D1-D4. | Owner then Eng | |
| F-9 | Smaller items: admin lists unpaginated, partner edit UI, category not editable, reader index iframe has no sandbox, JWT has no `nbf`/`iss`/`aud` and no server-side revocation, `currency` not checked in `markPaid`, `AdminBookController::update` does not validate prices, test CLI helpers lack a `PHP_SAPI` guard, `API_BASE_URL` silently falls back to localhost if `VITE_API_URL` is missing at build time. | Eng | |
| F-11 | **Runtime versions.** The local stack still uses `mysql:8.0`, which reached end of life in April 2026, and PHP 8.2, whose security support ends in December 2026 (verify both dates against the vendors). The major versions were deliberately not changed in this pass. Plan an upgrade to MySQL 8.4 LTS and PHP 8.3/8.4 with the full test suite, and choose supported versions on the production host. | Eng | Plan before go-live; the production host decides. |
| F-10 | Self-referral check compares only the partner gmail with the buyer's login email, so a second account bypasses it (worth the flat commission per order). "First purchase only" (U4) would bound it. | Owner (U4) | |

## 3. Business decisions required from the owner

### Open decisions from the design (U1-U8)

| Id | Decision | Needed for |
|---|---|---|
| U1 | Shipping charge per type | Deploy |
| U2 | GST rate and mode (inclusive or extra); whether the business is GST-registered | Deploy |
| U3 | Refund windows (see D1) | Policy pages, refund UI |
| U4 | Commission hold period and clawback rule (see D8); first-purchase-only | Commission payouts |
| U5 | Email notifications (deferred) | Follow |
| U6 | Razorpay account, KYC, keys, webhook | Deploy |
| U7 | Hosting for API and frontend | Deploy |
| U8 | Guest checkout; can a customer cancel a hard copy before it ships? (current behaviour: login required; cancel means a refund request through the API only) | Follow |

### Refund and cancellation: current behaviour

- Customers can request a refund through the API only (there is no screen), for any paid order, at any time, with no window. They cannot cancel an order.
- Admins can approve or reject a request, or refund an order directly ("Refund this order"). Only **full** refunds are possible; the amount always comes from the stored payment.
- An unshipped hard copy can only be cancelled by refunding it (the admin cancel option is refused with `refund_required` for `confirmed`/`processing` orders). Cancelling after shipping (lost parcel) moves no money and leaves the order `paid`.
- Refunding a shipped hard copy returns the money while the goods stay with the customer; there is no return tracking.
- Refunding a digital order revokes the code and the reader access it granted, including a gift recipient's access.
- Nobody is notified (email is deferred).

### Decisions D1-D10 (recommendations are the engineering suggestion, not agreed policy)

Do **not** build the customer refund screen until D1-D4 are answered.

| Id | Question | Options | Recommendation |
|---|---|---|---|
| D1 | Who can request a refund, and until when? | (a) support only; (b) self-service inside a window; (c) self-service any time | **(b)**. Digital: within 7 days of payment and before redemption. Hard copy: instant cancel before dispatch; after delivery, request within 7 days. Requests stay admin-approved. |
| D2 | Digital refund after the code is redeemed or the book was read? | (a) always refundable; (b) not refundable once redeemed; (c) refundable if fewer than N pages were viewed | **(b)** with an admin override. It is simple to check and avoids revoking a gift recipient. (c) needs page-view logging. |
| D3 | Hard copy before and after dispatch, and returns? | Before dispatch: (a) instant customer cancel with automatic refund, or (b) admin-approved request. After dispatch: (c) no refunds, (d) refund only after the return is received, (e) refund without return for damage or loss | Before dispatch **(a)**, on `confirmed` only (not `processing`). After dispatch **(d)** by default and **(e)** at admin discretion with photo proof. Needs `return_requested`/`returned` states. |
| D4 | Shipping charge and partial refunds? | (a) full refund always (today); (b) full before dispatch, book price only after; (c) free-form partial amounts | **(b)** with a single "book only" preset. Avoid free-form partials: they need a multi-refund ledger. This is the largest change; do it only if (b) is chosen. |
| D5 | GST on refunds | Refund GST pro-rata (automatic while full-only) | No work until U2 is settled; if GST is charged, the accountant decides whether credit notes are needed. |
| D6 | Refund reason capture | Free text today | Fixed reason list (`not_received`, `damaged`, `wrong_item`, `changed_mind`, `duplicate_purchase`, `other`) plus optional text. |
| D7 | Self-service button or support only? | | Self-service request button (D1) with manual approval; the instant cancel before dispatch is the one exception (D3). |
| D8 | Commission clawback after payout (U4) | (a) deduct from the partner's next payout; (b) ask the partner to repay; (c) write off | A hold period equal to the refund window (commission becomes payable only N days after delivery/redemption) plus (a) for the rest. Also: commission on a cancelled order must not be payable. |
| D9 | Notifications while email is deferred | (a) status in Account/My Books only; (b) build email (U5); (c) manual WhatsApp from admin | **(a)** now, **(b)** before marketing drives volume. |
| D10 | Gift recipients (digital) | | With D2 (b) the issue disappears; otherwise the policy must state that a refund revokes the reader access granted by the code. |

## 4. Razorpay test-mode verification checklist (not yet run)

Needs the owner's **test** keys. Until every box below is ticked, live payments stay disabled. Nothing in this branch has been exercised against real Razorpay: the Razorpay Checkout popup was replaced by a stub and all payment, webhook and refund tests used the fake gateway.

- [ ] Test keys in the server `.env` (`RAZORPAY_GATEWAY=live` with `rzp_test_` keys, and `APP_ENV=production` or a staging value that is not a development value; `fake` is refused outside development). `/api/orders` returns a real `order_...` id and the real Checkout popup opens with the right name and branding.
- [ ] Successful **card**, **UPI** (`success@razorpay`) and **netbanking** test payments: `/verify` returns 200, the order is `paid`, a digital code is shown, and a hard copy gets a tracking code.
- [ ] Failed payment (`failure@razorpay` UPI or a failing test card): the order becomes `failed`; a retry in the same modal succeeds and the order becomes `paid`.
- [ ] Tamper with `razorpay_signature` in the browser tools: 400 `payment_verification_failed`.
- [ ] Close the tab before the handler runs: the `payment.captured` webhook alone marks the order paid within seconds.
- [ ] Webhook delivery: the dashboard shows 2xx for `payment.captured` and `payment.failed`; a redelivered event returns `{"status":"duplicate"}`; a wrong secret returns 400. Confirm the `X-Razorpay-Event-Id` header is present on every delivery (the code returns 400 without it).
- [ ] Auto-capture is ON. Optionally test with it off: the order stays pending and `/verify` returns 202.
- [ ] Refund through Admin, "Refund this order": note whether test mode returns `processed` immediately or `pending` at normal speed; the `refund.processed` webhook must finalise a `processing` refund. Simulate `refund.failed` if test mode allows.
- [ ] Refund initiated **in the Razorpay dashboard** for an app order: confirm the app ignores it (known gap F-1). Same for a partial refund from the dashboard.
- [ ] Reconciler: `php scripts/reconcile_orders.php` exits 0 with test keys. Create an order, pay, block the webhook (wrong URL), run the cron after 15 minutes: the order becomes `paid`.
- [ ] Settlement and fees: check in the dashboard that fees and GST on fees are deducted and whether fees are returned on refund (they normally are not). This feeds decision D4.
- [ ] Dashboard webhook configuration: URL `https://<api>/api/webhooks/razorpay`, the secret equals `RAZORPAY_WEBHOOK_SECRET`, only the four events are active (`payment.captured`, `payment.failed`, `refund.processed`, `refund.failed`). Test-mode and live-mode webhooks are configured separately.
- [ ] Live switch (only after all of the above and the owner's approval): live keys, a live webhook with a new secret, and one small real payment and refund on the owner's own card or UPI.

## 5. Verification not done in a real environment

- Razorpay Checkout popup, real payments, real webhooks, real refunds (section 4).
- The Apache `.htaccess`, the nginx snippet and Netlify/Cloudflare `_redirects` rules were only imitated by a local test server; follow the post-deploy checklist in [frontend-hosting.md](frontend-hosting.md) on the first deploy.
- The customer refund request was exercised through the API only; there is no screen.

## 6. Operational notes from the hardening pass

**Deploy order.** Back up the database, run `backend/scripts/premigration_checks.sql` (every query must return zero rows), then apply migrations 003 to 008 in order. On every deploy run `php scripts/check_config.php` (in Docker: `docker compose exec -T php php scripts/check_config.php`); it must exit 0.

**Environment policy (enforced at startup, fail closed).**
- The fake gateway is allowed only when `APP_ENV` is exactly one of `development`, `dev`, `local`, `test`, `testing` (case-sensitive). Any other value, including unset, empty, `production`, `Production`, `prod` and `staging`, is treated as production.
- Outside development the live gateway needs a key id starting `rzp_test_` or `rzp_live_`, a key secret of at least 20 characters, a webhook secret of at least 32 characters, and no placeholder text.
- `JWT_SECRET` and `CODE_SECRET` need at least 32 characters and no placeholder in every environment.
- Error messages name the variable, never its value. `.env.example` defaults to production plus the live gateway with empty keys; the local `backend/.env` sets development plus fake for the test suite.

**Runtime image.** `php:8.2-fpm-trixie` (PHP 8.2.35, Debian 13, Poppler 25.03). Bullseye had been pinned because of a dev-host "exec format error" that no longer occurs. PHP 8.4 was tried and rejected for now: from PHP 8.3, `http_response_code()` stops working once output has started, which breaks two test harnesses (`admin_authz_test`, `middleware_test`) that print before calling controllers. To move to 8.4, add output buffering to those harnesses. PHP 8.2 security support ends 2026-12-31. `mysql:8.0` is unchanged (end of life April 2026; upgrade needs its own plan and a data-volume backup). `nginx:alpine` is a floating tag and should be pinned on the production host. The previous image is kept locally as `vtee-php:bullseye-backup` for rollback.

**New or changed API behaviour.**
- `render_busy` (503, `Retry-After: 5`) on reader pages and `/access` when a page is being rendered or vanished mid-request; the reader's Retry button covers it.
- `rate_limited` (429) on both logins.
- `duplicate_application` and `duplicate_partner` (409) are now also returned on races; they were a 500 before.
- `/access` for an owner whose book has no usable PDF returns 200 without `pageCount` (it was a 500).
- The reader memory cap is 1 GiB per render; a very complex page may fail with `render_failed` and is then remembered for 60 seconds.
- Encrypted PDFs fail in `pdfinfo` and give `render_failed` (no fixture was available to test this).

**Decision recorded.** Phone, WhatsApp and UPI are deliberately not unique across partners (people can share them); only the email is. Rejected applications may re-apply.
