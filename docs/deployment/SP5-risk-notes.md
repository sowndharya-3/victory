# SP-5 risk notes: refunds, headers, tokens, rate limits, hosting

Branch `sp5-orders-codes` at `93f8384`, read on 2026-10-31 in `S:\Company\Client\vtee\__vtee`. The original investigation changed nothing in the repo and ran nothing against Docker, the database or Razorpay; see the status section below for what was built afterwards.

## Status after the finalization pass (read this first)

The rest of this file is the original investigation and design, kept for the reasoning. Several recommendations have since been built or overruled by owner decisions. Where the text below says "today" or "not built", it describes the code at `93f8384`.

| Section | Status on `sp5-finalize` |
|---|---|
| 1. Lost refund webhook | **Built.** The cron reconciler finalises open refunds, records full dashboard refunds, and flags partial or unmatched external refunds for an admin. The manual runbook (1.4) remains a fallback. Not yet verified against real Razorpay. |
| 2. Security headers | **Built.** API headers in `backend/nginx/default.conf`; frontend headers and a Report-Only CSP in `public/_headers` and `public/.htaccess`. HSTS is a commented line to enable on the real host. CSP enforcement is a follow-up after a clean week. |
| 3. Token storage | **Unchanged.** Tokens stay in `localStorage` (option 1: rely on the CSP). Lifetime and cookie options are not implemented. |
| 4. Proxy-aware rate limiting | **Built.** `TRUSTED_PROXIES` plus IPv6 /64 grouping (`App\Support\ClientIp`); the nginx `set_real_ip_from` route is still preferred. |
| 5. Hosting | **Not started** (a separate final stage). The code runs on PHP 8.4. See [SP5-environments.md](SP5-environments.md). |

Owner decisions that overrule parts of this file: no general refunds and no customer refund-request workflow (the customer route was removed, so the "customer refund request" references below are historical); genuine exceptions are refunded manually by an admin; commission is Rs 25 online + Rs 25 hard copy with **no hold period** and **no clawback, reversal or settlement rules** (the payout hold described in 1.1 was removed). Admin-approved exception refunds do NOT reverse or claw back commission: statuses stay as they were and finalising a refund writes audit row `refund.commission_unchanged`. `clawback_due` is a legacy column that nothing sets or shows; the `reversed` status and columns stay unused; there is no migration 009 and no commission hold. Later decisions: `sp5-finalize` is the PR head (base `master`); contact enquiries are email only (not stored); policy pages stay drafts; live Razorpay credentials are reserved for the final production stage; the admin "clear flag" action is not being built (see [SP5-flag-cleanup-plan.md](SP5-flag-cleanup-plan.md)). Wherever the sections below say a refund "reverses" a commission, or propose a commission hold or a `source` column in a migration, that is historical design text and is NOT built. The decisions D1-D10 referred to at the end are answered and closed.

All `file:line` references are relative to the repo root. Note that the scripts live in `backend/scripts/`, not in a top-level `scripts/` folder.

**How to read this**
- **Severity.** *High* means money, access or data end up wrong with no automatic correction. *Medium* means wrong state that an admin can see and fix. *Low* means rare or cosmetic.
- **Effort.** Effort is engineering time for one developer who knows the codebase. It includes tests, but not the owner's time or the wait for Razorpay test mode.
- **"Verify on host/test mode".** The claim depends on the real hosting company or on real Razorpay behaviour, and could not be proven from the code.

Terms used throughout:
- **Webhook.** A message that Razorpay's server sends to our API when something happens, such as "refund processed".
- **Reconciler.** The cron script `backend/scripts/reconcile_orders.php`, run every 15 minutes. It asks Razorpay for the truth and repairs our database.
- **Finalise.** `RefundService::finalise()`, the single place that marks a refund done. It sets the order and payment to `refunded`, revokes digital access or cancels an unshipped hard copy. It leaves the commission untouched (audit row `refund.commission_unchanged`).
- **`admin_flag`.** A free-text column on `orders` that the admin order list shows as a badge (`src/pages/admin/AdminOrdersPage.jsx:64`). The code uses it today for `duplicate_payment` and `amount_mismatch`.

---

## 1. Lost or missed refund webhook

### 1.1 How refunds work today

1. An admin clicks "Refund this order" or approves a customer request. `RefundService::approve()` locks the refund row, then calls Razorpay `POST /payments/{id}/refund` for the full captured amount (`backend/src/Refunds/RefundService.php:94-100`).
2. Razorpay's answer decides the next state:
   - `processed`: the row becomes `processing`, the transaction is committed, and finalise runs at once (`:107-115`).
   - `pending`: the row becomes `processing` with the Razorpay refund id stored. Nothing else changes until a webhook arrives (`:107-108`).
   - Error, timeout or any other status: the row becomes `failed` with the note "approve again to retry", and **no Razorpay refund id is stored** (`:103-106`). A timeout can happen *after* Razorpay has already refunded the money; the cURL timeout is 10 s (`backend/src/Razorpay/RazorpayGateway.php:55`).
3. The webhook (`backend/src/Controllers/WebhookController.php`) handles:
   - `refund.processed` (`:116-146`): it finds our refund by Razorpay refund id plus payment id. Failing that, it takes the newest refund row for that payment that has **no** refund id and status `requested`/`approved`/`processing`/`failed` (`:130-134`). It checks that the amount equals our full amount (`:141-144`), then finalises (`:145`).
   - `refund.failed` (`:149-164`): matched by refund id only. `markFailed()` changes only a `processing` row to `failed` (`RefundService.php:187-206`).
4. Every webhook event is stored in `payment_events` and marked processed **even when the result is `ignored`** (`WebhookController.php:36-52`). A redelivery of the same event therefore returns `duplicate` and is never looked at again.
5. *(At `93f8384`; since built, see the status section.)* The reconciler had **no refund logic at all**. `OrderReconciler::run()` only handles pending, failed and cancelled orders (`backend/src/Orders/OrderReconciler.php:15-79`), and `reconcile_orders.php:24-31` only calls `run()` and `purgeRateLimits()`.

*Superseded:* at `93f8384` commission payouts were held while a refund was `requested`, `approved` or `processing`. By owner decision that hold was removed; payouts are no longer blocked by an open refund.

### 1.2 Scenario by scenario

| # | Scenario | What happens today (order / access / commission) | How an admin would notice | Severity |
|---|---|---|---|---|
| a1 | `refund.processed` lost or late, and Razorpay first answered `pending` (our row is `processing`) | Order stays `paid`, reader and code stay live, commission unchanged. A late webhook fixes everything; a lost one never does. | Admin, Refunds, filter "processing" (`src/pages/admin/AdminRefundsPage.jsx:5,78`) shows it forever. No button can act on it (`:12` allows approve only for requested/approved/failed). | **Medium**. Customer has money and book; nothing is paid out wrongly. |
| a2 | Gateway call timed out but Razorpay *did* refund (row `failed`, no refund id), and the webhook is lost | Order `paid`, access live, and the commission is unchanged and can be marked payable and paid (by decision, refunds never affect commission). If the admin clicks Approve again, Razorpay refuses a second full refund (verify on test mode), so the row goes back to `failed` with a 502. | A `failed` row in Refunds plus a processed refund in the Razorpay dashboard. Confusing: the admin is told to retry and the retry keeps failing. | **Medium-High**: wrong commission payout possible. |
| b | Refund stuck at Razorpay `pending` (bank slow) | Same as a1 for as long as it stays pending. | Same as a1. How long "pending" lasts in practice: verify on test mode. | **Low-Medium** |
| c | `refund.failed` arrives after we already finalised (row `refunded`) | `markFailed` refuses (status is not `processing`) and returns false. The webhook answers `ignored` with **no log line** (`RefundService.php:193`, `WebhookController.php:163`). The order stays `refunded` and access stays revoked, though Razorpay says the money went back to the merchant. | Only through the Razorpay dashboard or a customer complaint. The event row is in `payment_events` (`event_type = refund.failed`). | **Low likelihood** (Razorpay normally fails only pending refunds; verify on test mode), **High impact** for that customer (no book and no money). |
| d-full | Full refund started in the **Razorpay dashboard** for an order the app thinks is `paid` | If the customer had an open `requested` refund (no refund id), the webhook finalises it (`:130-134` with `fromWebhook=true`, allowed at `RefundService.php:225`). That is correct. Otherwise it is logged "unknown refund" and ignored (`WebhookController.php:136-138`): order `paid`, access live, commission payable and payable out. The event is marked processed, so it can never be replayed. | Only by comparing the Razorpay dashboard with the app. The server error log has one line. | **High** (silent; money gone, access and commission continue) |
| d-partial | Partial refund in the dashboard | Amount differs, so it is logged and ignored (`:141-144`). Order `paid`, access live, commission payable. A later full refund from the app is refused by Razorpay because the remaining amount is less than the full amount (verify on test mode). The row goes `failed` and can never succeed from the app. | Same as d-full. | **Medium** (needs a human decision anyway) |
| e | Webhook for an unknown refund id | Falls back to the payment-id match. If the payment has an app refund row that **already has a different refund id** (for example our refund `rfnd_A` failed, then someone refunded in the dashboard as `rfnd_B`), the fallback skips it because it requires `razorpay_refund_id IS NULL` (`:131`), so it is ignored. If the payment is not ours at all (another app on the same Razorpay account), ignoring it is correct. | Error log only. | **Medium** |

Razorpay retries webhooks that do not get a 2xx response for some time, and may disable a webhook after repeated failures (verify the exact retry window and disable rule in the Razorpay docs and test mode). "Lost" therefore mainly means our API was down longer than the retry window, the webhook was misconfigured or disabled, or the event was not subscribed.

### 1.3 Design: refund reconciler (cron pass)

**Goal.** Within about 15-30 minutes of Razorpay knowing about a refund, the app reflects it. The reconciler must never revoke anything for a partial or unclear refund, and must never refund money itself.

**New gateway calls** (`backend/src/Razorpay/Gateway.php`, `RazorpayGateway.php`, `FakeGateway.php`):
- `fetchRefund(paymentId, refundId)`: `GET /v1/payments/{pay_id}/refunds/{rfnd_id}` (or `GET /v1/refunds/{rfnd_id}`).
- `fetchPaymentRefunds(paymentId)`: `GET /v1/payments/{pay_id}/refunds`.
- `listRefunds(fromUnix, toUnix, skip)`: `GET /v1/refunds?from=&to=&count=100&skip=`, for refunds created anywhere on the account, including the dashboard.
- Each returns Razorpay refund entities (`id`, `payment_id`, `amount` in paise, `status` = `pending|processed|failed`). The exact field names must be verified on test mode. FakeGateway returns `[]`.

**Pass R1: our own refunds that are not settled.** It runs first.
- Selection:
  ```sql
  SELECT r.*, p.razorpay_payment_id FROM refunds r JOIN payments p ON p.id = r.payment_id
   WHERE (r.status = 'processing' AND r.updated_at <= NOW() - INTERVAL 15 MINUTE)
      OR (r.status = 'failed' AND r.razorpay_refund_id IS NULL AND r.updated_at >= NOW() - INTERVAL 7 DAY)
  ```
  The second line covers timeouts. A `failed` row *with* a refund id came from a real `refund.failed` and is not re-checked.
- When a refund id is known: call `fetchRefund`.
  - `processed` and the amount equals `toPaise(r.amount)`: `finalise(r.id, rfnd_id)`.
  - `failed`: `markFailed(r.id)`.
  - `pending` older than **5 days**: count it as `stuck` and log it (alert only). This threshold is a setting; tune it after test mode.
- When there is no refund id (timeout): call `fetchPaymentRefunds`.
  - A `processed` full-amount refund whose id is not already on another `refunds` row: `finalise(r.id, rfnd_id, true)`. Finalise already handles `failed` rows and the "another refund is open" case (`RefundService.php:160-171`).
  - A `pending` one: leave it. The next run sees it processed.

**Pass R2: refunds made outside the app (dashboard).**
- Call `listRefunds(now - 7 days, now)`. The query is stateless (no cursor to store); page with `skip` and stop after 5 pages. At small volume this is one call per run.
- For each Razorpay refund, look up our payment by `payment_id`. Skip it if the payment is not ours, or if the refund id is already on a `refunds` row (R1 owns those).
- If our order is `paid`:
  - **Full** (refund `processed`, amount equals `payments.amount` in paise): call a new `RefundService::recordExternal($paymentRowId, $rzpRefund)`. In one transaction:
    1. If the order has an open refund row without a refund id, finalise that row with `fromWebhook=true`. This matches the existing webhook fallback.
    2. Otherwise insert a `refunds` row with status `approved`, `requested_by_user_id` NULL, `reviewed_by_admin_id` NULL, reason `"Refunded outside the app (Razorpay dashboard) rfnd_..."` and `razorpay_refund_id` set. Add an `audit_logs` entry `refund.external`, then finalise.
    3. The unique keys `uq_open_refund` and `uq_refunds_rzp_refund` make a second run or a parallel webhook a no-op. Catch the duplicate-key error (23000) and treat it as "already done".
    4. Marking the row as external needs no migration: the null admin, the reason text and the audit action identify it. Add a `source` column (it would be a new migration; none exists, there is no migration 009) only if the owner wants reports by origin.
  - **Partial** (amount below full, or several refunds): set `orders.admin_flag = 'external_partial_refund'` when the flag is NULL; otherwise write an audit entry only, because the code never overwrites a flag (`WebhookController.php:111`). Log it. **Never revoke and never finalise.** (The proposal to also block commission payout on `external_*` flags is NOT adopted: no commission hold by owner decision.)
  - **Pending:** do nothing. The next runs see it again while it is younger than 7 days.
- If the order is already `refunded`, skip it. If the order is `failed`, `cancelled` or `pending`, flag it `external_refund_review` and log it, because this should not happen.

**Webhook changes (same helpers, real-time path):**
- `refundProcessed` unknown branch (`:136-138`): call `recordExternal` instead of ignoring.
- Amount-differs branch (`:141-144`): set the partial flag.
- `refundFailed` on a row that is already `refunded`: flag `refund_failed_after_processed` and log it. Access is not restored automatically; a human decides.
- The reconciler then becomes a safety net, not the main path.

**Idempotency.** All writes go through `finalise()` and `markFailed()`. Both lock the refund row and are no-ops when already done (`RefundService.php:222-224`). Lock order stays refund, then payment and order, then commission, which is the existing documented order (`:15`). Webhook and cron racing on the same refund is therefore safe.

**Razorpay API rate limits.** Razorpay does not publish a fixed number; it answers `429` when you go too fast (verify in their docs). The design:
- caps each run at **30 Razorpay calls** across R1 and R2;
- stops the pass on the first `429` or `5xx` and counts it as an error, so the next run continues;
- relies on the existing 10 s timeout per call, so a run takes at most about 5 minutes, well inside the 15-minute cron interval (plus `flock -n` protection).

**Alerting.**
- Extend the output line to `paid= cancelled= errors= purged= refunds_finalised= refunds_failed= external_full= external_flagged= stuck=`.
- Exit codes:
  - 0: nothing to report;
  - 2: errors (existing behaviour);
  - **3: needs admin attention** (any flagged or stuck item). This exit code is new.
- The cron line should mail or ping on any non-zero exit, for example `MAILTO=` with a wrapper `|| mail ...`, or a free uptime/cron-monitor ping. Admins also see the flag badge in the order list. Add an "Orders with a flag" filter to `AdminOrderController` and `AdminOrdersPage` (small).

**Tests needed** (in `backend/tests/reconciler_test.php` and `refund_test.php`, using an in-test scripted Gateway like the existing pattern):
1. `processing` to Razorpay `processed`: order refunded, code and `book_access` revoked, commission left unchanged.
2. `processing` to `failed`: row `failed`, nothing else changes.
3. `processing` and `pending` for 6 days: counted as `stuck`, exit 3.
4. Timeout `failed` (no id) with a processed refund at Razorpay: finalised, id stored.
5. `failed` with an id: not queried.
6. External full on a `paid` order: one refund row created and finalised; a second run changes nothing.
7. External full while a customer request is open: that request finalised, no second row.
8. External partial: flag set, access and commission untouched, payout unaffected (no hold).
9. Refund for a payment that is not ours: ignored.
10. `429` from the gateway: pass stops, `errors=1`, exit 2.
11. Webhook and reconciler on the same refund: finalised once (forced-overlap race test, as in `race_test.php`).
12. `refund.failed` after processed: flagged, access not restored.

**Files to change.**
- `backend/src/Razorpay/{Gateway,RazorpayGateway,FakeGateway}.php`
- new `backend/src/Refunds/RefundReconciler.php`
- `backend/src/Refunds/RefundService.php` (`recordExternal`)
- `backend/src/Controllers/WebhookController.php`
- `backend/src/Referral/CommissionService.php` (no change: no hold by decision)
- `backend/scripts/reconcile_orders.php` (call, counts, exit 3)
- `backend/src/Controllers/AdminOrderController.php` and `src/pages/admin/AdminOrdersPage.jsx` (flag filter and labels)
- tests
- `backend/README.md` and the handover docs

**Effort.**

| Part | Estimate |
|---|---|
| Gateway methods | 1-2 h |
| R1 | 3-4 h |
| R2 and `recordExternal` | 4-6 h |
| Webhook changes | 1-2 h |
| Payout hold and admin filter (NOT built: no hold by decision) | n/a |
| Tests | 5-7 h |
| Docs | 1 h |
| Test-mode run | 2 h |
| **Total** | **about 2.5-3 working days** |

**Recommendation.** Build it before marketing drives volume (premerge item F-1). Until then, follow the manual runbook below and the rule "never refund from the Razorpay dashboard".

### 1.4 What to do manually until it is built (admin runbook)

1. **The rule.** Always refund from **Admin, Orders, (order), Refund this order**. Never from the Razorpay dashboard. The Razorpay order "receipt" is our order number (`backend/src/Orders/OrderService.php:88`), so orders are easy to match.
2. **Daily check, 5 minutes:**
   - In Admin, Refunds, set the filter to **processing** and then **failed**. Note any row older than 1 day.
   - In the Razorpay dashboard, open Refunds for the last 7 days. For every refund, find the order by its receipt (order number) in Admin, Orders. Its status should be **Refunded**.
3. **If a refund is `processed` in Razorpay but the app shows `processing` or `failed`, or shows the order `paid` with no refund row:**
   - Do **not** mark the commission paid.
   - If there is no refund row, click "Refund this order" once. Razorpay should refuse because the money is already refunded (verify on test mode), which leaves a `failed` row.
   - Ask the engineer to run a one-off finalise for that row with the Razorpay refund id. The engineer runs this on the server as the PHP user, from `backend/`, and deletes the file after use:
     ```php
     <?php // /tmp/finalise_refund.php  usage: php /tmp/finalise_refund.php <refundRowId> <rfnd_id>
     chdir('/path/to/backend'); require 'config/env.php'; loadEnv('.env');
     spl_autoload_register(function ($c) { $p = 'src/' . str_replace('\\', '/', substr($c, 4)) . '.php'; if (str_starts_with($c, 'App\\') && is_file($p)) require $p; });
     var_dump((new App\Refunds\RefundService(App\Razorpay\GatewayFactory::make()))->finalise((int) $argv[1], $argv[2], true));
     ```
     `true` means "money confirmed by Razorpay" and allows `requested`/`failed` rows (`RefundService.php:225`). Expect `bool(true)`. Try it on staging first.
4. **Partial refund done in the dashboard by mistake:**
   - Write it down.
   - Do not refund again from the app (it will fail).
   - Do not pay the commission.
   - Decide per case: to remove a digital book, use Admin, Order, Codes, **Revoke**.
5. **Refund that shows `refund.failed` in Razorpay after the app marked it Refunded:** contact the customer. Re-grant access by issuing a new code from Admin (reissue), or refund again from the dashboard and record it.
6. **Weekly:** ask the engineer for `grep "razorpay webhook" <php error log>` to catch "unknown refund" or "amount differs" lines.

---

## 2. API security headers

### 2.1 What was sent at `93f8384` (before the finalization pass; see the status section for what is sent now)

**API (nginx plus PHP):**

| Source | Headers |
|---|---|
| `backend/nginx/default.conf:1-27` | **None.** No `add_header`, no `server_tokens off`, port 80 only (TLS must be added on the host). It denies dot-files (`:24-26`) and returns 404 for `uploads/books/*/book.pdf` and `pages/` (`:20-22`). |
| `backend/public/index.php:22` | `Content-Type: application/json` on every PHP response |
| `backend/src/Middleware/Cors.php:9-20` | `Access-Control-Allow-Origin: <FRONTEND_ORIGIN>` (only if set), `-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS`, `-Allow-Headers: Content-Type, Authorization`, `Vary: Origin`; any `OPTIONS` gets 204 before routing |
| Per endpoint | `Cache-Control: no-store` on access-code views (`AccessCodeController.php:20,60`) and admin code verify (`AdminCodeController.php:64`); `Cache-Control: private, no-store` on `/access` (`ReaderController.php:34`) and page images, plus `X-Content-Type-Options: nosniff` on images (`:61-64`); `Retry-After: 5` on `render_busy` (`PdfPageRenderer.php:266`) |
| Everything else | No `Cache-Control`. This covers `/api/me`, `/api/me/orders`, every admin list, and the customer and partner data. |

**Frontend:**
- `public/.htaccess:23-36` (copied to `dist/`): `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN`, `no-cache` on `index.html`, one-year immutable cache on hashed bundles.
- `public/_redirects:1`: SPA fallback only, no headers.
- No `_headers` file.
- The nginx snippet in `docs/deployment/frontend-hosting.md:42-57` adds nosniff and Referrer-Policy only.
- There is no HSTS and no CSP anywhere.

### 2.2 What the built frontend actually loads

Found by searching `src/`, `index.html` and the built `dist/index.html` plus `dist/assets/*`:

| Origin | Where | Type |
|---|---|---|
| `https://cdnjs.cloudflare.com` | `index.html:7` (Font Awesome 6.4.0 CSS; its fonts load from the same host) | style, font |
| `https://fonts.googleapis.com`, `https://fonts.gstatic.com` | `index.html:8` (CSS, then font files) | style, font |
| `https://checkout.razorpay.com` | `src/utils/razorpay.js:1` (script injected at checkout) | script; Checkout then opens frames and calls on `*.razorpay.com` (exact hosts: verify on test mode) |
| API origin (`VITE_API_URL`) | `src/api/client.js:1,12` (fetch), `assetUrl()` `:4-6` (cover `<img>`), `src/api/reader.js:12` (page fetch), `ReaderViewer.jsx:153` (index PDF in an **iframe**), `:157` (index image) | connect, img, frame |
| `blob:` | `src/api/reader.js:20` (page images become blob URLs, shown at `ReaderViewer.jsx:140`) | img |
| `https://images.unsplash.com` | `src/styles/site.css:199` (hero background) | img |
| `https://via.placeholder.com` | `src/components/home/Header.jsx:37` (logo fallback; the service is unreliable, so remove it) | img |
| `www.google.com/maps`, `wa.me`, `tel:`, `mailto:` | `Footer.jsx:4,10,14,21,100` | navigation only; CSP does not apply |

The page has no inline `<script>` or `<style>` tags (both index files checked). The 21 `style={{...}}` props in JSX are applied by React through the DOM style API, which CSP does not block.

**Observation.** The `dist/` currently in the working tree was built **without** `VITE_API_URL`: `dist/assets/index-CeuM5FL_.js` contains `localhost:8080`. Do not upload this `dist/`; rebuild with the variable set (premerge A26 / F-9).

### 2.3 Recommended API headers (nginx)

Put the following in the **HTTPS** server block. If TLS ends at a proxy in front of the container nginx, put HSTS on the proxy instead.

```nginx
server_tokens off;

# Applies to PHP (JSON) responses. nginx drops these in any location that sets its own add_header.
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "no-referrer" always;
add_header X-Frame-Options "DENY" always;
add_header Content-Security-Policy "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'" always;
add_header Strict-Transport-Security "max-age=300" always;   # step up: 300 -> 86400 -> 15552000; no includeSubDomains/preload yet

# Must come BEFORE "location ~ \.php$": regex locations are tried in file order.
location ~* ^/uploads/.*\.(php|phtml|phar)$ { return 404; }

# Covers, previews, index files. The reader shows the index PDF in an iframe on the FRONTEND,
# so this path must NOT send X-Frame-Options DENY / frame-ancestors 'none'.
location /uploads/ {
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer" always;
    add_header Content-Security-Policy "frame-ancestors https://www.example.com" always;  # = FRONTEND_ORIGIN
    add_header Strict-Transport-Security "max-age=300" always;
    try_files $uri =404;
}
# Keep the existing book.pdf/pages 404 regex and the dot-file deny (regexes still win over /uploads/).
```

Also in PHP, add one line after `index.php:22`: `header('Cache-Control: no-store');`. This becomes the default for every API response. Controllers that set their own `Cache-Control` replace it, because `header()` replaces by default. Doing it in PHP rather than nginx avoids two `Cache-Control` headers.

**HSTS** is a header that tells browsers "only ever use HTTPS for this host". Add it only after HTTPS works on that exact host. Start at 300 seconds, raise it to 1 day after a week, then to 6 months. Do not add `includeSubDomains` or `preload` until every subdomain is on HTTPS.

**Effort:** 1-2 h, plus a test with the curl checks in 5.6.

### 2.4 Recommended frontend headers

#### Content Security Policy (CSP), Report-Only first

A CSP is a browser rule list saying which sites the page may load code, styles, images and frames from. Report-Only mode logs violations in the browser console without blocking anything. Replace `API_HOST` with the real API host:

```
default-src 'self';
script-src 'self' https://checkout.razorpay.com;
style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com;
font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com;
img-src 'self' data: blob: https://API_HOST https://images.unsplash.com https://via.placeholder.com https://*.razorpay.com;
connect-src 'self' https://API_HOST https://*.razorpay.com;
frame-src https://API_HOST https://api.razorpay.com https://checkout.razorpay.com;
object-src 'none';
base-uri 'self';
form-action 'self';
frame-ancestors 'none'
```

Why each directive:

| Directive | Rationale |
|---|---|
| `default-src 'self'` | Anything not listed below may come only from the site itself. |
| `script-src` | The site's own bundle plus Razorpay Checkout (`razorpay.js:1`). No `'unsafe-inline'` and no `'unsafe-eval'`; neither is needed (no inline scripts in `dist/index.html`). This is the line that actually protects the tokens (section 3). |
| `style-src` | Font Awesome and Google Fonts CSS. `'unsafe-inline'` is kept because Razorpay Checkout injects styles (verify on test mode). Inline styles are a low risk compared with scripts. |
| `font-src` | Font Awesome webfonts on cdnjs and Google font files. `data:` covers any embedded font. |
| `img-src` | `blob:` for reader pages, the API host for covers and index images, Unsplash for the hero, and the placeholder (drop it once `Header.jsx:37` is removed). `*.razorpay.com` for Checkout logos (exact host: verify on test mode). |
| `connect-src` | API calls; Razorpay Checkout also calls its own hosts (for example `api.razorpay.com`, and possibly a logging host). The wildcard avoids breakage; narrow it after reading the reports. |
| `frame-src` | The API host for the index-PDF iframe (`ReaderViewer.jsx:153`); `api.razorpay.com` and `checkout.razorpay.com` for the payment popup. |
| `object-src 'none'`, `base-uri 'self'` | Closes old plugin and base-tag tricks. |
| `form-action 'self'` | The site has no cross-site form posts. Razorpay bank pages run inside Razorpay's own frame, so this does not apply to them (verify on test mode with netbanking). |
| `frame-ancestors 'none'` | Nobody may frame the site (clickjacking). It replaces `X-Frame-Options`; keep both for old browsers. |

What **breaks** under this CSP if a line is wrong or missing:
- Without `checkout.razorpay.com` in `script-src`, the popup never opens ("Failed to load Razorpay").
- Without `api.razorpay.com` in `frame-src`, the popup is blank.
- Without the API host in `connect-src`, every page fails. In `img-src`, covers break. In `frame-src`, the reader index is blank.
- Without `blob:` in `img-src`, reader pages are blank.
- Removing `'unsafe-inline'` from `style-src` probably breaks the Checkout styling (verify on test mode).
- A wrong API host breaks everything, which is why the CSP starts in Report-Only.
- A CSP cannot be set with a `<meta>` tag in Report-Only mode, so it must be an HTTP header.

#### Per host

**Apache / Hostinger (`public/.htaccess`, inside the existing `<IfModule mod_headers.c>`):**
```apache
Header always set Content-Security-Policy-Report-Only "default-src 'self'; script-src 'self' https://checkout.razorpay.com; ...(as above)..."
Header always set Strict-Transport-Security "max-age=300" "expr=%{HTTPS} == 'on'"
Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
```
On a host that runs LiteSpeed instead of Apache (Hostinger's shared plans do), whether `.htaccess` `Header` directives and `expr=` are honoured must be verified on the host. If `expr=` is not supported, drop it and only add HSTS once HTTPS is forced.

**nginx:** add the same lines to all three `location` blocks in `frontend-hosting.md` with `always`. nginx does not inherit `add_header` into locations that define their own.

**Netlify / Cloudflare Pages (`public/_headers`, new file copied to `dist/`):**
```
/*
  Content-Security-Policy-Report-Only: default-src 'self'; script-src 'self' https://checkout.razorpay.com; ...
  X-Content-Type-Options: nosniff
  Referrer-Policy: strict-origin-when-cross-origin
  X-Frame-Options: DENY
  Strict-Transport-Security: max-age=300
/index.html
  Cache-Control: no-cache
/assets/*
  Cache-Control: public, max-age=31536000, immutable
```

**Vercel:** the same headers go in `vercel.json` under `headers`. Vercel also needs a rewrite to `/index.html`, because neither `.htaccess` nor `_redirects` applies there.

**Also, cheap and recommended:** add `integrity="sha384-..." crossorigin="anonymous"` (Subresource Integrity, SRI) to the Font Awesome `<link>` at `index.html:7`, so a tampered CDN file is refused. cdnjs publishes the hash. Google Fonts CSS changes per browser, so it cannot use SRI.

#### Rollout plan
1. Ship Report-Only together with the other headers.
2. On test mode, click through every page with the browser console open: home, books, detail, checkout with card, UPI and netbanking, the result page, My Books, the reader (pages and index), track, referral register, and all admin pages including uploads. Fix every reported violation.
3. After a week with no new violations, rename the header to `Content-Security-Policy` (enforcing) and keep watching.
4. Optionally add a `report-uri` collector later. Without one, reports are visible only in each tester's console.

**Effort:** 0.5 day to write and deploy, plus 2-3 h of test-mode click-through, plus a 1 h switch to enforcing.

### 2.5 CORS review (`backend/src/Middleware/Cors.php`)

CORS (cross-origin resource sharing) is the set of rules that lets the frontend's site call the API on another domain.

- **Exact origin: correct.** One origin from `FRONTEND_ORIGIN`. With the variable empty, no allow header is sent, so the API fails closed. It is sent to every caller, which is harmless because browsers compare it with their own origin. `check_config.php:60-66` validates the format and HTTPS.
- **Credentials: correct for today.** There is no `Access-Control-Allow-Credentials` and the API uses Bearer tokens, not cookies, so CSRF (forged requests riding on a logged-in browser) does not apply. This changes if cookies are adopted (section 3).
- **`Authorization` allowed: correct** (`:14`).
- **Preflight caching: missing.** There is no `Access-Control-Max-Age`, so browsers repeat the preflight `OPTIONS` request very often; Chrome's default cache is 5 s. Add `header('Access-Control-Max-Age: 600');`. This is 5 min of work and saves one round-trip per API call.
- **Error responses:** the CORS headers are sent before routing, so JSON errors are readable by the frontend. Errors produced by nginx itself (`413` body too large, `502`/`504`) have no CORS headers, and the browser shows them as a generic network error. This is a low-priority annoyance; add `always` CORS headers in nginx only if it confuses admins during uploads.
- **`Vary: Origin`:** harmless and fine.

---

## 3. Token storage

### 3.1 Where the tokens live today

| Item | Storage key | Written | Read | Cleared |
|---|---|---|---|---|
| Customer JWT (7 days: `AuthController.php:54,86`) | `localStorage['vtee-auth-token']` | `AccountPage.jsx:39` | `AccountPage.jsx:24`, `CheckoutPage.jsx:24`, `MyBooksPage.jsx:17`, `OrderResultPage.jsx:62`, `ReaderPage.jsx:16` | Logout only (`AccountPage.jsx:52`) |
| Admin JWT (2 hours: `AdminController.php:37`) | `localStorage['vtee-admin-token']` | `AdminLoginPage.jsx:18` | `api/admin.js:12`, `AdminBooksPage.jsx:8`, `AdminLayout.jsx:17` | Logout (`AdminLayout.jsx:32`), and on any 401 or 403 (`api/admin.js:14-16`) |
| UI session (customer email, admin username; not a secret) | `localStorage['vtee-session']` | `SessionContext.jsx:42` | `:10` | Through the logout reducers |
| Referral code | `localStorage['vtee-referral']` | `utils/referral.js:7` (regex-filtered) | `:15` | Never |

There is no `sessionStorage` and no `document.cookie` use.

- **Token format.** HS256, `iat` and `exp` only, and the role in a `role` claim (`backend/src/Auth/Jwt.php:9-21`). The signature is checked with `hash_equals` (`:36`) and `exp` is enforced (`:45`). There is no `nbf`/`iss`/`aud` and no server-side revocation list.
- **Logout** is client-only. A copied token keeps working until it expires: 7 days for customers, 2 hours for admins. The only global kill switch is changing `JWT_SECRET`, which logs everyone out.
- **401/403 handling.**
  - Admin: clean (cleared, then back to login).
  - Customer: there is **no global handler**. An expired customer token leaves the UI "logged in", because that is driven by `vtee-session`, not by token validity. The Account page just shows the API message. The Reader shows a "Log in" link on 401 (`ReaderPage.jsx:32`). This is a small UX gap: clear the token and the session on any customer 401. Effort 1-2 h.
- **Server side.** `AuthMiddleware.php:12` reads only `$_SERVER['HTTP_AUTHORIZATION']`. This matters for Apache (section 5).

### 3.2 XSS surface

XSS (cross-site scripting) means someone else's script running inside our page. If that happens, it can read anything in `localStorage`, including the admin token.

- **Third-party scripts.** Only Razorpay Checkout (`razorpay.js:1`), and only once checkout is opened. Once loaded it runs with full access to our origin's `localStorage`. That is inherent to any third-party script and is accepted for a payment provider. Font Awesome and Google Fonts are CSS only; CSS cannot read storage. Adding SRI on Font Awesome removes most of that supply-chain risk.
- **User-supplied text rendered.**
  - Public referral applications (shown in the admin panel), refund reasons, addresses, names, and book titles and descriptions.
  - All of it is rendered as JSX text, which React escapes.
  - There is no `dangerouslySetInnerHTML`, `innerHTML`, `eval` or `new Function` in `src/` (searched).
- **URL handling.**
  - `href`/`src` values built from data are `coverUrl`, `indexUrl` and blob URLs. The cover and index paths are generated by the server at upload time (`AdminBookController.php:112,131`), never typed by an admin, and `assetUrl()` only prefixes paths that start with `/`.
  - The `?ref=` value is filtered to `[A-Z0-9_-]` (`referral.js:7`).
  - There is no redirect taken from the query string.
  - Uploads accept only JPEG, PNG, WebP and PDF (`FileValidator.php:9`; no SVG), so no script-bearing image can be served from the API host.
- **Conclusion.** No XSS was found. The token risk is "an XSS appears later, or a third-party script is compromised". CSP is the main defence in every option below, because even httpOnly cookies do not stop an injected script from *using* the session while the page is open. Cookies only stop it from *stealing* the token.

### 3.3 Options

| Option | What changes | Pros | Cons | Effort |
|---|---|---|---|---|
| **1. Keep `localStorage` + strict CSP + short-lived tokens** | Enforce the CSP (section 2.4) and SRI. Shorten the admin token to 1 h (it is 2 h). Customer stays at 7 days or drops to 1-3 days: this is a UX trade-off, since there is no refresh token and customers would log in more often. Add a global customer 401 handler. Optionally add a `token_version` column on `admins` so "log out everywhere" works. | No architecture change; works with any hosting layout, including a Netlify frontend with the API on another domain. | A successful XSS can still copy the token and use it elsewhere until it expires. | 0.5 day on top of the CSP work; revocation +0.5 day |
| **2. httpOnly Secure SameSite cookies + CSRF protection** | Login responses send `Set-Cookie: vtee_admin=<jwt>; HttpOnly; Secure; SameSite=Strict; Path=/api` (host-only on the API host), and the same for customers. A new `POST /api/auth/logout` clears the cookie. `AuthMiddleware` reads the cookie when there is no `Authorization` header (keep the header so `http_test.sh`/`order_test.sh` and CLI tests still work). CORS adds `Access-Control-Allow-Credentials: true`; the exact origin is already in place. `client.js` and `reader.js` use `credentials: 'include'` and stop sending tokens; the pages stop reading `localStorage` (about 10 call sites listed above). **CSRF:** on every non-GET request, check that the `Origin` header equals `FRONTEND_ORIGIN` (a few lines in `index.php`). | XSS can no longer steal the token. | **Domain requirement.** The frontend and API must be *same-site*, meaning the same registrable domain: for example `www.example.com` and `api.example.com`. If the frontend is on `*.netlify.app` or `*.pages.dev` and the API on your own domain, the cookie is a third-party cookie and Safari/Firefox (and increasingly Chrome) block it, so login silently fails. More moving parts; every test helper that uses Bearer tokens keeps working only because the header path stays. | 2-3 days including tests |
| **3. Hybrid: admin on cookies first** | Option 2 for the admin login and admin routes only (`/api/admin/*`, `Path=/api/admin`). Customers stay on Bearer plus CSP. | Protects the one token that matters most (refunds, codes, payouts) at half the cost. | Two auth styles to maintain. The same domain requirement applies to the admin panel. | 1-1.5 days |

**Recommendation.**
- **For launch:** option 1. Use the CSP from section 2.4 (enforced, not just Report-Only, before launch if test mode allows), SRI, an admin token of 1 h, and the customer 401 handler.
- **Later, within 1-2 months of launch:** option 3. Move the admin to cookies once the domain layout is fixed.

**The decision the owner must make now.** Will the website and the API live on the **same registrable domain**, for example `www.<yourdomain>` and `api.<yourdomain>`?
- If yes, options 2 and 3 stay open, and this is also the simplest CORS setup.
- If the frontend stays on a platform domain (`*.netlify.app`, `*.hostingersite.com`, etc.), cookies are effectively off the table, and option 1 plus a strong CSP is the long-term answer.

Also decide the customer token lifetime: 7 days (convenience) or 1-3 days (safety).

---

## 4. Proxy-aware rate limiting

### 4.1 How the client IP was derived at `93f8384` (now `ClientIp::bucket()`, see the status section)

There is exactly one source: `$_SERVER['REMOTE_ADDR']`. It is the address of whatever connected to PHP. Through nginx's `fastcgi_params` that is the address nginx saw. `X-Forwarded-For` and `CF-Connecting-IP` are not read anywhere (searched `backend/src`, `scripts`, `public`, `config`). `RateLimiter` stores the bucket string as given, in fixed windows (`backend/src/Support/RateLimiter.php:52-68`).

Every rate-limit key:

| Key | Based on | Limit | Where |
|---|---|---|---|
| `login-user:<sha1(email)>` | account | 10 failures / 15 min | `AuthController.php:72` |
| `login-ip:<IP>` | **IP** | 30 failures / 15 min | `AuthController.php:72` |
| `admin-login-user:<sha1(username)>` | account | 5 failures / 15 min | `AdminController.php:23` |
| `admin-login-ip:<IP>` | **IP** | 15 failures / 15 min | `AdminController.php:23` |
| `code-redeem-user:<userId>` | user | 5 failures / 15 min | `AccessCodeController.php:28` |
| `code-redeem-ip:<IP>` | **IP** | 20 failures / 15 min | `AccessCodeController.php:28` |
| `code-reissue:<userId>` | user | 3 / day | `AccessCodeController.php:58` |
| `reader-page:<userId>` | user | 240 / 5 min | `ReaderController.php:42` |
| `order-create:<userId>` | user | 20 / hour | `OrderService.php:25` |
| `admin-code-verify:<adminId>` | admin | 30 / 10 min | `AdminCodeController.php:19` |
| `admin-code-reissue:<adminId>` | admin | 30 / 10 min | `AdminCodeController.php:61` |
| `referral-apply:<IP>` | **IP** | 5 / hour | `ReferralApplicationController.php:42` |
| `referral-validate:<IP>` | **IP** | 30 / min | `ReferralController.php:12` |
| `track:<IP>` | **IP** | 10 / min | `TrackController.php:23` |

Not limited at all: `POST /api/auth/register` and the webhook (which is signed, so that is fine). The customer refund request no longer exists.

### 4.2 What goes wrong behind a proxy

A proxy is any server that sits between visitors and our nginx: a CDN such as Cloudflare, a load balancer, a TLS terminator such as Caddy or Traefik in front of Docker, or a shared host's front server.

- **Everyone shares one bucket.** `REMOTE_ADDR` becomes the proxy's address for every visitor. For example, 5 referral applications per hour *for the whole world*, 10 track lookups per minute for everybody, and 30 failed customer logins in 15 minutes, after which nobody can log in. One attacker locks everyone out.
- **The Docker case.** This also happens on a VPS with Docker if a TLS proxy runs in front of the container nginx. `REMOTE_ADDR` is then the Docker network gateway (verify on host).
- **The naive fix is dangerous.** Reading `X-Forwarded-For` directly lets any attacker send `X-Forwarded-For: <random>` on each request and get a fresh bucket every time, which defeats every IP limit.
- **IPv6.** Each full address gets its own bucket. Ordinary home connections get a whole /64 block (18 quintillion addresses), so an attacker can rotate addresses for free.
- **Targeted lockout (separate, minor issue).** The per-account login buckets let anyone lock a known admin username out for 15 minutes with 5 bad passwords. Mitigation: an admin username that is not public.

### 4.3 Safe design

**Preferred: fix it in the web server**, so `REMOTE_ADDR` is already correct and the PHP code stays unchanged.

nginx, with the `realip` module, which is included in the official nginx images:
```nginx
# Only these addresses are allowed to tell us the client IP:
set_real_ip_from 172.16.0.0/12;      # e.g. the Docker network / your TLS proxy (use the exact range)
real_ip_header X-Forwarded-For;
real_ip_recursive on;                # walk right-to-left, skipping trusted hops
```

Behind **Cloudflare**, list Cloudflare's published ranges (https://www.cloudflare.com/ips-v4 and `/ips-v6`; refresh them periodically) and use its dedicated header:
```nginx
set_real_ip_from 173.245.48.0/20;   # ...every Cloudflare v4 and v6 range...
real_ip_header CF-Connecting-IP;
```
Also firewall the origin so only Cloudflare can reach it. Otherwise an attacker can connect directly and forge the header.

**Apache** (`mod_remoteip`, server config, not `.htaccess`):
```apache
RemoteIPHeader X-Forwarded-For
RemoteIPTrustedProxy 10.0.0.0/8      # exact proxy range only
```

**Shared hosting (LiteSpeed):** you cannot change this yourself. The host decides whether `REMOTE_ADDR` is the visitor. Verify on host with the procedure in 4.4.

**App-level fallback, if the server config cannot be changed.** Add one small helper, `App\Support\ClientIp::bucket()`:
1. Read a `TRUSTED_PROXIES` environment variable (comma-separated CIDRs; empty by default, which means today's behaviour).
2. If `REMOTE_ADDR` is **not** in that list, use `REMOTE_ADDR`. The header is ignored, so it cannot be spoofed.
3. If it is in the list, split `X-Forwarded-For` on commas and walk **right to left**, skipping addresses that are themselves trusted. Use the first valid untrusted IP (`filter_var(..., FILTER_VALIDATE_IP)`). The left-most entries are whatever the client typed, so they are never trusted.
4. IPv4: use the address as is. IPv6: `inet_pton`, zero the last 8 bytes, `inet_ntop`, and add `/64`. One bucket per /64.
5. Replace the 6 `$_SERVER['REMOTE_ADDR'] ?? 'unknown'` call sites listed in 4.1 with `ClientIp::bucket()`.

Do the IPv6 /64 grouping in either case; it is independent of proxies.

**Tests.** One `client_ip_test.php` with table cases:
- no proxy;
- trusted proxy with one hop;
- trusted proxy with a spoofed left-most entry;
- an untrusted `REMOTE_ADDR` carrying a header (header ignored);
- a garbage header;
- IPv6 /64 grouping;
- an IPv4-mapped IPv6 address.

Plus one `http_test.sh` case: 11 track requests with rotating `X-Forwarded-For` values still produce a 429.

**Effort.**
- nginx/Apache config only: 1 h plus verification.
- App-level `ClientIp` with IPv6 /64 and tests: 0.5 day.
- Recommendation: do the IPv6 /64 change now (0.5 day including tests). Use server config for the proxy part. Add `TRUSTED_PROXIES` only if the host forces it.

### 4.4 Verification after deploy (no secrets exposed)

1. From your phone on mobile data (not office Wi-Fi), find your public IP (any "what is my IP" site).
2. Request `GET https://<api>/api/track/AAAAAAAA` 11 times within a minute (a browser refresh or `curl` works). The 11th request must return **429**.
3. Immediately, from a **different** network (office Wi-Fi or a laptop), request the same URL once. It must return **404** (unknown code), **not 429**. If it returns 429, everyone shares one bucket.
4. The engineer runs `SELECT bucket FROM rate_limits WHERE bucket LIKE 'track:%' ORDER BY window_start DESC LIMIT 5;`. It must show your phone's IP (or its /64), not a `10.x`, `172.x` or proxy address.
5. Spoof test: `for i in $(seq 1 11); do curl -s -o /dev/null -w "%{http_code}\n" -H "X-Forwarded-For: 1.2.3.$i" https://<api>/api/track/AAAAAAAA; done`. The last request must still be **429**, and no `track:1.2.3.x` bucket may appear.
6. Delete the test rows afterwards: `DELETE FROM rate_limits WHERE bucket LIKE 'track:%';`.

---

## 5. Hosting compatibility

### 5.1 Requirements taken from the code

| Requirement | Evidence | Notes |
|---|---|---|
| **PHP 8.0 minimum by syntax; now tested on 8.4 (earlier 8.2)** | `match` (`WebhookController.php:76`), `mixed`, `array\|false`, `str_starts_with`; no 8.1+ syntax found | The repository image is now `php:8.4-fpm-trixie` and the full suite passes on it (the rollback image is `vtee-php:8.2-trixie-rollback`). PHP 8.2 security support ends 2026-12-31. Use 8.4 in production. |
| Extension **pdo_mysql** | `Database/Db.php:19-24` | `charset=utf8mb4` in the DSN |
| Extension **curl** | `Razorpay/RazorpayGateway.php` | All Razorpay calls |
| Extension **openssl** | `Codes/CodeCipher.php` (AES-256-GCM) | Access codes |
| Extension **mbstring** | 7 files, e.g. `RefundService.php:323` | |
| Extension **fileinfo** | `Upload/FileValidator.php`, `PdfPageRenderer.php`, `check_config.php` | Upload type check |
| Extension **ctype** | `AdminBookController.php`, `AdminCommissionController.php`, `AdminOrderController.php` | |
| Core (always on) | json, hash (`hash_hmac`), random (`random_bytes`), password, pcre | |
| Not used | sodium, gd, imagick, intl, pcntl, posix | |
| **`putenv`/`getenv`** must not be disabled | `config/env.php:19`; read in `Jwt.php`, `CodeCipher.php`, `Db.php`, `Cors.php`, `Razorpay/Config.php`, `Env.php` | Some shared hosts disable `putenv`. The app would then fail on every request (verify on host). |
| **`proc_open`, `proc_get_status`, `proc_terminate`, `proc_close`, `stream_select`** | `Reader/PdfPageRenderer.php:165-212` | Shared hosts often list these in `disable_functions`. On PHP 8 a disabled function is a fatal `Error`; the `@` does not suppress it, so the reader returns a **500**, not `render_failed`. |
| **Poppler `pdfinfo` + `pdftoppm`** binaries | `PdfPageRenderer.php:55,123,217`; installed in `Dockerfile:14-16` | Found through `PATH`. A pre-rendered page skips them (5.4). |
| **`/usr/bin/prlimit`** (Linux util-linux) | `PdfPageRenderer.php:167-170` | Optional; used when present. |
| **`flock()`** on local files | `PdfPageRenderer.php:106` | Needs a local filesystem. Network filesystems such as NFS may not honour it, so do not run several app servers on a shared disk. |
| **Cron** every 15 min, with the `flock` command | `backend/README.md` cron section, handover §6.10 | Mandatory (premerge D-8). If the `flock` command is missing, overlapping runs are still safe in practice, because every write locks rows and is idempotent. |
| **Writable `backend/storage/books`, outside the web root** | `PdfPageRenderer.php:22`, `AdminBookController.php:15`, checked in `check_config.php:97-101` | Path fixed relative to the code (`__DIR__ . '/../../storage/books'`) |
| **Writable `backend/public/uploads/books`** | `AdminBookController.php:14`, `check_config.php:102-103` | Covers, previews and index files, served as static files from the API host |
| **Upload limits** 50 MB PDF, 5 MB images | `FileValidator.php:10-11`; `php/uploads.ini` (50M/55M); nginx `client_max_body_size 50M` (`default.conf:5`) | The host's PHP and web server limits must be at least 55 MB. |
| **Front controller** | `backend/public/index.php`; router matches the full path, `/api/...` included (`Router/Router.php:34-40`) | The API must sit at the **root** of its host (e.g. `api.example.com`), not in a sub-folder. `backend/public` has **no `.htaccess`**. nginx does `try_files $uri /index.php?$query_string` (`default.conf:7-9`), denies dot-files (`:24-26`) and 404s `book.pdf`/`pages/` (`:20-22`). |
| **`Authorization` header must reach PHP** | `AuthMiddleware.php:12` reads only `HTTP_AUTHORIZATION` | Apache with PHP-FPM or CGI drops it unless `CGIPassAuth On` or a rewrite rule copies it. After a rewrite it can arrive as `REDIRECT_HTTP_AUTHORIZATION`, which the code does not read. Fix with `.htaccess` (5.4) plus a one-line fallback in `AuthMiddleware`. |
| **HTTPS** | Razorpay webhooks; `check_config.php:65` requires an https `FRONTEND_ORIGIN` | |
| **PHP-FPM workers ≥ 10** | handover §6.6; the Docker image default is 5 (hardening report) | Not set in the repo. Needs a pool override file (`pm.max_children = 10`) in the image or on the host. |
| **Timeouts** | Worst first render of one page: 10 s lock wait + up to 30 s `pdfinfo` + up to 30 s `pdftoppm` (`PdfPageRenderer.php:24-25,105-123`); typical is about 2 s | `max_execution_time` counts PHP CPU time on Linux, not time spent waiting for child processes, so the default 30 s is fine. nginx `fastcgi_read_timeout` (default 60 s) or a host wall-clock limit could cut off the pathological case; the reader shows Retry. |
| **`.env` outside the web root** | `index.php:4` loads `__DIR__/../.env` | Never upload `backend/` itself into `public_html`. |

### 5.2 MySQL features used by migrations 001-008, and MariaDB

MariaDB is the MySQL-compatible database that most shared hosts provide instead of MySQL.

| Feature | Where | MySQL 8.0/8.4 | MariaDB 10.6+ / 10.11 / 11.4 |
|---|---|---|---|
| STORED generated columns | `004_refunds.sql:14`; `008_partner_email_unique.sql` (2 columns) | yes | yes (`STORED` is accepted as a synonym of `PERSISTENT` since 10.2) |
| UNIQUE key on a generated, nullable column | `004:17`, `008` | yes | yes |
| `CHARACTER SET ... COLLATE utf8mb4_bin` on a generated column | `008` | yes | expected yes; **verify by applying 008 on MariaDB** |
| `JSON` column type | `003:32,72`; `001` `payments.raw_response` | native JSON | yes (an alias for LONGTEXT plus a `JSON_VALID` check); inserts work the same |
| `ENUM` and `MODIFY COLUMN ... ENUM` | `003`, `004:25` | yes | yes |
| `ADD COLUMN ... AFTER` | `007` | yes | yes |
| `INSERT IGNORE`, `ON DUPLICATE KEY UPDATE`, `GREATEST` | `003:76`, `RateLimiter.php:25,54` | yes | yes |
| `SELECT ... FOR UPDATE` | `RefundService`, `OrderReconciler`, etc. | yes | yes |
| `SKIP LOCKED` / `NOWAIT`, `CHECK` constraints, CTEs, window functions, JSON functions, `IF NOT EXISTS` forms | **not used** (searched) | n/a | n/a |
| Duplicate-key detection | `Db::isDuplicateKey()` (`Db.php:30-34`) accepts both `for key 'table.key'` (MySQL 8) and `for key 'key'` (MariaDB) | yes | yes |
| Deadlock retry on SQLSTATE `40001` | `RefundService.php:289` | yes | yes (same code) |
| `utf8mb4_0900_ai_ci` collation | not named in the migrations; MySQL 8 uses it as the table default | yes | Not supported on older MariaDB. This matters only when you **import a MySQL 8 dump** (the existing live SP-4 database) into MariaDB: rewrite the collation in the dump, or check that the MariaDB version accepts it (verify). |
| Default character set | the DSN forces `utf8mb4` | `utf8mb4` | Create the database explicitly with `CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`. A `latin1` default would break the Tamil titles. |

**Conclusion.** Nothing in the code or migrations is MySQL-8-only. A typical shared-hosting MariaDB 10.6 or later should work. This was checked statically only: before relying on it, apply 001-008 on the target MariaDB and run `order_test.sh` and `refund_test.php` against it. Allow 2-3 h.

### 5.3 Compatibility matrix

| Host type | Verdict | Why / what is needed |
|---|---|---|
| **(a) VPS or cloud VM, Docker (the dev stack) or nginx + PHP-FPM** | **GO** | Everything is under your control: Poppler, `prlimit`, `proc_open`, cron with `flock`, the FPM worker count, real IP, headers. Needs: TLS (Caddy, Traefik or certbot); the FPM pool override (`pm.max_children=10`); pinned nginx and MySQL 8.4 images; backups of the database, `storage/` and `public/uploads/`. Effort about 1 day to production-harden the compose file, plus 0.5 day of verification. |
| **(b) Apache/LiteSpeed shared hosting (cPanel, hPanel, Hostinger)** | **Frontend: GO. API: NO-GO as-is; conditional with changes** | The repo already ships `public/.htaccess` for Hostinger and cPanel *frontends* (`docs/deployment/frontend-hosting.md:30`). The premerge review warns about the *API* there (premerge D-3 / E-3). Blockers listed in 5.4. |
| **(c) Container PaaS (Render, Railway, Fly.io, AWS App Runner)** | **Conditional** | The filesystem is wiped on each deploy. Needs: (1) a persistent volume mounted at `backend/storage` **and** `backend/public/uploads` (or move uploads under storage), otherwise uploaded PDFs, the page cache and covers vanish; (2) a single instance only (local `flock`, local cache); (3) one image that serves HTTP (today's image is php-fpm only and needs nginx in the same container); (4) cron as a scheduled job reaching the same database; (5) a managed MySQL or MariaDB (Render has no managed MySQL: verify; use an external provider). App Runner has no volumes, so it is **no-go** without moving files to object storage (S3 and similar), which is a 3-5 day code change. Effort with a volume: 1-2 days. |
| **(d) Static frontend hosts (Netlify, Cloudflare Pages, Vercel), frontend only** | **GO** | `_redirects` covers Netlify and Cloudflare. Vercel needs its own rewrite in `vercel.json`. Set `VITE_API_URL` at build time. `FRONTEND_ORIGIN` on the API must be the *custom* domain exactly; preview URLs will (correctly) fail CORS. Headers go in `_headers` (2.4). Using the platform's own domain rules out cookie auth later (3.3). |

### 5.4 Shared hosting for the API: what blocks, and minimal changes if the owner insists

**What blocks** (each needs to be checked with the host):
1. **No Poppler** (`pdfinfo`/`pdftoppm`) and no way to install it. The reader cannot render pages; `/access` returns no `pageCount`, so "not available yet".
2. **`proc_open` probably disabled.** Any page not already rendered gives a 500.
3. **No `.htaccess` in `backend/public`.** Every `/api/...` route returns 404 (the host's own page), and the `book.pdf`/dot-file protections do not exist.
4. **`Authorization` header may be stripped.** Every logged-in call returns 401 `unauthorized`.
5. **Worker count, timeouts and upload size** are plan limits ("entry processes", LiteSpeed limits) that you cannot tune.
6. **Real client IP** depends on the host's front servers.
7. **Possibly `putenv` disabled.**
8. **Cron:** usually available in the panel; `flock` may be missing (acceptable, see 5.1).
9. **Database is usually MariaDB** (see 5.2).

**Minimal changes to make it work:**
- **`backend/public/.htaccess` (new):**
  ```apache
  RewriteEngine On
  RewriteRule ^uploads/books/[^/]+/(book\.pdf|pages/) - [R=404,L]
  RewriteRule (^|/)\.(?!well-known) - [R=404,L]
  RewriteRule ^uploads/.*\.(php|phtml|phar)$ - [R=404,L]
  RewriteCond %{HTTP:Authorization} .
  RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteRule ^ index.php [L,QSA]
  <IfModule mod_headers.c> ...API headers from 2.3... </IfModule>
  ```
  Add to `AuthMiddleware.php:12` a fallback: `?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''`.
  Effort 2-3 h including a test on the real host.
- **Folder layout.** Point the API subdomain's document root at `backend/public`, with `src/`, `config/`, `storage/`, `scripts/` and `.env` one level above it. hPanel and cPanel allow a custom document root for a subdomain (verify on host).
- **Reader without Poppler.** Options, best first:
  1. **Pre-render on another machine (no code change).** On a laptop or VPS with Poppler, run `pdftoppm -jpeg -r 110 book.pdf p`. Rename the outputs to `1.jpg`…`N.jpg`, upload them to `storage/books/{id}/pages/`, and run `UPDATE books SET page_count = N WHERE id = {id};`. The code serves cached pages without calling any tool (`PdfPageRenderer.php:46-48` page count from the database, `:90-92` cached page). Caveat: re-uploading the PDF in Admin wipes the pages and the count (`AdminBookController.php:182`), so this must be repeated after every PDF change. Effort: a small helper script (2-3 h), then about 30 min per book.
  2. **Imagick + Ghostscript**, if the host has both and the ImageMagick policy allows PDF. That is rare on shared hosts. A second renderer is about 1 day of work and the output quality differs; verify on host first.
  3. *(Rejected)* Rendering in the browser with pdf.js would send the whole PDF to the browser, which breaks the design ("no PDF ever sent to the browser").
- **Total if insisted:** about 1-1.5 days of engineering plus the per-book work, and permanent operational care. **Not recommended for the API.** It is fine for the frontend.

### 5.5 Recommended hosting for launch

- **Frontend:** the existing Hostinger shared plan, or Netlify/Cloudflare Pages, on `https://www.<yourdomain>`. Use `dist/` built with `VITE_API_URL=https://api.<yourdomain>`, plus the security headers (2.4). Redirect the bare domain to `www`.
- **API:** one small Linux VPS in India (2 vCPU, 4 GB RAM, Debian 13 or Ubuntu 24.04 LTS) on `https://api.<yourdomain>`. Run the existing Docker Compose stack, production-hardened:
  - PHP 8.3/8.4 FPM image with Poppler and an FPM pool override setting `pm.max_children=10`;
  - pinned `nginx:1.x` with the headers from 2.3;
  - MySQL **8.4 LTS** in Docker with a volume, or a managed MySQL;
  - Caddy or certbot for HTTPS, with `set_real_ip_from` for that proxy (4.3);
  - no CDN in front of the API at launch;
  - firewall open on ports 22, 80 and 443 only, and the database port **not** published (the dev compose publishes 3307 at `docker-compose.yml:9-10`, which must be removed).
- **Cron on the host:** `*/15 * * * * cd /srv/vtee && flock -n /tmp/vtee-reconcile.lock docker compose exec -T php php scripts/reconcile_orders.php >> /var/log/vtee-reconcile.log 2>&1 || <alert>`.
- **Backups:** nightly `mysqldump --single-transaction` plus a `tar` of `backend/storage` and `backend/public/uploads`, copied off the server; keep 14 days. Test a restore once.
- **Effort:** about 1.5-2 days of engineering. Hosting cost is roughly that of a basic VPS; the owner should compare providers.

### 5.6 Verification checklist on the real host

Run each item and record the result.

1. `docker compose exec -T php php scripts/check_config.php` (or `php backend/scripts/check_config.php` as the PHP-FPM user). It must exit 0 with no WARN lines for launch. Note: the command line can use a different `php.ini` from FPM. Also confirm in the FPM pool or host panel that `disable_functions` does not contain `proc_open`, `proc_get_status`, `proc_terminate`, `proc_close` or `putenv`.
2. `pdftoppm -v`, `pdfinfo -v` and `prlimit --version` inside the PHP container or host print versions. Then open a book in the reader and page to a page never viewed before: it must render.
3. **Cron:** run the cron command by hand, then `echo $?` (expect `0`). Check the log line `paid=… cancelled=… errors=… purged=…`. Simulate a failure (stop the database briefly, or use a wrong `DB_PASS` in a copy) and confirm the alert fires.
4. **Headers:**
   - `curl -sI https://api.<d>/api/health` must show HSTS, nosniff, CSP `default-src 'none'`, `Cache-Control: no-store` and no nginx version.
   - `curl -sI https://www.<d>/` must show the frontend headers and the CSP (Report-Only first).
   - `curl -sI https://api.<d>/uploads/books/1/cover.jpg` must show `frame-ancestors https://www.<d>` and no `X-Frame-Options DENY`. The reader's index panel must load.
5. **Protections:** `curl -o /dev/null -w '%{http_code}\n'` against `https://api.<d>/uploads/books/1/book.pdf`, `/.env` and `/../.env` must all return 404 or 403.
6. **Deep links:** follow `docs/deployment/frontend-hosting.md` §4. Refresh `/admin/orders`, `/read/2` and `/my-books`. `/nope` must show the app's "Page not found". `curl -I https://www.<d>/assets/missing.js` must return 404.
7. **Authorization header:** `curl -s https://api.<d>/api/me -H "Authorization: Bearer x"` must return `"code":"invalid_token"`. If it returns `"code":"unauthorized"` ("Missing or malformed Authorization header"), the server stripped the header.
8. **CORS preflight:** `curl -si -X OPTIONS https://api.<d>/api/orders -H "Origin: https://www.<d>" -H "Access-Control-Request-Method: POST" -H "Access-Control-Request-Headers: authorization,content-type"` must return 204 with `Access-Control-Allow-Origin: https://www.<d>`.
9. **Webhook reachable:** `curl -s -X POST https://api.<d>/api/webhooks/razorpay -d '{}'` must return 400 `invalid_signature`, which proves routing works (not 404 and not HTML). Then, in Razorpay **test mode**, make a payment and confirm the dashboard shows 2xx deliveries for `payment.captured` (premerge §4).
10. **Real client IP:** the procedure in 4.4.
11. **Database:** `SELECT @@version, @@character_set_database, @@collation_database;` must report a supported version and `utf8mb4`.
12. **Uploads:** upload a book PDF of about 40 MB through Admin. It must succeed, without a 413 from nginx or PHP.

---

## Decisions and actions for the owner (one page)

| # | Decide or do | My recommendation | Who | Effort |
|---|---|---|---|---|
| 1 | **Where does the API run?** | A small Linux VPS with the existing Docker stack, HTTPS, cron and backups (5.5). **Not** shared hosting for the API. | Owner picks a provider; Eng sets it up | 1.5-2 days Eng |
| 2 | **Where does the website run?** | Keep Hostinger (or Netlify/Cloudflare Pages) on `www.<yourdomain>`. | Owner | 1 h |
| 3 | **Same domain for site and API?** (`www.` + `api.` of one domain) | **Yes.** It keeps CORS simple and keeps the "admin on secure cookies" upgrade possible. | Owner | none |
| 4 | **Refund rule for staff** | "Refunds only from the Admin panel, never from the Razorpay dashboard" (still the safest rule, although dashboard refunds are now reconciled). | Owner tells staff | none |
| 5 | **Refund reconciler (F-1)** | *Built.* Full dashboard refunds get recorded automatically; partial ones are flagged for a human. Verify in Razorpay test mode. | Eng | done |
| 6 | **Security headers + CSP** | *Built.* CSP is Report-Only; switch to enforcing before launch if the Razorpay test-mode run shows no violations. | Eng | enforcement: minutes |
| 7 | **Login token policy** | Launch with tokens in the browser plus a strict CSP; admin session 1 h. Customer session: **owner chooses** 7 days (convenience) or 1-3 days (safety). Move the admin login to secure cookies 1-2 months after launch. | Owner (lifetime), Eng | 0.5 day now, 1-1.5 days later |
| 8 | **Real visitor IP** | No CDN in front of the API at launch. Configure `set_real_ip_from` for the HTTPS proxy (or `TRUSTED_PROXIES`); IPv6 /64 grouping is built. Run the 4.4 test after deploy. | Eng | at hosting time |
| 9 | **Database engine** | MySQL 8.4 LTS on the VPS. If a host only offers MariaDB, it should work, but run the migrations and tests on it first. | Eng | 2-3 h if MariaDB |
| 10 | **PHP version** | *Done:* PHP 8.4 image, full suite green. | Eng | done |
| 11 | **Rebuild the frontend** | *Done:* the production build fails when `VITE_API_URL` is missing. Always rebuild before upload; never upload an old `dist/` (the one inspected on 2026-10-31 pointed at `localhost:8080`). | Eng | done |
| 12 | **Go-live check** | Run the 12-step checklist in 5.6 on the real host, together with the Razorpay test-mode checklist (premerge §4). | Eng runs, Owner signs off | 0.5 day |

Refund and cancellation policy is settled by the owner (no general refunds, admin exception refunds only); see the status section. The old options D1-D10 are closed.

---

## Flag cleanup (plan only, nothing built)

`orders.admin_flag` (`refund_external_unmatched`, `duplicate_payment`, `currency_mismatch`, `amount_mismatch`; first flag wins) has no clear action in the app, and the owner decided NOT to build one now. The plan, with the backup-first SQL approach, is in [SP5-flag-cleanup-plan.md](SP5-flag-cleanup-plan.md). `commissions.clawback_due` and the `reversed` status are legacy and unused.

## Open owner blockers: homepage claims

"32+ Years Experience" and "30 years in universities + 2 years company experience" were removed. Also removed on the owner's instruction: world-class, retired professors, 24x7 availability, free one-to-one counselling, and the founder titles (Founder Chairman, PhD in Education). Re-add any of them only after the business verifies it.
