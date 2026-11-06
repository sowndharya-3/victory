# SP-5 pre-merge review: outstanding items, decisions and test-mode checklist

Companion to [SP5-handover.md](SP5-handover.md). The handover describes what was built and how to deploy it. This file lists what is still open before production, what the owner must decide, and what can only be verified with real Razorpay test credentials.

**Status:** this review was written on `sp5-orders-codes` and has been updated for the 33 finalization commits on `sp5-finalize`, the PR source branch (see [SP5-PR-description.md](SP5-PR-description.md)). Base branch is `master` (there is no `main`). Not merged, not deployed. **Live payments must stay disabled** until section 4 has been completed with Razorpay test credentials; that run has **not** happened (no test keys exist in the project).

Meaning of the columns: *Owner* = a business decision or an account/hosting action. *Eng* = a code or configuration change. *Deploy* = blocks a production deploy. *Follow* = can ship after go-live.

## 1. Fixed in the pre-merge hardening pass

| Finding | Fix |
|---|---|
| Fake payment gateway could be switched on in production by accident (forgeable signatures give free books) | The fake gateway is allowed only for explicit development `APP_ENV` values; any other value fails closed. Placeholder, short or default secrets are refused outside development. `.env.example` now defaults to the real gateway. |
| No login brute-force protection | Per-account and per-IP throttling on `/api/auth/login` and `/api/admin/login`, with a generic response that does not reveal whether an account exists. |
| Weak or missing secrets were only noticed at the first payment | `backend/scripts/check_config.php`: a deploy-time check that prints PASS/FAIL per item and never prints secret values. |
| Migration 003 can half-apply on a live database with duplicate rows | `backend/scripts/premigration_checks.sql`: read-only checks that must return zero rows. Run it, and take a backup, before applying migrations. |
| PDF page renderer could stall the API (blocking lock, no size cap, weak page-count parsing, non-atomic re-upload) | Lock with a deadline, page-size and resource limits, tested timeout, safe generic errors, anchored page-count parsing, atomic re-upload and guarded `page_count` write. |
| Docker base image was end-of-life Debian bullseye | Moved to Debian 13 trixie, then to `php:8.4-fpm-trixie` in the finalization pass. The full backend suite was run on the new image (results in `backend/TEST_RESULTS.md`). |
| Two partners could share one email; duplicate applications could race | Database-enforced unique partner email and one pending-or-approved application per email (migration 008), with 409 responses instead of 500. |
| Deep links such as `/admin` returned 404 on a static host | `.htaccess` (Apache), `_redirects` (Netlify/Cloudflare) and an nginx snippet; unknown paths show a "Page not found" page. See [frontend-hosting.md](frontend-hosting.md). |
| Stray `run` npm dependency in `package.json` | Reverted. It was never imported and looks like an accidental `npm i run`. |
| Three screenshots showed development access codes | Redacted. The codes no longer exist in the database. |
| `.gitignore` gaps (`.env*`, `*.zip`, `.playwright-mcp/`, private screenshots) | Added. |

### Fixed in the finalization pass (`sp5-finalize`)

Refund reconciliation for lost webhooks and dashboard refunds (F-1); API security headers and a Report-Only CSP (F-3); proxy-aware client IP with `TRUSTED_PROXIES` and /64 grouping for IPv6 (F-4); `Authorization` header accepted on Apache/FastCGI and a front-controller `.htaccess` for the API (part of D-3); book title/price validation, admin category and price editing, payment currency check (F-9); production build fails without `VITE_API_URL` (F-9); PHP 8.4 (F-11); contact form API; draft policy pages; no-refund notice at checkout; customer refund-request route removed; commission payout hold removed. The full commit list is in the PR description.

## 2. Still open

### Blocks a production deploy

| # | Item | Who |
|---|---|---|
| D-1 | **Policy pages**: four draft pages exist (`/terms`, `/privacy`, `/refund-policy`, `/digital-access`, text in `src/content/policies`) and are linked from the footer. The owner must review and approve the text before go-live; a separate shipping policy page does not exist (shipping is covered only if the owner adds it to the drafts). The contact form is implemented. Razorpay live activation checks the website for these pages. | Owner (review) |
| D-2 | **Razorpay account and test-mode run** (U6): KYC, test and live keys, webhook secret, auto-capture on, and the checklist in section 4 passing. | Owner |
| D-3 | **Hosting** (U7, a separate final stage, not started): HTTPS API host, Poppler installed, cron for `reconcile_orders.php` with `flock` and alerting on failure, `pm.max_children >= 10`, real client IP behind any proxy/CDN (`set_real_ip_from` or `TRUSTED_PROXIES`), `storage/` outside the web root. Code side done: `backend/public/.htaccess` (front controller, 404 for `book.pdf`/`pages/`, dot-files and stray PHP) and the `Authorization` header fix exist, checked only on a throwaway Apache container. Still to do on the real host: HTTPS, HSTS, cron, workers, backups. See [SP5-environments.md](SP5-environments.md). | Owner + Eng |
| D-4 | **Shipping and GST** (U1/U2) must be confirmed by the accountant and set in Admin, Settings. Defaults are shipping 0 and GST 0% inclusive, so no GST is collected or shown. | Owner |
| D-5 | Run `check_config.php` and `premigration_checks.sql` on the production server, back up the database, then apply migrations in order. | Eng |
| D-6 | Seed exactly one admin with a strong password and confirm the development admin is not copied to production. | Eng |
| D-7 | Decide retention for `payment_events.payload` (full Razorpay payloads including email, phone, last-4/VPA, IP) and cover it in the privacy notice (DPDP Act 2023). | Owner |
| D-8 | The cron reconciler is mandatory: it is the only fallback for lost `payment.captured` webhooks. | Eng |

### Can follow go-live

| # | Item | Who | Notes |
|---|---|---|---|
| F-1 | **Lost refund webhook.** *Fixed.* The cron reconciler now re-checks open refunds and finalises them; a full refund made in the Razorpay dashboard is recorded; a partial or unmatched external refund is flagged for an admin (`admin_flag`). Still verify against real Razorpay test mode. | Eng | Done; verification pending. |
| F-2 | **Watermark limits.** The watermark is a browser overlay. Page images are served clean and are the same for every user, so a buyer can script-download a whole book (rate limit 240 pages per 5 minutes) with no traceable mark. It deters casual sharing only. | Owner (accept, or pay for per-user burned-in marks) + Eng | The design accepted this risk. |
| F-3 | **Security headers.** *Fixed (Report-Only).* The API sends `Content-Security-Policy: default-src 'none'`, nosniff, `Referrer-Policy: no-referrer` and `Cache-Control: no-store` (nginx config). The static host sends a CSP in **Report-Only** mode, Permissions-Policy, nosniff, Referrer-Policy and X-Frame-Options (`public/_headers`, `public/.htaccess`). Remaining: HSTS only once HTTPS works on the real host; switch the CSP to enforcing after a clean week of reports. Tokens still live in `localStorage`, so an XSS would expose the admin token; the CSP is the main defence. | Eng | HSTS and CSP enforcement at hosting time. |
| F-4 | Rate limits and proxies. *Fixed in code.* `TRUSTED_PROXIES` (or nginx `set_real_ip_from`) gives the real visitor IP; IPv6 is bucketed per /64. The deployer must still configure one of them if a proxy/CDN sits in front. | Eng + Owner (hosting) | At hosting time. |
| F-5 | Commission. Decided: Rs 25 online and Rs 25 hard copy, no hold period. Admin-approved exception refunds do NOT reverse or claw back commission: commission statuses are untouched and finalising a refund writes audit row `refund.commission_unchanged`. `clawback_due` is a legacy column that nothing sets or shows; `reversed` status and columns are unused; no migration 009. Remaining by design: a hard copy cancelled after shipping keeps its commission; a deactivated partner still earns on orders created before deactivation. | Owner decided | None. |
| F-6 | Duplicate payment on an already-paid order is flagged (`admin_flag`). Per the owner's decision it is a genuine exception: an admin refunds it with "Refund this order" (or from the Razorpay dashboard, which the reconciler then records). | Admin runbook | |
| F-7 | Transactional email is not implemented: refunds, revocations and shipments are silent to the customer, and the policy text must say status is shown in Account and My Books. The contact form does send mail to the admin (SMTP). | Owner | Follow |
| F-8 | Customer refund-request screen. *Closed by decision:* there is no customer refund-request workflow; the route was removed. | Owner decided | None. |
| F-9 | Smaller items. *Fixed:* category and price editing in Admin, price/title validation on update, payment currency check, `VITE_API_URL` missing at build time (the production build now fails). *Still open:* admin lists unpaginated, partner edit UI, reader index iframe has no sandbox, JWT has no `nbf`/`iss`/`aud` and no server-side revocation, test CLI helpers lack a `PHP_SAPI` guard. | Eng | Follow |
| F-11 | **Runtime versions.** PHP is now 8.4 (security support to 2028-12-31; verify the date); the image used before is kept as `vtee-php:8.2-trixie-rollback`. `mysql:8.0` in the dev compose file reached end of life in April 2026 (verify): the production host should use a supported MySQL (8.4 LTS) or MariaDB, tested with migrations 001-008 and the suites. | Eng | Production host decides. |
| F-10 | Self-referral check compares only the partner gmail with the buyer's login email, so a second account bypasses it (worth the flat Rs 25 per order). No first-purchase-only rule has been decided. | Owner | |

## 3. Business decisions required from the owner

### Decisions confirmed by the owner

| Topic | Decision |
|---|---|
| Refunds | No general refunds for purchased books. No customer refund-request workflow (route removed, no screen). Genuine exceptions (duplicate payment, debited-but-failed, legal cases) are handled manually by an admin with an admin refund. This answers the former questions D1-D10 (windows, redeemed codes, returns, partial refunds, reason list, self-service button, gift recipients): none of those policies is built. |
| Commission | Rs 25 online + Rs 25 hard copy. No hold period. Refunds do not reverse or claw back commission (audit row `refund.commission_unchanged`); `clawback_due` is legacy and unused; no migration 009. |
| PR source | `sp5-finalize` is the PR head, base `master`. |
| Contact | Enquiries are emailed to the admin only, not stored in the database; failures are shown honestly. |
| Policy pages | Drafts until business approval. |
| Razorpay live credentials | Reserved for the final production deployment stage; the real test-mode run waits for `rzp_test_` keys in the staging `.env`. |
| Flag cleanup | No admin "clear flag" action now; plan in [SP5-flag-cleanup-plan.md](SP5-flag-cleanup-plan.md). |
| Dev test data | Cleanup pending owner approval (backup kept outside the repository). |
| Homepage | "32+ Years Experience" and "30 years in universities + 2 years company experience" removed. |
| Notifications | Order status is shown in Account and My Books; transactional email is not built. The contact form emails the admin. |
| Hosting | A separate final stage. |

The checkout and book page show a no-refund notice, and checkout requires an acknowledgement. The text of the four policy pages is a draft awaiting owner review.

### Still open (U1-U8)

| Id | Decision | Needed for |
|---|---|---|
| U1 | Shipping charge per type | Deploy |
| U2 | GST rate and mode (inclusive or extra); whether the business is GST-registered | Deploy |
| U3 | Answered (see above): no general refunds | - |
| U4 | Answered (see above): Rs 25 + Rs 25, no hold, no reversal or clawback | - |
| U5 | Transactional email (not built) | Follow |
| U6 | Razorpay account, KYC, keys, webhook | Deploy |
| U7 | Hosting for API and frontend | Deploy (final stage) |
| U8 | Guest checkout (current: login required). Customers cannot cancel; an unshipped paid hard copy is cancelled only through an admin exception refund | Follow |

### Refund and cancellation: current behaviour

- Customers cannot request refunds or cancel orders (no route, no screen). `GET /api/me/refunds` still lists their refunds.
- Admins can refund an order directly ("Refund this order") for a genuine exception, and can still approve or reject legacy `requested` rows. Only **full** refunds are possible; the amount always comes from the stored payment.
- An unshipped hard copy can only be cancelled by refunding it (the admin cancel option is refused with `refund_required` for `confirmed`/`processing` orders). Cancelling after shipping (lost parcel) moves no money and leaves the order `paid`.
- Refunding a shipped hard copy returns the money while the goods stay with the customer; there is no return tracking.
- Refunding a digital order revokes the code and the reader access it granted, including a gift recipient's access.
- Refunding an order never changes its commission (no reversal, no clawback); the audit row `refund.commission_unchanged` records the commission id, status and amount. Pay or withhold partner money manually.

- Nobody is notified by email.

## 4. Razorpay test-mode verification checklist (NOT YET RUN)

The full checklist with a results table is [SP5-razorpay-test-checklist.md](SP5-razorpay-test-checklist.md). No Razorpay credentials exist in the project (only development placeholders), so no row has been run. Needs the owner's **test** keys. Until every box below is ticked, live payments stay disabled. Nothing in this branch has been exercised against real Razorpay: the Razorpay Checkout popup was replaced by a stub and all payment, webhook and refund tests used the fake gateway.

- [ ] Test keys in the server `.env` (`RAZORPAY_GATEWAY=live` with `rzp_test_` keys, and `APP_ENV=production` or a staging value that is not a development value; `fake` is refused outside development). `/api/orders` returns a real `order_...` id and the real Checkout popup opens with the right name and branding.
- [ ] Successful **card**, **UPI** (`success@razorpay`) and **netbanking** test payments: `/verify` returns 200, the order is `paid`, a digital code is shown, and a hard copy gets a tracking code.
- [ ] Failed payment (`failure@razorpay` UPI or a failing test card): the order becomes `failed`; a retry in the same modal succeeds and the order becomes `paid`.
- [ ] Tamper with `razorpay_signature` in the browser tools: 400 `payment_verification_failed`.
- [ ] Close the tab before the handler runs: the `payment.captured` webhook alone marks the order paid within seconds.
- [ ] Webhook delivery: the dashboard shows 2xx for `payment.captured` and `payment.failed`; a redelivered event returns `{"status":"duplicate"}`; a wrong secret returns 400. Confirm the `X-Razorpay-Event-Id` header is present on every delivery (the code returns 400 without it).
- [ ] Auto-capture is ON. Optionally test with it off: the order stays pending and `/verify` returns 202.
- [ ] Exception refund through Admin, "Refund this order": note whether test mode returns `processed` immediately or `pending` at normal speed; the `refund.processed` webhook must finalise a `processing` refund. Simulate `refund.failed` if test mode allows.
- [ ] Refund initiated **in the Razorpay dashboard** for an app order: confirm the reconciler records it (F-1 is now implemented but unverified against Razorpay). A partial refund from the dashboard must be flagged for an admin, not finalised.
- [ ] Reconciler: `php scripts/reconcile_orders.php` exits 0 with test keys. Create an order, pay, block the webhook (wrong URL), run the cron after 15 minutes: the order becomes `paid`.
- [ ] Settlement and fees: check in the dashboard that fees and GST on fees are deducted and whether fees are returned on refund (they normally are not). This feeds decision D4.
- [ ] Dashboard webhook configuration: URL `https://<api>/api/webhooks/razorpay`, the secret equals `RAZORPAY_WEBHOOK_SECRET`, only the four events are active (`payment.captured`, `payment.failed`, `refund.processed`, `refund.failed`). Test-mode and live-mode webhooks are configured separately.
- [ ] Live switch (only after all of the above and the owner's approval): live keys, a live webhook with a new secret, and one small real payment and refund on the owner's own card or UPI.

## 5. Verification not done in a real environment

- Razorpay Checkout popup, real payments, real webhooks, real refunds (section 4). The real Razorpay popup is NOT TESTED; browser E2E at 1280, 820 and 390 px ran on the fake gateway with a stub checkout.js (evidence kept outside git by the owner).
- The Apache `.htaccess`, the nginx snippet and Netlify/Cloudflare `_redirects` rules were only imitated by a local test server; follow the post-deploy checklist in [frontend-hosting.md](frontend-hosting.md) on the first deploy.
- Refund reconciliation (lost `refund.processed`, dashboard refunds) was tested against a scripted gateway only.
- The contact form was tested against a scripted SMTP peer and a local mail catcher, not a real mail provider.

## 6. Operational notes from the hardening pass

**Deploy order.** Back up the database, run `backend/scripts/premigration_checks.sql` (every query must return zero rows), then apply migrations 003 to 008 in order. On every deploy run `php scripts/check_config.php` (in Docker: `docker compose exec -T php php scripts/check_config.php`); it must exit 0.

**Environment policy (enforced at startup, fail closed).**
- The fake gateway is allowed only when `APP_ENV` is exactly one of `development`, `dev`, `local`, `test`, `testing` (case-sensitive). Any other value, including unset, empty, `production`, `Production`, `prod` and `staging`, is treated as production.
- Outside development the live gateway needs a key id starting `rzp_test_` or `rzp_live_`, a key secret of at least 20 characters, a webhook secret of at least 32 characters, and no placeholder text.
- `JWT_SECRET` and `CODE_SECRET` need at least 32 characters and no placeholder in every environment.
- Error messages name the variable, never its value. `.env.example` defaults to production plus the live gateway with empty keys; the local `backend/.env` sets development plus fake for the test suite.

**Runtime image.** `php:8.4-fpm-trixie` (PHP 8.4.27, Debian 13, Poppler 25.03). The two test harnesses that broke on PHP 8.3+ (output before `http_response_code()`) were fixed. The previous image is kept locally as `vtee-php:8.2-trixie-rollback`. `mysql:8.0` is unchanged in the dev compose file (end of life April 2026; the production host needs its own plan and a data backup). `nginx:alpine` is a floating tag and should be pinned on the production host.

**New or changed API behaviour.**
- `render_busy` (503, `Retry-After: 5`) on reader pages and `/access` when a page is being rendered or vanished mid-request; the reader's Retry button covers it.
- `rate_limited` (429) on both logins.
- `duplicate_application` and `duplicate_partner` (409) are now also returned on races; they were a 500 before.
- `/access` for an owner whose book has no usable PDF returns 200 without `pageCount` (it was a 500).
- The reader memory cap is 1 GiB per render; a very complex page may fail with `render_failed` and is then remembered for 60 seconds.
- Encrypted PDFs fail in `pdfinfo` and give `render_failed` (no fixture was available to test this).

**Decision recorded.** Phone, WhatsApp and UPI are deliberately not unique across partners (people can share them); only the email is. Rejected applications may re-apply.

## 7. Owner decisions and blockers: homepage claims

The unverified "32+ Years Experience" claim and the "30 years in universities + 2 years company experience" text were removed from the homepage. These claims are still on the home page and need business confirmation:

| Claim | Status | Recommendation |
|---|---|---|
| "World-class" | Not confirmed | If not verified, remove before production |
| Retired professors | Not confirmed | If not verified, remove before production |
| 24x7 availability | Not confirmed | If not verified, remove before production |
| Free one-to-one counselling | Not confirmed | If not verified, remove before production |
| Founder titles (Founder Chairman, PhD in Education) | Not confirmed | If not verified, remove before production |

Other blockers: owner approval of the four draft policy pages; `rzp_test_` keys in the staging `.env` before the real test-mode run; owner approval of the dev test data cleanup.
