# SP-5 Orders & Razorpay Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the mock purchase flow with real, server-authoritative orders and Razorpay payments (digital + hard copy), with a price/shipping/GST breakdown, referral attribution with flat-₹ commission rows, hard-copy address capture, and optional-SMTP email notifications.

**Architecture:** Plain PHP controllers + small service classes behind the existing `Router`; a `Gateway` interface (live curl client / fake for tests) isolates Razorpay; one idempotent `OrderFulfillment::markPaid()` is the only code path that grants access or creates commissions, called by both the verify endpoint and the webhook. React checkout calls the new API and never decides price or "paid".

**Tech Stack:** PHP 8.2-fpm, MySQL 8, PDO, nginx, Docker Compose, vendored PHPMailer (no Composer), React 18 + Vite + Vitest + Testing Library, Razorpay Checkout.js.

**Spec:** `docs/superpowers/specs/2026-10-26-phase2-commerce-reader-referrals-design.md` (revised 2026-10-27; §4.1, §4.4, §4.6, §4.7, §5, §8, §12).

## Global Constraints

- **Server decides the payable amount.** Price from `books.online_price|hardcopy_price` + `settings`; client-sent amounts are ignored. Money computed in integer paise, stored as DECIMAL(10,2); Razorpay amounts are integer paise, currency `INR`.
- **Never grant access from the frontend success callback.** Only `OrderFulfillment::markPaid()` inserts `book_access`/`commissions`, and only after server-side verification (signature + Razorpay API fetch) or a signed webhook.
- **Commission is flat ₹, never a percentage:** `settings.commission_rate_digital` / `commission_rate_hardcopy` (seed `25`), snapshotted into `commissions.amount` at paid time.
- **Shipping and GST are unconfirmed (U1/U2):** configured via `settings`, defaulting to `0` / `0` / `0` / `inclusive`; checkout shows price, shipping, GST, total lines always.
- **Webhook:** signature over the raw body with `RAZORPAY_WEBHOOK_SECRET`; dedupe by `X-Razorpay-Event-Id` in `payment_events`; non-2xx only when processing really failed.
- **Email never blocks payments.** `SMTP_HOST` empty → outbox rows `skipped`; any mail error is swallowed and logged. Credentials only from env.
- **SP-4 intact.** Additive migration only; no change to existing endpoint payloads; Razorpay/SMTP env is validated lazily (at use), never at boot, so a `.env` without them still serves SP-4. Gates every task: `bash backend/tests/book_test.sh` 25/25, `bash backend/tests/http_test.sh` 23/23, `docker compose exec -T php php tests/<unit>.php` all pass, `npm test` (63 tests before this plan).
- **Env keys:** `APP_ENV` (`development`|`production`), `RAZORPAY_GATEWAY` (`live`|`fake`), `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_SECURE` (`tls`|`ssl`|`none`), `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`. `fake` gateway is forbidden when `APP_ENV=production`.
- **Error shape:** throw `App\Http\ApiException(message, status, code, fields[])`; codes used here: `validation_error`, `not_found`, `forbidden`, `already_owned`, `payment_verification_failed`, `gateway_error`, `rate_limited`, `invalid_signature`.
- **Code style:** match existing controllers (`json_decode(file_get_contents('php://input'), true) ?? []`, `Db::connection()`, `AuthMiddleware::requireRole([...])`, `echo json_encode(...)`, `http_response_code(...)`); `// ponytail:` comments for deliberate shortcuts.
- **Test style:** PHP unit scripts in `backend/tests/*_test.php` (assert helpers, non-zero exit on failure, run inside the php container) and shell HTTP scripts using the `check()` helper from `book_test.sh`; Vitest + Testing Library on the frontend, mocking `src/api/*` with `vi.spyOn`.

## Revision 2026-10-28 (owner brief: access codes, My Books, tracking) — **overrides the tasks below where they conflict**

1. **Digital access is granted by code redemption, not by payment alone.** `OrderFulfillment::markPaid` (Task 6) for a digital order creates an *active access code* (Task 9) and does **not** insert `book_access`. `book_access` is inserted only by `AccessCodeService::redeem`. A paid-but-unredeemed digital order counts as "already purchased": `POST /api/orders` returns 409 `already_owned` when the user has a `book_access` row **or** a paid digital order for that book.
2. **Hard copy**: `markPaid` sets `fulfilment_status='confirmed'` (not `pending`) and generates `orders.tracking_code` (`VTT-` + 8 chars of Crockford base32 from `random_bytes`, unique). A tracking code can only read status via `GET /api/track/{code}` and never grants digital access.
3. **Fulfilment enum (Task 1)**: `not_applicable, confirmed, processing, shipped, out_for_delivery, delivered, cancelled`. Customer labels: Order Confirmed, Processing, Shipped, Out for Delivery, Delivered, Cancelled.
4. **Migration 003 additions (Task 1)**: `orders.tracking_code VARCHAR(20) NULL UNIQUE`; `book_access.revoked_at DATETIME NULL`; `access_codes` (`id`, `order_id` FK, `user_id` FK (buyer), `book_id` FK, `code_hash CHAR(64) NOT NULL UNIQUE`, `code_last4 CHAR(4)`, `code_enc VARBINARY(255) NULL`, `status ENUM('active','redeemed','revoked') DEFAULT 'active'`, `redeemed_by_user_id INT NULL`, `redeemed_at`, `revoked_at`, `revoked_reason VARCHAR(255)`, `replaced_by_id INT NULL`, `created_at`); `audit_logs` (`id`, `admin_id INT NULL`, `user_id INT NULL`, `action VARCHAR(60)`, `entity_type VARCHAR(40)`, `entity_id INT`, `detail JSON NULL`, `created_at`). **Remove `email_outbox` from 003** (email is deferred, see 7).
5. **Env**: add `CODE_SECRET` (≥32 bytes, placeholder-rejected, lazily validated like Razorpay). Hash pepper = `hash_hmac('sha256','hash',CODE_SECRET)`, encryption key = `hash_hmac('sha256','enc',CODE_SECRET)`.
6. **Code policy (my call, change if wrong):** digital code = 16 chars Crockford base32 (80 bits) shown as `XXXX-XXXX-XXXX-XXXX`; stored as `HMAC-SHA256(pepper, normalised code)` plus last4. The plaintext is kept only as AES-256-GCM ciphertext in `code_enc` **until redeemed** (so the owner can re-display it while logged in), then `code_enc` is set NULL. Admins never see plaintext: only status, last4, timestamps; they can verify, revoke and reissue. Reissue revokes the old code, issues a new one, links `replaced_by_id`, writes `audit_logs`.
7. **Deferred out of SP-5:** email outbox/SMTP (old Task 9), moved to a later sub-project after reader + admin. Mail-related steps and `skipped` outbox checks in other tasks are dropped; orders and codes are shown on pages instead.
8. **Upcoming plans** (written after SP-5 merges): SP-6 secure reader (Poppler in PHP image, page-image endpoints, reader UI), SP-8 admin (orders/customers/dashboard/code management/fulfilment screens), then refunds, referrals admin, email.
**Execution order:** Tasks 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11 → 12 → 13 → 14 → 14b → 15 (Task 14b is listed next to Task 9 but needs Tasks 12–14).


## Review Focus

1. **Out-of-order / replayed payment events:** `payment.failed` arriving after a captured payment, `payment.captured` arriving before `/verify`, the same event id twice, a capture for an order already `cancelled` by the sweep. Expected: a paid order never downgrades, exactly one access row and one commission, late capture still fulfils. (Tasks 6, 8, 10)
2. **Client tampering / IDOR:** unpublished or archived book, bad `purchaseType`, `amount` in the body, another user's order id on verify/show, signature for a different order. Expected: 404/403/400, nothing granted. (Tasks 5, 7)
3. **Money rounding:** odd prices (₹349.50), 5% / 18% GST inclusive and exclusive, shipping with GST. Expected: `subtotal + shipping + gst(exclusive) == total` to the paisa, quote equals the stored order. (Task 2)
4. **SMTP unset / down / header injection:** `SMTP_HOST` empty, unreachable server, a name or subject containing `\r\nBcc:`. Expected: order still paid, outbox `skipped`/`failed`, no injected header. (Task 9)
5. **Messy hard-copy address input:** 5-digit pincode, `+91 98765-43210` phone, 10 000-char fields, Tamil text, missing district. Expected: normalised or rejected with per-field errors; Tamil stored intact (utf8mb4). (Task 4)

---

## File Structure

Backend (new unless noted):
- `backend/migrations/003_orders_payments.sql` — schema + settings seeds.
- `backend/src/Support/Money.php`, `Settings.php`, `RateLimiter.php` — shared helpers.
- `backend/src/Orders/PricingService.php`, `AddressValidator.php`, `OrderService.php`, `OrderFormatter.php`, `OrderFulfillment.php`, `OrderReconciler.php`.
- `backend/src/Razorpay/Config.php`, `Gateway.php`, `RazorpayGateway.php`, `FakeGateway.php`, `GatewayFactory.php`, `Signature.php`.
- `backend/src/Referral/ReferralService.php`.
- `backend/src/Mail/MailConfig.php`, `Mailer.php`, `SmtpMailer.php`, `EmailTemplates.php`, `EmailService.php`; vendored `backend/lib/phpmailer/`.
- `backend/src/Controllers/CheckoutController.php`, `ReferralController.php`, `OrderController.php`, `WebhookController.php`.
- `backend/scripts/send_emails.php`, `reconcile_orders.php`.
- Modify: `backend/src/routes.php`, `backend/public/index.php` (autoload prefix for PHPMailer), `backend/.env.example`, `docker-compose.yml` (mailpit), `backend/README.md`.
- Tests: `backend/tests/{money_pricing,signature_gateway,address_validator,order_fulfillment,email,reconciler}_test.php`, `backend/tests/order_test.sh`.

Frontend:
- `src/api/orders.js`, `src/utils/referral.js`, `src/utils/razorpay.js`.
- `src/components/checkout/PriceBreakdown.jsx`, `AddressForm.jsx`, `ReferralCodeInput.jsx`.
- `src/pages/CheckoutPage.jsx` (rewrite), `src/pages/OrderResultPage.jsx`; modify `src/pages/AccountPage.jsx`, `src/App.jsx`; rewrite `CheckoutPage.test.jsx`, update `AccountPage.test.jsx`.

---

### Task 1: Migration 003 and dev environment

**Files:**
- Create: `backend/migrations/003_orders_payments.sql`
- Modify: `backend/.env.example`, `docker-compose.yml`, `backend/README.md` (Migrations + env sections)

**Interfaces:**
- Produces (schema): `orders` + `paid_at DATETIME NULL`, `subtotal_amount`, `shipping_amount`, `gst_amount` `DECIMAL(10,2) NOT NULL DEFAULT 0`, `gst_percent DECIMAL(5,2) NOT NULL DEFAULT 0`, `gst_mode ENUM('inclusive','exclusive') NOT NULL DEFAULT 'inclusive'`, `fulfilment_status ENUM('not_applicable','pending','packed','shipped','delivered','cancelled') NOT NULL DEFAULT 'not_applicable'`, `courier VARCHAR(100) NULL`, `tracking_number VARCHAR(100) NULL`, `shipped_at DATETIME NULL`, `referral_code_snapshot VARCHAR(30) NULL`, `admin_flag VARCHAR(100) NULL`, indexes `(user_id, created_at)` and `(status, created_at)`. `payments` + `failure_reason VARCHAR(255) NULL`, `UNIQUE(razorpay_order_id)`, `UNIQUE(razorpay_payment_id)`. New `payment_events(id, event_id VARCHAR(100) UNIQUE, event_type VARCHAR(60), payload JSON, processed_at DATETIME NULL, created_at)`, `email_outbox(id, to_email VARCHAR(190), subject VARCHAR(255), body_text MEDIUMTEXT, body_html MEDIUMTEXT, status ENUM('pending','sent','failed','skipped') DEFAULT 'pending', attempts INT DEFAULT 0, last_error VARCHAR(500) NULL, created_at, sent_at NULL)`, `rate_limits(bucket VARCHAR(190), window_start DATETIME, hits INT NOT NULL DEFAULT 1, PRIMARY KEY(bucket, window_start))`. `settings` seeds (INSERT IGNORE): `shipping_charge_hardcopy=0`, `shipping_charge_digital=0`, `gst_percent_digital=0`, `gst_percent_hardcopy=0`, `gst_mode=inclusive`.

- [ ] **Step 1: Write the migration** exactly per the Produces block (all `ALTER`s additive; tables `CREATE TABLE`; seeds `INSERT IGNORE`). Do not touch `referral_mappings`.
- [ ] **Step 2: Apply to the running stack** — `docker compose up -d --build`, then `docker compose exec -T mysql mysql -u vtee -pchangeme vtee < backend/migrations/003_orders_payments.sql`. Expected: no error.
- [ ] **Step 3: Verify** — `docker compose exec -T mysql mysql -u vtee -pchangeme vtee -e "SHOW COLUMNS FROM orders LIKE 'admin_flag'; SHOW TABLES LIKE 'payment_events'; SELECT \`key\` FROM settings WHERE \`key\` LIKE 'gst%';"`. Expected: the column, the table, `gst_mode`, `gst_percent_digital`, `gst_percent_hardcopy` listed. Re-applying the file must fail only on already-exists, not corrupt data (document that migrations are one-shot, same as 001/002).
- [ ] **Step 4: Dev config** — add to `.env.example` every env key from Global Constraints with fake/dev defaults (`RAZORPAY_GATEWAY=fake`, `RAZORPAY_KEY_ID=rzp_test_changeme`, `RAZORPAY_KEY_SECRET=changeme`, `RAZORPAY_WEBHOOK_SECRET=changeme`, `SMTP_HOST=` empty, `APP_ENV=development`) with comments saying fake is dev-only and placeholders are rejected in `live`. Add a `mailpit` service (`axllent/mailpit`, ports `8025:8025`, `1025:1025`) to `docker-compose.yml`. Copy the new keys into the local `backend/.env` (not committed) with `SMTP_HOST=mailpit SMTP_PORT=1025 SMTP_SECURE=none`.
- [ ] **Step 5: Regression** — `bash backend/tests/book_test.sh` and `bash backend/tests/http_test.sh`. Expected: all PASS.
- [ ] **Step 6: Commit** — `git add backend/migrations/003_orders_payments.sql backend/.env.example docker-compose.yml backend/README.md && git commit -m "feat(sp5): orders/payments migration 003 and dev env"`

---

### Task 2: Money, Settings, PricingService

**Files:**
- Create: `backend/src/Support/Money.php`, `backend/src/Support/Settings.php`, `backend/src/Orders/PricingService.php`
- Test: `backend/tests/money_pricing_test.php`

**Interfaces:**
- Produces:
  - `Money::toPaise(string|int|float $amount): int` (rounds half-up from a 2-decimal value), `Money::fromPaise(int $paise): string` (`"199.00"`).
  - `Settings::get(string $key, string $default = ''): string` (reads `settings`; falls back to `$default`).
  - `PricingService::breakdown(int $pricePaise, int $shippingPaise, string $gstPercent, string $gstMode): array{subtotal:int,shipping:int,gst:int,total:int}` — pure, integer-only, half-up. Percent converted to basis points (`"18"`→1800, `"2.5"`→250). taxable = price + shipping. `exclusive`: gst = round(taxable·bp/10000), total = taxable + gst. `inclusive`: gst = round(taxable·bp/(10000+bp)), total = taxable. `subtotal` = price.
  - `PricingService::forBook(array $bookRow, string $purchaseType): array{subtotal:int,shipping:int,gst:int,total:int,gstPercent:string,gstMode:string}` — picks `online_price`/`hardcopy_price` and the per-type `Settings` keys (`shipping_charge_<type>`, `gst_percent_<type>`, `gst_mode`); throws `ApiException('Invalid purchase type',400,'validation_error',['purchaseType'=>...])` for anything but `digital|hardcopy`.

- [ ] **Step 1: Write failing tests** in `money_pricing_test.php` (pure functions, no DB): `breakdown(19900,0,'0','inclusive')` → gst 0 total 19900; `breakdown(19900,0,'18','exclusive')` → gst 3582 total 23482; `breakdown(19900,0,'18','inclusive')` → gst 3036 total 19900; `breakdown(34950,0,'5','exclusive')` → gst 1748 total 36698; `breakdown(34950,5000,'18','exclusive')` → shipping 5000 gst 7191 total 47141; `Money::toPaise('349.50')===34950`, `Money::toPaise(199)===19900`, `Money::fromPaise(34950)==='349.50'`; property check over prices 1..2000 paise × rates {0,5,12,18}: exclusive `subtotal+shipping+gst===total`.
- [ ] **Step 2: Run** `docker compose exec -T php php tests/money_pricing_test.php` — Expected: FAIL (class not found).
- [ ] **Step 3: Implement** the three classes per Interfaces (integer math only — no floats on money; `Settings::get` uses a prepared SELECT).
- [ ] **Step 4: Run** same command — Expected: all PASS. Then run regression gates.
- [ ] **Step 5: Commit** — `feat(sp5): money helpers and pricing breakdown`

---

### Task 3: Razorpay config, gateway interface, signatures

**Files:**
- Create: `backend/src/Razorpay/Config.php`, `Gateway.php`, `RazorpayGateway.php`, `FakeGateway.php`, `GatewayFactory.php`, `Signature.php`
- Test: `backend/tests/signature_gateway_test.php`

**Interfaces:**
- Produces:
  - `Config::load(): array{mode:string,keyId:string,keySecret:string,webhookSecret:string}` — reads env lazily; throws `RuntimeException` when `RAZORPAY_GATEWAY=live` and any of the three values is empty or contains `changeme`; throws when `RAZORPAY_GATEWAY=fake` and `APP_ENV=production`; `RAZORPAY_GATEWAY` unset ⇒ treated as `live`.
  - `interface Gateway { createOrder(int $amountPaise, string $receipt, array $notes = []): array /* ['id'=>string] */; fetchPayment(string $paymentId): array /* ['id','order_id','amount'(int paise),'currency','status','error_description'?] */; fetchOrderPayments(string $razorpayOrderId): array /* list of payment arrays */ }`
  - `RazorpayGateway implements Gateway` — `curl` to `https://api.razorpay.com/v1/…` with basic auth, 10 s timeout, JSON; non-2xx or curl error ⇒ `ApiException('Payment gateway error',502,'gateway_error')` (response details to `error_log`, never to client).
  - `FakeGateway implements Gateway` — `createOrder` returns `['id'=>'order_fake_'.bin2hex(random_bytes(6))]`; `fetchPayment("pay_fake_<rzpOrderId>_<state>")` (state ∈ `captured|authorized|failed`) returns that state, `order_id` parsed from the id, and `amount` = `payments.amount` (looked up by `razorpay_order_id`) × 100; unknown format ⇒ `ApiException(…,502,'gateway_error')`; `fetchOrderPayments` returns `[]`.
  - `GatewayFactory::make(): Gateway`.
  - `Signature::verifyCheckout(string $rzpOrderId, string $paymentId, string $signature, string $secret): bool` (`hash_hmac('sha256', "$rzpOrderId|$paymentId", $secret)` compared with `hash_equals`); `Signature::verifyWebhook(string $rawBody, string $signatureHeader, string $secret): bool` (HMAC of the raw body, `hash_equals`).

- [ ] **Step 1: Write failing tests**: known-vector `verifyCheckout('order_X','pay_Y',hash_hmac(...),'s')` true and false for a flipped char / different order id / empty signature; `verifyWebhook` true/false and false for empty header; `Config::load()` throws for `live`+`changeme`, for `fake`+`production`, returns values for `fake`+`development` (set env with `putenv`).
- [ ] **Step 2: Run** `docker compose exec -T php php tests/signature_gateway_test.php` — Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces.
- [ ] **Step 4: Run** — Expected: PASS; also `docker compose exec -T php php -m | grep -Ei "curl|openssl|mbstring"` lists all three (needed later).
- [ ] **Step 5: Regression gates, then commit** — `feat(sp5): razorpay gateway abstraction and signature verification`

---

### Task 4: Referral resolution, address validation, rate limiter, quote and validate endpoints

**Files:**
- Create: `backend/src/Support/RateLimiter.php`, `backend/src/Referral/ReferralService.php`, `backend/src/Orders/AddressValidator.php`, `backend/src/Controllers/CheckoutController.php`, `backend/src/Controllers/ReferralController.php`
- Modify: `backend/src/routes.php`
- Test: `backend/tests/address_validator_test.php`, `backend/tests/order_test.sh` (new; sections 1–2)

**Interfaces:**
- Produces:
  - `RateLimiter::hit(string $bucket, int $max, int $windowSeconds): void` — upserts into `rate_limits` for the current window; throws `ApiException('Too many requests',429,'rate_limited')` when `hits > $max`.
  - `ReferralService::resolve(?string $code, string $buyerEmail): ?array` — trims/uppercases; returns `['id'=>int,'name'=>string,'code'=>string]` only for an existing `active=1` partner whose `gmail` differs (case-insensitive) from `$buyerEmail`; otherwise `null` (never throws).
  - `AddressValidator::validate(array $input): array{fullName,phone,addressLine,city,district,state,pincode}` — required: all fields; phone: strip spaces/dashes, accept optional `+91`/`91`/`0` prefix, must resolve to 10 digits starting 6–9, stored as the 10 digits; pincode `^[1-9][0-9]{5}$`; `mb_strlen` caps: name 150, addressLine 500, others 100; throws `ApiException('Validation failed',400,'validation_error',$fields)` with a message per bad field.
  - `GET /api/checkout/quote?bookId=&type=` → 200 `{bookId,purchaseType,currency:"INR",subtotal,shipping,gst,gstPercent,gstMode,total}` (amounts as numbers in ₹); 404 `not_found` for unpublished/archived/missing book; 400 for bad `type`.
  - `GET /api/referral/validate/{code}` → 200 `{valid:bool, partnerName?:string}` where `partnerName` is first letter + `***`; rate limited 30/min per `REMOTE_ADDR`.

- [ ] **Step 1: Write failing tests**: `address_validator_test.php` — accepts `+91 98765-43210` → `9876543210`; Tamil name round-trips unchanged; rejects pincode `60001`, `060001`, phone `12345`, missing district, 10 000-char addressLine (each with the right field key). `order_test.sh` §1: seed a published book via admin API (copy the setup block from `book_test.sh`), quote digital/hardcopy returns the `settings` values; with `gst_percent_digital=18`, `gst_mode=exclusive` set by SQL the quote shows gst and total, then reset settings; unpublished book → 404; bad type → 400. §2: validate endpoint — insert an active partner `VTP-TEST01` by SQL, `valid:true` with masked name; unknown/inactive code `valid:false`; 31st call within a minute → 429.
- [ ] **Step 2: Run** unit + `bash backend/tests/order_test.sh` — Expected: FAIL.
- [ ] **Step 3: Implement** the classes and controllers; register the two GET routes in `routes.php`. `$_GET` is the query source.
- [ ] **Step 4: Run** — Expected: PASS. Regression gates.
- [ ] **Step 5: Commit** — `feat(sp5): checkout quote, referral validation, address validation`

---

### Task 5: Create order (`POST /api/orders`)

**Files:**
- Create: `backend/src/Orders/OrderService.php`, `backend/src/Orders/OrderFormatter.php`, `backend/src/Controllers/OrderController.php` (method `create`)
- Modify: `backend/src/routes.php`
- Test: `backend/tests/order_test.sh` (§3)

**Interfaces:**
- Consumes: `PricingService::forBook`, `AddressValidator::validate`, `ReferralService::resolve`, `RateLimiter::hit`, `GatewayFactory::make`, `Money::fromPaise`.
- Produces:
  - `OrderService::__construct(Gateway $gateway)`; `OrderService::create(int $userId, array $input): array` returning `['order'=>array,'razorpay'=>['keyId'=>string,'orderId'=>string,'amount'=>int,'currency'=>'INR']]`.
  - `OrderFormatter::format(array $orderRow, ?array $addressRow = null): array` — `{id, orderNumber, bookId, bookTitle, purchaseType, status, fulfilmentStatus, subtotal, shipping, gst, gstPercent, gstMode, total, currency:"INR", paidAt, createdAt, address?}` (numbers for money, `address` camelCase only when row given).
  - `POST /api/orders` (customer JWT) body `{bookId, purchaseType, referralCode?, address?}` → 201 with the object above.
- Behaviour of `create`: rate limit 20/hour per user; book must be `published=1 AND archived=0` (404); `purchaseType` ∈ digital|hardcopy; digital refused with 409 `already_owned` when an un-revoked `book_access` row exists for (user, book) — use `book_access` as it is today (no `revoked_at` yet); hardcopy requires `address` (validated, stored in `hardcopy_addresses` in the same transaction); referral resolved with the buyer's `users.email`, invalid ⇒ silently ignored; order number `VT-YYYYMMDD-` + 6 chars from `random_bytes` (base32 alphabet, retry on duplicate); Razorpay order created **before** inserting rows (amount = total paise, receipt = order number, notes `{order_number}`); inserts `orders` (status `pending`, breakdown columns, `amount` = total, `referral_partner_id`, `referral_code_snapshot`, `fulfilment_status` = `not_applicable`) + `payments` (`status='created'`, `razorpay_order_id`, `amount`) in one transaction. A `body.amount` is ignored.

- [ ] **Step 1: Write failing tests** (`order_test.sh` §3, fake gateway, customer registered like `book_test.sh`): digital create → 201, `order.total` equals the quote, `razorpay.amount` equals total×100, `status:"pending"`; sending `"amount":1` still charges the real price; unpublished book 404; no token 401; hardcopy without address 400 with `fields.address…`; hardcopy with valid address 201 and `hardcopy_addresses` row exists (query via `docker compose exec mysql`); referral code of an active partner stored in `referral_partner_id`; self-referral (partner gmail = buyer email) leaves `referral_partner_id` NULL; unknown code leaves it NULL and still 201; after setting a `book_access` row by SQL, digital create → 409 `already_owned`; 21st create in an hour → 429.
- [ ] **Step 2: Run** `bash backend/tests/order_test.sh` — Expected: §3 FAIL.
- [ ] **Step 3: Implement** per Interfaces; register `POST /api/orders`.
- [ ] **Step 4: Run** — Expected: PASS; regression gates.
- [ ] **Step 5: Commit** — `feat(sp5): create order and razorpay order`

---

### Task 6: OrderFulfillment (the single paid/failed path)

**Files:**
- Create: `backend/src/Orders/OrderFulfillment.php`
- Test: `backend/tests/order_fulfillment_test.php` (DB-backed, run in the php container)

**Interfaces:**
- Consumes: `Money::toPaise`, `Settings::get`, tables from Task 1.
- Produces: `OrderFulfillment::markPaid(string $razorpayOrderId, string $razorpayPaymentId, int $capturedPaise, array $rawPayment = []): string` returning `'paid' | 'already_paid' | 'amount_mismatch' | 'unknown_order'`; `OrderFulfillment::markFailed(string $razorpayOrderId, string $razorpayPaymentId, string $reason): string` returning `'failed' | 'ignored' | 'unknown_order'`.
- `markPaid` rules, all in one transaction with the order row locked (`SELECT … FOR UPDATE` joined via `payments.razorpay_order_id`): unknown order ⇒ `unknown_order`; status already `paid` ⇒ `already_paid` (no writes); `$capturedPaise` ≠ `Money::toPaise(payments.amount)` ⇒ set `orders.admin_flag='amount_mismatch'`, change nothing else, return `amount_mismatch`; otherwise (status may be `pending`, `failed` **or** `cancelled` — late capture must still fulfil): `payments` → `captured` with `razorpay_payment_id` and `raw_response`; order → `paid`, `paid_at=NOW()`; digital ⇒ `INSERT IGNORE INTO book_access (user_id, book_id, order_id)`, and if no row was inserted because the pair already existed ⇒ `admin_flag='duplicate_digital_purchase'`; hardcopy ⇒ `fulfilment_status='pending'`; if `referral_partner_id` set ⇒ `INSERT IGNORE INTO commissions (order_id, referral_partner_id, purchase_type, amount, status)` with `amount = Settings::get("commission_rate_{$type}", '25')` as a flat ₹ value, status `pending`.
- `markFailed`: if order `paid` ⇒ `ignored`; else order → `failed`, payment → `failed` with `failure_reason` (truncated to 255); never touches a `paid` order.

- [ ] **Step 1: Write failing tests** (insert fixtures by SQL: user, book, partner, orders+payments rows; clean up after): digital paid → order `paid`, one `book_access`, `paid_at` set; second `markPaid` → `already_paid`, still one access row, one commission; referral digital → commission amount `25.00`, status `pending`; change `commission_rate_hardcopy` to `40` then a hardcopy paid order gets `40.00` while the earlier row stays `25.00`; hardcopy paid → `fulfilment_status='pending'`, no `book_access`; no partner → zero commission rows; wrong captured amount → `amount_mismatch`, order still `pending`, `admin_flag` set, no access; `markFailed` then `markPaid` (late capture) → `paid`; `markPaid` then `markFailed` → `ignored`, still `paid`; order `cancelled` then `markPaid` → `paid`; existing access from another order → `paid` + `duplicate_digital_purchase`; **concurrency**: spawn two `php -r` processes (via `proc_open`) calling `markPaid` for the same order → exactly one access row and one commission row.
- [ ] **Step 2: Run** `docker compose exec -T php php tests/order_fulfillment_test.php` — Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces (PDO transaction; rethrow on error so callers return 500).
- [ ] **Step 4: Run** — Expected: PASS; regression gates.
- [ ] **Step 5: Commit** — `feat(sp5): idempotent order fulfilment and commission snapshot`

---

### Task 7: Verify endpoint and customer order APIs

**Files:**
- Modify: `backend/src/Controllers/OrderController.php` (add `verify`, `mine`, `showMine`), `backend/src/routes.php`
- Test: `backend/tests/order_test.sh` (§4)

**Interfaces:**
- Consumes: `Signature::verifyCheckout`, `Gateway::fetchPayment`, `OrderFulfillment::markPaid/markFailed`, `OrderFormatter::format`, `Config::load`.
- Produces:
  - `POST /api/orders/{id}/verify` (customer, owner only) body `{razorpay_order_id, razorpay_payment_id, razorpay_signature}` → 200 `{order}` (formatted, fresh). Steps: order must belong to caller (else 404); `razorpay_order_id` must equal the stored one; signature checked with `Config::load()['keySecret']` ⇒ 400 `payment_verification_failed`; then `Gateway::fetchPayment` — require `order_id` equal and `status`: `captured` ⇒ `markPaid` (amount = `amount` from the gateway); `authorized` ⇒ 202 `{order}` still pending; `failed` ⇒ `markFailed`, 200 with `status:"failed"`. Already-paid order ⇒ 200 idempotently.
  - `GET /api/me/orders` → list newest first, `[formatted]`, customer-scoped. `GET /api/me/orders/{id}` → one order incl. `address`, 404 if not owner's.

- [ ] **Step 1: Write failing tests** (`order_test.sh` §4, helper computes HMAC with the dev `RAZORPAY_KEY_SECRET` via `openssl dgst -sha256 -hmac`): create digital order, verify with `pay_fake_<rzpOrder>_captured` and a valid signature → 200, `status:"paid"`, `book_access` row exists; repeat verify → 200 no duplicate; bad signature → 400 `payment_verification_failed` and order still `pending` and **no access row**; valid signature for a different `razorpay_order_id` → 400; user B verifying user A's order → 404; `pay_fake_<order>_failed` with valid signature → order `failed`; `…_authorized` → 202 pending; `/api/me/orders` returns only own orders; `/api/me/orders/{A's id}` as B → 404; hardcopy verify → `fulfilmentStatus:"pending"` and address present in `/api/me/orders/{id}`.
- [ ] **Step 2: Run** — Expected: §4 FAIL.
- [ ] **Step 3: Implement**; register the three routes.
- [ ] **Step 4: Run** — Expected: PASS; regression gates.
- [ ] **Step 5: Commit** — `feat(sp5): payment verification and customer order endpoints`

---

### Task 8: Webhook endpoint with idempotency

**Files:**
- Create: `backend/src/Controllers/WebhookController.php`
- Modify: `backend/src/routes.php`
- Test: `backend/tests/order_test.sh` (§5)

**Interfaces:**
- Produces: `POST /api/webhooks/razorpay` (no auth; HMAC). Reads raw body; `Signature::verifyWebhook($raw, $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '', webhookSecret)` else 400 `invalid_signature`; requires `HTTP_X_RAZORPAY_EVENT_ID` else 400. Dedupe: `INSERT INTO payment_events(event_id,event_type,payload)`; on duplicate key, if `processed_at IS NOT NULL` ⇒ 200 `{status:"duplicate"}`, if NULL ⇒ reprocess. Handles `payment.captured` (→ `markPaid` with `payload.payment.entity.{order_id,id,amount}`), `payment.failed` (→ `markFailed` with `error_description`); any other event ⇒ recorded and `{status:"ignored"}`. Sets `processed_at=NOW()` after success; returns 200 `{status:"ok"|"duplicate"|"ignored"}`; unknown order ⇒ 200 `ignored`; an exception ⇒ 500 (event stays unprocessed so Razorpay retries).

- [ ] **Step 1: Write failing tests** (`order_test.sh` §5, body signed with `RAZORPAY_WEBHOOK_SECRET`): captured webhook **without** a prior verify call → order `paid`, access granted, commission row for a referred order; same event id replayed → `duplicate`, still one access row/commission; a *different* event id for the same payment → `already_paid`-safe, still one row; bad signature → 400 and nothing changes; missing event id → 400; `payment.failed` for a pending order → `failed`; `payment.failed` after capture → order stays `paid`; webhook with amount ≠ order → order stays `pending` with `admin_flag='amount_mismatch'`; `refund.processed` → 200 `ignored` and recorded; event row left unprocessed by a simulated failure (set `processed_at` NULL by SQL) is reprocessed on retry.
- [ ] **Step 2: Run** — Expected: §5 FAIL.
- [ ] **Step 3: Implement**; register route (note: `Cors::handle` is harmless here).
- [ ] **Step 4: Run** — Expected: PASS; regression gates.
- [ ] **Step 5: Commit** — `feat(sp5): razorpay webhook with signature check and idempotency`

---

### Task 9: Access codes (issue, reveal, redeem, reissue, revoke) and tracking lookup

**Files:**
- Create: `backend/src/Codes/CodeGenerator.php`, `backend/src/Codes/CodeCipher.php`, `backend/src/Codes/AccessCodeService.php`, `backend/src/Controllers/AccessCodeController.php`, `backend/src/Controllers/TrackController.php`
- Modify: `backend/src/Orders/OrderFulfillment.php`, `backend/src/Orders/OrderFormatter.php`, `backend/src/routes.php`, `backend/.env.example`, `backend/README.md`
- Test: `backend/tests/codes_test.php`, `backend/tests/order_test.sh` (§6)

**Interfaces:**
- Produces:
  - `CodeGenerator::digital(): string` (16 Crockford-base32 chars, no dashes), `CodeGenerator::tracking(): string` (`VTT-` + 8 chars), `CodeGenerator::normalise(string $input): string` (uppercase, strip spaces/dashes, map `O→0`, `I/L→1`), `CodeGenerator::format(string $code): string` (`XXXX-XXXX-XXXX-XXXX`).
  - `CodeCipher::hash(string $normalisedCode): string` (hex HMAC-SHA256 with pepper), `CodeCipher::encrypt(string $plain): string`, `CodeCipher::decrypt(string $blob): ?string` (null on tamper); keys derived from `CODE_SECRET`, validated lazily (≥32 bytes, not containing `changeme`).
  - `AccessCodeService::issueForOrder(int $orderId): void` — called inside `markPaid`'s transaction for digital orders; creates the active row (hash, last4, `code_enc`); idempotent.
  - `AccessCodeService::revealForOwner(int $userId, int $orderId): ?string` — formatted code if the order is the caller's, paid, code `active` and `code_enc` present; else `null`.
  - `AccessCodeService::redeem(int $userId, string $input): array{bookId:int,orderId:int}` — throws `ApiException` with codes `code_invalid` (generic message, also for malformed input), `code_already_redeemed` (409; **idempotent success** when the same user already redeemed it), `code_revoked` (403), `code_order_not_paid` (403). On success (one transaction): code → `redeemed` (`redeemed_by_user_id`, `redeemed_at`, `code_enc` NULL), `INSERT IGNORE book_access(user_id, book_id, order_id)` and clear `revoked_at`.
  - `AccessCodeService::reissue(int $orderId, ?int $adminId, ?int $userId, string $reason): string` — revokes the current code, issues and returns a new formatted code, writes `audit_logs`, sets `replaced_by_id`; if the old code had been redeemed the redeemer's `book_access.revoked_at` is set so the new code is the only way in.
  - `AccessCodeService::revoke(int $codeId, int $adminId, string $reason): void` — status `revoked`, matching `book_access.revoked_at` set when redeemed, audit row.
  - Routes (customer JWT): `GET /api/me/orders/{id}/access-code` → `{code}` or 404; `POST /api/me/redeem` `{code}` → 200 `{bookId, orderId}`; failed redeem attempts limited to 5 / 15 min per user and 20 / 15 min per IP (429 `rate_limited`; successes don't count); `POST /api/me/orders/{id}/reissue-code` (owner, 3/day) → `{code}`; `GET /api/me/library` → `{books:[{bookId,title,coverUrl,orderId,purchasedAt,accessStatus:'active'|'revoked'}], pendingRedemptions:[{orderId,bookId,title,coverUrl,purchasedAt}]}`.
  - Public `GET /api/track/{code}` (10/min per IP) → `{orderNumber, bookTitle, status, label, updatedAt, courier?, trackingNumber?}`; **never returns address, email or any digital code**; unknown → 404 `not_found`.
  - `OrderFormatter::format` gains `trackingCode` (hard-copy, owner views only) and `digitalCode: {status,last4}|null` (never plaintext).

- [ ] **Step 1: Write failing tests** — `codes_test.php` (DB-backed): generator charset/length and uniqueness over 1 000 draws; `normalise('abcd-efgh ijkl-mnop')` equals `ABCDEFGHIJKLMNOP`; cipher round-trips and returns null for a flipped byte; issue → redeem as buyer → `book_access` row and `code_enc` NULL; redeem twice by buyer → success; redeem by a second user → `code_already_redeemed` and no access row for them; revoked code → `code_revoked`; code on an unpaid order → `code_order_not_paid`; garbage and 15-char input → `code_invalid` with the same message as an unknown valid-format code; reissue after redemption revokes old access and the old code fails; the DB (`access_codes`, `audit_logs`, `orders`) never contains the plaintext code. `order_test.sh` §6: pay digital (fake gateway + signature) → reveal code → library lists it under `pendingRedemptions` and has **no** `book_access` yet → redeem → library lists the book; second user redeem → 409; 6th wrong guess in 15 min → 429; hard-copy order → `trackingCode` visible to its owner, `GET /api/track/<code>` returns `confirmed` and no address/email keys, and submitting that tracking code to `/api/me/redeem` yields `code_invalid` (separate purposes); unknown tracking code → 404.
- [ ] **Step 2: Run** `docker compose exec -T php php tests/codes_test.php` and `bash backend/tests/order_test.sh` — Expected: FAIL.
- [ ] **Step 3: Implement** per Interfaces; wire `issueForOrder` and tracking-code generation into `OrderFulfillment::markPaid` per Revision items 1–2 (digital: no `book_access`; hard copy: `fulfilment_status='confirmed'` + tracking code); update the Task 6 tests accordingly (digital paid ⇒ active code row and **zero** `book_access` rows).
- [ ] **Step 4: Run** — Expected: PASS; regression gates.
- [ ] **Step 5: Commit** — `feat(sp5): access codes, redemption, library and tracking lookup`

---

### Task 14b: My Books page and tracking lookup (frontend)

**Files:**
- Create: `src/pages/MyBooksPage.jsx`, `src/pages/MyBooksPage.test.jsx`, `src/pages/TrackOrderPage.jsx`, `src/pages/TrackOrderPage.test.jsx`
- Modify: `src/api/orders.js` (add `fetchLibrary`, `redeemCode`, `fetchAccessCode`, `reissueCode`, `trackOrder`), `src/App.jsx` (routes `/my-books`, `/track`), `src/pages/AccountPage.jsx` (logged-in view links to My Books), `src/pages/OrderResultPage.jsx` (show code + Redeem button), `src/components/home/Header.jsx` (My Books link next to the existing Account link; no design changes)

**Interfaces:**
- Produces: `MyBooksPage` — loads `fetchLibrary` + `fetchMyOrders`; sections **My Books** (cover, title, purchase date, access status, **Read Book** link to `/read/{bookId}` only when `accessStatus==='active'`), **Redeem a code** (input + button, server messages shown verbatim for invalid / already redeemed / revoked / unpaid; success moves the book into the list), **To redeem** (pendingRedemptions with "Show my code" → `fetchAccessCode`, Redeem button), **Hard-copy orders** (order number, tracking code, status label). No `vtee-auth-token` ⇒ link to `/account`. `TrackOrderPage` — public: tracking-code input → status label, courier, tracking number; generic not-found message.
- `OrderResultPage` (Task 14) for a paid digital order calls `fetchAccessCode` and displays the code with a **Redeem to My Books** button; hard copy shows the tracking code and a link to `/track`.

- [ ] **Step 1: Write failing tests** (spy on `api/orders`): My Books renders purchased books with a Read link and hides it for `revoked`; redeem success adds the book; each server error message is displayed; pending redemption shows the code after clicking Show; hard-copy list shows tracking code and label; unauthenticated shows the login link; Track page shows status, never renders an address, and shows the not-found message on 404.
- [ ] **Step 2: Run** `npx vitest run src/pages/MyBooksPage.test.jsx src/pages/TrackOrderPage.test.jsx` — Expected: FAIL.
- [ ] **Step 3: Implement**, reusing existing `Header`/`Footer`/`container` styles.
- [ ] **Step 4: Run** `npm test` and `npm run build` — Expected: PASS.
- [ ] **Step 5: Commit** — `feat(sp5): my books, code redemption and order tracking pages`

---

### Task 10: Reconcile and expire pending orders

**Files:**
- Create: `backend/src/Orders/OrderReconciler.php`, `backend/scripts/reconcile_orders.php`
- Test: `backend/tests/reconciler_test.php`

**Interfaces:**
- Consumes: `Gateway::fetchOrderPayments`, `OrderFulfillment::markPaid`.
- Produces: `OrderReconciler::run(Gateway $gateway, int $minAgeMinutes = 15, int $expireAfterHours = 24): array{paid:int,cancelled:int}` — for `pending` orders older than `$minAgeMinutes`: ask the gateway for the Razorpay order's payments; a `captured` one ⇒ `markPaid`; orders with no capture and older than `$expireAfterHours` ⇒ status `cancelled`, payment `failed`. `scripts/reconcile_orders.php` calls it with `GatewayFactory::make()` and prints counts (cron, e.g. every 15 min).

- [ ] **Step 1: Write failing tests** with an anonymous `Gateway` stub: a 20-minute-old pending order whose stub returns a captured payment ⇒ `paid` (access granted); a 25-hour-old order with no payments ⇒ `cancelled`; a 5-minute-old order untouched; a 25-hour-old order that the stub reports captured ⇒ `paid` (not cancelled); a `paid` order untouched.
- [ ] **Step 2: Run** `docker compose exec -T php php tests/reconciler_test.php` — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — Expected: PASS; regression gates.
- [ ] **Step 5: Commit** — `feat(sp5): reconcile and expire pending orders`

---

### Task 11: Backend wrap-up (docs, full backend regression)

**Files:**
- Modify: `backend/README.md` (new env keys; endpoint table rows for every route added in Tasks 4–8; cron lines for `send_emails.php` and `reconcile_orders.php`; webhook setup note: URL `/api/webhooks/razorpay`, events `payment.captured`, `payment.failed`, ngrok for local), `backend/TEST_RESULTS.md` (append SP-5 results)

- [ ] **Step 1: Run the full backend suite** — all `backend/tests/*_test.php` via `docker compose exec -T php php tests/<file>`, plus `bash backend/tests/book_test.sh`, `http_test.sh`, `order_test.sh`. Expected: zero FAIL lines.
- [ ] **Step 2: SP-4 payload check** — `curl localhost:8080/api/books` and `/api/books/<id>` still return exactly the SP-4 field sets (no new keys); private PDF path still 404 via nginx (`curl -I localhost:8080/../storage/...` style check from `book_test.sh` already covers it — confirm it passes).
- [ ] **Step 3: Start without Razorpay/SMTP env** — temporarily remove the Razorpay and SMTP keys from `.env`, restart php, run `book_test.sh` ⇒ PASS (proves lazy validation); restore `.env`.
- [ ] **Step 4: Document and commit** — `docs(sp5): backend README and test results`

---

### Task 12: Frontend API client, referral capture, Razorpay loader

**Files:**
- Create: `src/api/orders.js`, `src/utils/referral.js`, `src/utils/razorpay.js`
- Modify: `src/App.jsx` (call referral capture once on load)
- Test: `src/api/orders.test.js`, `src/utils/referral.test.js`, `src/utils/razorpay.test.js`

**Interfaces:**
- Produces (all use `apiRequest` from `./client`):
  - `fetchQuote(bookId, type)` → `GET /api/checkout/quote?bookId=&type=`; `validateReferralCode(code)` → `GET /api/referral/validate/{code}` (URL-encoded); `createOrder(token, {bookId, purchaseType, referralCode, address})`; `verifyPayment(token, orderId, {razorpay_order_id, razorpay_payment_id, razorpay_signature})`; `fetchMyOrders(token)`; `fetchMyOrder(token, orderId)`.
  - `captureReferralFromUrl(search = window.location.search): void` stores `?ref=CODE` (uppercased, 30-char cap) in `localStorage['vtee-referral']`; `getStoredReferral(): string` (`''` when none); both wrapped in try/catch like `SessionContext`.
  - `loadRazorpay(): Promise<RazorpayConstructor>` injecting `https://checkout.razorpay.com/v1/checkout.js` once (memoised promise; rejects on script error).

- [ ] **Step 1: Write failing tests**: each API function calls `apiRequest` with the right path/method/token/body (spy on `./client`); `captureReferralFromUrl('?ref=vtp-abc')` stores `VTP-ABC`, ignores missing/garbage, survives a throwing `localStorage`; `loadRazorpay` appends one script tag across two calls and resolves with `window.Razorpay` after `onload`, rejects on `onerror`.
- [ ] **Step 2: Run** `npx vitest run src/api/orders.test.js src/utils/referral.test.js src/utils/razorpay.test.js` — Expected: FAIL.
- [ ] **Step 3: Implement**; wire the capture call into `App.jsx` as a tiny component alongside `ScrollReveal`.
- [ ] **Step 4: Run** — Expected: PASS; `npm test` still green.
- [ ] **Step 5: Commit** — `feat(sp5): frontend orders api, referral capture, razorpay loader`

---

### Task 13: Checkout page

**Files:**
- Create: `src/components/checkout/PriceBreakdown.jsx`, `AddressForm.jsx`, `ReferralCodeInput.jsx`
- Modify (rewrite): `src/pages/CheckoutPage.jsx`, `src/pages/CheckoutPage.test.jsx`
- Test: `src/components/checkout/*.test.jsx`

**Interfaces:**
- Produces:
  - `<PriceBreakdown quote />` — renders labelled rows **Book price, Shipping, GST (n%) / "GST (included)", Total payable** from `{subtotal,shipping,gst,gstPercent,gstMode,total}`; zero values still render as `₹0.00`; amounts via `toFixed(2)`.
  - `<AddressForm value onChange errors />` — controlled; fields labelled Full name, Phone, Address, City, District, State, Pincode (ids `addr-<field>`); shows `errors[field]`.
  - `<ReferralCodeInput value onChange />` — text input labelled "Referral code (optional)"; on blur calls `validateReferralCode` and shows "Code applied" / "Code not recognised" (never blocks payment).
  - `CheckoutPage` (`/checkout/:bookId`): loads `fetchBook(bookId)` (not found ⇒ "Book not found."); no `vtee-auth-token` ⇒ message with `<Link to="/account">` "Log in to continue"; purchase-type radios (Digital / Hard Copy with ₹ price); refetches `fetchQuote` on type change; address form only for hard copy; referral input prefilled from `getStoredReferral()`; **Pay with Razorpay** button → `createOrder` → `loadRazorpay()` → `new Razorpay({key, amount, currency, order_id, name:'VTEE', prefill:{email}, handler, modal:{ondismiss}})` where `handler(resp)` calls `verifyPayment` then `navigate('/orders/'+order.id+'/result')` (also navigate on verify error, the result page will poll); `ondismiss` and `payment.failed` show an inline message and re-enable the button (reusing the same created order); server `fields` errors map onto the address form; button disabled while submitting; no price is ever sent.

- [ ] **Step 1: Write failing tests** (spy on `api/books`, `api/orders`, mock `loadRazorpay` to return a stub constructor capturing options): breakdown rows render including `₹0.00` shipping/GST; switching to hard copy refetches the quote and shows the address form; submitting hard copy with the server returning `fields.pincode` shows that message; Pay → `createOrder` called with `{bookId, purchaseType, referralCode, address?}` and **no amount key**; stub's `handler` invoked ⇒ `verifyPayment` called with the three Razorpay fields then navigation to `/orders/…/result`; `ondismiss` shows the cancelled message and Pay is clickable again without a second `createOrder`; unpublished/unknown book shows "Book not found."; no token shows the login prompt and no Pay button; stored referral code prefilled.
- [ ] **Step 2: Run** `npx vitest run src/pages/CheckoutPage.test.jsx src/components/checkout` — Expected: FAIL.
- [ ] **Step 3: Implement** the components and rewrite the page; delete the mock `CREATE_ORDER`/`MARK_ORDER_PAID` usage from this page only (reducer stays until SP-8).
- [ ] **Step 4: Run** — Expected: PASS; `npm test` green apart from AccountPage tests handled in Task 14 (if any fail here only because of the page rewrite, fix in this task).
- [ ] **Step 5: Commit** — `feat(sp5): real checkout with breakdown, address and razorpay`

---

### Task 14: Order result page and Account orders

**Files:**
- Create: `src/pages/OrderResultPage.jsx`, `src/pages/OrderResultPage.test.jsx`
- Modify: `src/App.jsx` (route `/orders/:orderId/result`), `src/pages/AccountPage.jsx`, `src/pages/AccountPage.test.jsx`

**Interfaces:**
- Produces:
  - `OrderResultPage`: reads `vtee-auth-token`; polls `fetchMyOrder` every 3 s (fake-timer friendly) until `status !== 'pending'` or 60 s elapsed. `paid` ⇒ "Payment received", order number, breakdown total, hard copy ⇒ shipping city + "We will email you when it ships", digital ⇒ link to `/account`; `failed`/`cancelled` ⇒ "Payment failed" with link back to `/checkout/{bookId}`; still pending after 60 s ⇒ "We're still confirming your payment — you don't need to pay again; check My Orders shortly." Fetch error ⇒ shows the error message.
  - `AccountPage` logged-in view: loads `fetchMyOrders(token)` and lists every order (title, type, status, fulfilment status for hard copy, total, date); empty ⇒ "No orders yet."; the login/register half is unchanged. The mock-driven "My Books" list and its `Read` link are removed here (the real library arrives in SP-6).

- [ ] **Step 1: Write failing tests**: result page — paid immediately; pending then paid after a poll (fake timers); failed; timeout message after 60 s; hard-copy shows city. AccountPage — logged-in state renders orders from the mocked `fetchMyOrders`, shows hard-copy fulfilment text, empty state; existing login-success and error tests keep passing (login success now ends in "No orders yet." with an empty mocked list); delete the old `SeedOrdersAndLogin` test.
- [ ] **Step 2: Run** `npx vitest run src/pages/OrderResultPage.test.jsx src/pages/AccountPage.test.jsx` — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `npm test` — Expected: all PASS; `npm run build` succeeds.
- [ ] **Step 5: Commit** — `feat(sp5): order result polling and real account orders`

---

### Task 15: End-to-end verification

**Files:** none new (results appended to `backend/TEST_RESULTS.md`).

- [ ] **Step 1: Full automated pass** — backend suite (Task 11 Step 1), `npm test`, `npm run build`. Expected: zero failures.
- [ ] **Step 2: Browser E2E on the fake gateway** (Playwright/Chrome MCP against `npm run dev` + docker stack): register → book page → digital checkout shows breakdown → pay (fake handler path: temporarily call verify with a signed fake payment from the test harness) → result "Payment received"; hard-copy checkout with address → result shows shipping city; failed payment path shows retry; abandon-and-return shows pending order in My Orders.
- [ ] **Step 3: Real Razorpay test mode (needs owner's test keys, U6)** — set `RAZORPAY_GATEWAY=live`, test key id/secret/webhook secret, expose the webhook with ngrok; pay with a Razorpay test card/UPI success and failure; confirm verify path, then a second run closing the tab before the handler fires proves the webhook alone fulfils the order; replay the webhook from the Razorpay dashboard and confirm no duplicates. Record outcomes.
- [ ] **Step 4: Release gate checklist in `TEST_RESULTS.md`** — list the open blockers: U1 shipping and U2 GST settings confirmed and set, U5 SMTP configured, U7 production host/HTTPS, and "do not release before SP-6 (reader)".
- [ ] **Step 5: Commit** — `docs(sp5): verification results and release gate`

---

## Self-Review

- **Spec coverage:** R1 flat commission (T6, settings, tests); R2 breakdown/config (T2, T4, T13); R6 server-authoritative pricing, signature + gateway verification, webhook signature + idempotency, no frontend-granted access (T3, T5–T8, Global Constraints); R5 email with optional SMTP (T9); hard-copy address/order handling (T4, T5, T6, T7); referral attribution + self-referral (T4, T5); failed payments, duplicate webhooks (T6, T8); SP-4 regression (every task + T11). R3 refunds, R4 partner admin UI, R7 reader/Poppler, refund/reader E2E scenarios are SP-7/SP-6/SP-8 by the design's scenario-ownership table; SP-5 leaves the data model ready (`admin_flag`, commissions `pending`, `payment_events`) and registers no refund or reader endpoints.
- **Type consistency:** `OrderFulfillment::markPaid(string,string,int,array)` and `markFailed(string,string,string)` are used with the same signatures in T7, T8, T10; `Gateway` method names identical in T3, T5, T7, T10; `OrderFormatter::format` shape is what T13/T14 consume; the order JSON keys (`total`, `status`, `fulfilmentStatus`, `address`) match the frontend tests.
- **Review Focus** lines map to tests in T2 (money), T4 (address), T5/T7 (tampering/IDOR), T6/T8/T10 (events), T9 (email).
- **Proportion:** bodies intentionally left to the implementer; only signatures, rules and assertions with the spec's exact values appear.
