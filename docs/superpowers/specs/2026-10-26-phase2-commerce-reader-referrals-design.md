# Phase 2: Payments, Reader, Orders, Referrals — Design (DRAFT for review)

Date: 2026-10-26, **revised 2026-10-27 with the owner's business decisions (§12 "Resolved"; unresolved items flagged in §12 "Unresolved").** Status: **design approved by owner; SP-5 plan written, nothing implemented.** Builds on SP-4 (merged 459a7bc). Stack unchanged: React + Vite frontend, PHP 8.2 + MySQL 8 backend (custom Router, PDO, JWT), Docker.

## 1. Intent

Turn the mock purchase flow into a real one. Today `CheckoutPage`, `ReaderPage`, `AccountPage` orders, and the admin Orders / Commissions / Referrals / Customers pages run on `SessionContext` + localStorage. Phase 2 replaces that with server-enforced payments, access control, fulfilment, and commission accounting.

**Goals**
- Real Razorpay payments for digital and hard-copy purchases; server is the only authority on price and on "paid".
- Paid digital buyers read the full book in a protected reader; everyone else sees only the SP-4 public index + preview pages.
- Hard-copy orders capture a shipping address and move through admin-managed fulfilment states.
- Referral partner attribution → commission record → payable → paid, reversed on refund.
- Admin can refund through Razorpay and see orders, customers, commissions.

**Non-goals (v1)**: cart / multi-book orders, coupons, subscriptions, partner self-service login/dashboard (architecture stays extensible, see §4.4), automated UPI payouts, shipping-carrier API integration, invoices/GST PDFs, SMS, partial refunds (full refunds only; see U3), DRM-grade copy protection. **Email notifications are in scope** (§4.7).

**Anti-goals**: price or "paid" ever decided by the browser; a PDF URL that works without a purchase; a payment captured with no order/access recorded; SP-4 behaviour changing.

## 2. Findings from the current code (what already exists)

| Area | Exists | Gap |
|---|---|---|
| Schema (`001_init.sql`) | `orders`, `payments` (razorpay_* cols), `book_access` (unique user+book), `hardcopy_addresses`, `referral_applications`, `referral_partners`, `referral_mappings`, `commissions` (unique order), `settings` | No uniqueness on razorpay ids, no webhook-event dedupe, no refunds table, no fulfilment fields, no revoked access, commission enum lacks `reversed` |
| Backend | auth, `/api/me`, books public + admin CRUD/uploads | **No** orders, payments, reader, referral, commission, admin order/customer endpoints |
| Private PDF | `storage/books/{id}/…` outside nginx root (`digital_file_path`) | Not renderable in a browser yet; Dockerfile has no PDF rasteriser |
| Frontend | pages exist, all on mock reducer; `AccountPage` does real login but then mirrors only email into the mock | Checkout/Reader/Orders/Referrals/Commissions all mock; checkout lists partner **names** publicly |
| Settings | `commission_rate_digital/hardcopy = 25` | Unit unclear: schema seed reads as %, admin UI says ₹ (see Q1) |
| `referral_mappings` | duplicates `orders.referral_partner_id` | Redundant; leave unused, do not drop |

## 3. Proposed decomposition

Four sub-projects, in order. Each is independently shippable and keeps SP-4 tests green.

| SP | Scope | Depends on |
|---|---|---|
| **SP-5 Orders & Razorpay** *(plan: `docs/superpowers/plans/2026-10-27-sp5-orders-razorpay.md`)* | pricing breakdown (price / shipping / GST / total), order creation, Razorpay order, verify, webhook + idempotency, hard-copy address capture, referral-code snapshot + commission row creation (flat ₹), email outbox + SMTP (optional), reconcile script, customer orders API, Checkout/Result/Account UI | Razorpay test keys |
| **SP-6 Secure reader** | Poppler (`poppler-utils`) in the PHP image, PDF→page images, access API, reader UI, library | SP-5 (`book_access`) |
| **SP-7 Referrals, commissions & refunds** | customer refund *requests* + admin review, refund state machine, commission reversal, access revocation, partner application/approval backend, admin commissions/partners/config UI | SP-5 |
| **SP-8 Admin panel** | orders list/detail/fulfilment, customers, dashboard stats; remove mock reducer pieces | SP-5, SP-7 |

SP-6 and SP-7 can run in parallel after SP-5.

## 4. Workflows

### 4.1 Purchase (digital or hard copy)
1. Customer (JWT) on book page → Checkout. Chooses type; hard copy shows address form; optional referral code (prefilled from `?ref=CODE` link stored in localStorage).
2. `POST /api/orders` `{bookId, purchaseType, referralCode?, address?}`. Server: book must be published and not archived; price read **from DB**; digital refused if `book_access` exists; hard copy requires valid address; referral code resolved to an active approved partner (silently ignored if invalid, plus self-referral blocked: partner gmail = customer email). Inserts `orders` (pending, amount snapshot, partner snapshot) + `payments` (created) after calling Razorpay `POST /v1/orders` (amount in paise, `receipt` = order_number, `notes.order_id`). Returns `{orderId, razorpayOrderId, keyId, amount, currency}`.
3. Browser opens Razorpay Checkout.js; on success gets `{razorpay_payment_id, razorpay_order_id, razorpay_signature}`.
4. `POST /api/orders/{id}/verify` with those three. Server: order belongs to caller; `hash_equals(hmac_sha256(rzp_order_id|payment_id, key_secret), signature)`; then `GET /v1/payments/{id}` to confirm `status=captured`, amount and order id match. Then `OrderFulfillment::markPaid()`.
5. **Webhook** `POST /api/webhooks/razorpay` (`payment.captured`, `payment.failed`, `refund.processed`) calls the same `markPaid()` so a closed tab never loses a paid order.
6. `markPaid(order)` — one DB transaction, `SELECT … FOR UPDATE` on the order, no-op if already paid: order→paid, payment→captured (store ids/raw), digital → insert `book_access`, hard copy → `fulfilment_status='pending'`, referral → insert `commissions` (snapshot amount + rate).

Failure paths: payment failed/cancelled → order `failed`/`cancelled`, user can retry (new Razorpay order, same app order allowed while pending); pending orders older than 24h → `cancelled` lazily on read or by a cron script; amount mismatch → order flagged, not paid, logged for admin.

### 4.2 Reading
1. Library/Book page calls `GET /api/books/{id}/access` → `{owned, pageCount}`.
2. Reader loads `GET /api/books/{id}/read/pages/{n}` as an authenticated `fetch` → blob → `<img>` (headers can't ride on `<img src>`). Server checks JWT + non-revoked `book_access`; streams cached page JPEG with `Cache-Control: private, no-store`.
3. Page images generated once per book from the private PDF (on PDF upload or lazily on first read) into `storage/books/{id}/pages/{n}.jpg`; the PDF itself is never sent to browsers.
4. Non-owners: reader route shows the SP-4 index + preview pages and a Buy button; page endpoint returns 403.
5. Deterrents (honest limits — screenshots cannot be prevented): per-user watermark overlay, block context menu/drag, no PDF download, per-user request throttle against bulk scraping, access revoked on refund.

### 4.3 Hard-copy fulfilment
Paid hard-copy order → `fulfilment_status`: `pending → packed → shipped → delivered`, or `cancelled` (→ triggers refund). Admin sets courier + tracking number on `shipped`. Customer sees status/tracking on Account → Orders. Address is snapshotted on the order (`hardcopy_addresses`, already one-per-order), validated: 6-digit pincode, 10-digit Indian phone, required fields.

### 4.4 Referral attribution and commission
- Registration: `POST /api/referral/applications` (public, rate-limited, validated) → admin approves → partner row created with generated unique `referral_code` (`VTP-xxxxxx`, not sequential/guessable). Reject keeps application only.
- Attribution: code captured at order creation and snapshotted on the order; later partner deactivation/edit does not change history. First-touch via stored `?ref=`; the checkout field overrides.
- **Flat amounts, not percentages.** Commission = ₹25 per eligible paid digital order and ₹25 per eligible paid hard-copy order, read from `settings` keys `commission_rate_digital` / `commission_rate_hardcopy` (existing keys; values are now defined as flat ₹). Admin edits them in SP-7 UI; SP-5 reads them. The amount is snapshotted into `commissions.amount` when the order is paid, so later config changes never alter history.
- **Eligible** = order reaches `paid`, partner was active+approved at order creation, partner is not the buyer (gmail ≠ buyer email, case-insensitive). One commission per order (`UNIQUE(order_id)` already enforced).
- Lifecycle: `pending` → `payable` → `paid` (admin enters payout reference, e.g. UPI txn id); `reversed` if the order is refunded before payout. Refund after payout → commission stays `paid` and is flagged `clawback_due`. Whether a hold period precedes `payable` is **U4**.
- **No partner login in v1.** Admin manages partners, referrals and commissions (SP-7). Extensibility: commissions and orders are keyed by `referral_partner_id`; a future dashboard adds credentials/`user_id` to `referral_partners` and read-only `/api/partner/*` endpoints over the same tables — no schema rework needed.

### 4.5 Refund (admin-reviewed) — built in SP-7
**States** (`refunds.status`): `requested` (customer asks, reason required) → `approved` (admin accepts) → `processing` (Razorpay refund call accepted) → `refunded` (confirmed by API response or `refund.processed` webhook). Terminal alternatives: `rejected` (admin declines, note required) and `failed` (Razorpay error; admin may retry → back to `approved`).
- Customer: `POST /api/me/orders/{id}/refund-request {reason}`; only for own `paid` orders. Admin: list/review `GET /api/admin/refunds`, `POST /api/admin/refunds/{id}/approve|reject`. Admin may also open a refund directly (state starts `approved`).
- **Duplicate-safe**: a stored generated column `is_open` = 1 when status ∈ (`requested`,`approved`,`processing`,`refunded`) else NULL, with `UNIQUE(order_id, is_open)` — a second request for the same order is rejected by the database, and returns the existing refund instead of an error. The Razorpay call carries `receipt`/idempotency from the refund id and is guarded by a row lock; `razorpay_refund_id` is `UNIQUE`; replayed `refund.processed` webhooks are no-ops via `payment_events`.
- **On `refunded`** (one transaction): payment→`refunded`, order→`refunded`; **digital**: `book_access.revoked_at` set so the reader returns 403 immediately; **hard copy**: no access to revoke, fulfilment set `cancelled` if not yet shipped; **commission**: `pending|payable` → `reversed`, `paid` → `clawback_due=1`; email sent (§4.7).
- Eligibility windows and shipped-goods rule are **U3**; until answered, SP-7 ships with admin discretion (no automatic window).

### 4.6 Pricing breakdown (price, shipping, GST, total)
Digital and hard-copy prices are separate (`books.online_price`, `books.hardcopy_price`, already exist). Shipping and GST are **unconfirmed (U1, U2)**, so they are *configuration*, defaulting to ₹0 / 0% so behaviour is "price = payable" until the owner confirms. The server computes everything (integer paise internally) and checkout displays every line, including zero lines, so nothing is hidden:

`settings` keys: `shipping_charge_hardcopy` (₹), `shipping_charge_digital` (₹, default 0), `gst_percent_digital`, `gst_percent_hardcopy`, `gst_mode` = `inclusive` | `exclusive`.
- taxable = price + shipping (assumption: GST also applies to shipping — U2).
- `exclusive`: gst = round(taxable × rate / 100); total = taxable + gst.
- `inclusive`: gst = round(taxable × rate / (100 + rate)) (shown as "includes GST"); total = taxable.
- `GET /api/checkout/quote?bookId=&type=` returns `{subtotal, shipping, gst, gstPercent, gstMode, total, currency}`; `POST /api/orders` recomputes and stores the same breakdown on the order. A quote is informational — the order's stored total is what Razorpay is asked to charge. **Release blocker:** owner confirms U1/U2 and sets the settings before go-live.

### 4.7 Email notifications
Events: order confirmation / payment received (SP-5), payment failed (SP-5), hard-copy dispatched/delivered (SP-8), refund requested/approved/rejected/processed (SP-7). All go through an `email_outbox` table: rows are written in the same transaction as the state change; delivery is attempted after commit and by `scripts/send_emails.php` (cron) for retries. SMTP is configured only via env (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_SECURE`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`); no credentials in code or repo. **If `SMTP_HOST` is empty, rows are marked `skipped` and payment/order processing is unaffected.** A mail failure can never fail or roll back a payment. Subjects and recipient names are stripped of CR/LF (header-injection guard) and HTML is escaped.

## 5. Database changes — `migrations/003_orders_payments.sql` (additive only)

> Revision 2026-10-27: migrations are now per sub-project. **003** (SP-5) carries the SP-5 subset: everything marked ⟨5⟩ plus the `orders` fulfilment / tracking / referral-snapshot columns, the `payments` unique indexes and `failure_reason`, and `payment_events`; refunds, `book_access.revoked_at`, and the commission-status extension ship in **004** (SP-7); fulfilment/tracking edits need no further columns. The block below remains the combined target.

```
orders:  + paid_at DATETIME NULL                                 ⟨5⟩
         + subtotal_amount, shipping_amount, gst_amount DECIMAL(10,2) NOT NULL DEFAULT 0  ⟨5⟩  (existing `amount` = total payable)
         + gst_percent DECIMAL(5,2) NOT NULL DEFAULT 0, gst_mode ENUM('inclusive','exclusive') NOT NULL DEFAULT 'inclusive'  ⟨5⟩
         + admin_flag VARCHAR(100) NULL   ⟨5⟩ -- 'amount_mismatch' | 'duplicate_digital_purchase' (needs admin attention)
         + email_outbox + rate_limits tables ⟨5⟩ (below)
         + fulfilment_status ENUM('not_applicable','pending','packed','shipped','delivered','cancelled') NOT NULL DEFAULT 'not_applicable'
         + courier VARCHAR(100) NULL, tracking_number VARCHAR(100) NULL, shipped_at DATETIME NULL
         + referral_code_snapshot VARCHAR(30) NULL
         + INDEX (user_id, created_at), INDEX (status, created_at)
payments: + UNIQUE (razorpay_order_id), UNIQUE (razorpay_payment_id)
         + failure_reason VARCHAR(255) NULL
payment_events (new): id, event_id VARCHAR(100) UNIQUE, event_type, payload JSON, processed_at   -- webhook dedupe/audit
email_outbox (new ⟨5⟩): id, to_email, subject, body_text, body_html, status ENUM('pending','sent','failed','skipped'), attempts, last_error, created_at, sent_at
rate_limits (new ⟨5⟩): bucket VARCHAR(190), window_start DATETIME, hits INT, PRIMARY KEY (bucket, window_start)
refunds (new, SP-7): id, order_id FK, payment_id FK, requested_by_user_id NULL, reviewed_by_admin_id NULL,
               status ENUM('requested','approved','processing','refunded','rejected','failed'), reason, admin_note,
               amount, razorpay_refund_id UNIQUE NULL, is_open TINYINT GENERATED (status IN requested/approved/processing/refunded → 1 ELSE NULL) STORED,
               UNIQUE (order_id, is_open), created_at, updated_at
book_access: + revoked_at DATETIME NULL
commissions (SP-7): status ENUM('pending','payable','paid','reversed')   -- MODIFY (superset, existing rows valid)
             + payable_at DATETIME NULL, paid_at DATETIME NULL, payout_reference VARCHAR(100) NULL, clawback_due TINYINT(1) DEFAULT 0
             (`amount` already is the flat ₹ snapshot; SP-5 only inserts rows as `pending`)
books.page_count INT NULL (SP-6)
settings ⟨5⟩: + shipping_charge_hardcopy, shipping_charge_digital, gst_percent_digital, gst_percent_hardcopy, gst_mode
             (all seeded 0 / 0 / 0 / 0 / inclusive); commission_rate_* now mean flat ₹ (seed stays 25)
referral_applications: unchanged (backend finally uses it)
```
Not dropped: `referral_mappings`. Money stays DECIMAL(10,2); Razorpay calls convert to integer paise in one helper.

## 6. Backend API

New `Controllers`: `OrderController`, `PaymentWebhookController`, `ReaderController`, `ReferralController`, `AdminOrderController`, `AdminRefundController`, `AdminReferralController`, `AdminCommissionController`, `AdminCustomerController`. Services: `Razorpay\Client` (curl, no SDK/Composer needed; interface so tests use a fake), `OrderFulfillment`, `PdfPageRenderer`, `CommissionService`.

| Method + path | Auth | Purpose |
|---|---|---|
| POST `/api/orders` | customer | create order + Razorpay order |
| POST `/api/orders/{id}/verify` | customer (owner) | signature + server-side payment check |
| GET `/api/me/orders`, `/api/me/orders/{id}` | customer | history, fulfilment status |
| GET `/api/me/library` | customer | owned digital books |
| GET `/api/books/{id}/access` | optional | `{owned, pageCount}` |
| GET `/api/books/{id}/read/pages/{n}` | customer + access | page image |
| POST `/api/webhooks/razorpay` | HMAC signature | async truth |
| POST `/api/referral/applications` | public (throttled) | partner signup |
| GET `/api/referral/validate/{code}` | public (throttled) | checkout code check (returns valid + masked partner name only) |
| GET `/api/admin/orders` (filters, pagination), GET `/{id}` | admin | list/detail |
| PUT `/api/admin/orders/{id}/fulfilment` | admin | state, courier, tracking |
| GET `/api/checkout/quote?bookId=&type=` | public | price / shipping / GST / total (SP-5) |
| POST `/api/me/orders/{id}/refund-request` | customer (owner) | open a refund request (SP-7) |
| GET `/api/admin/refunds`, POST `/{id}/approve`, `/{id}/reject`, `/{id}/retry` | admin | refund review (SP-7); approve triggers Razorpay refund |
| POST `/api/admin/orders/{id}/refund` | admin | admin-initiated refund (creates it already `approved`) (SP-7) |
| GET `/api/admin/customers` | admin | list + order counts |
| GET `/api/admin/referrals`, POST `/{id}/approve`, `/{id}/reject`, `/{id}/toggle-active` | admin | partners |
| GET `/api/admin/commissions`, POST `/{id}/mark-paid`, GET/PUT `/api/admin/settings` | admin | commissions, rates |
| GET `/api/admin/dashboard` | admin | counts/revenue |

SP-4 endpoints untouched. `GET /api/books/{id}` response stays identical (access is a separate call).

## 7. Frontend

Keep React Router + `apiRequest` pattern; add `src/api/orders.js`, `referrals.js`, `reader.js`, `admin.js`.
- **Checkout**: rewrite `CheckoutPage`; new `AddressForm`, `ReferralCodeInput`, `useRazorpay` (loads `https://checkout.razorpay.com/v1/checkout.js` once), `OrderResultPage` (success / pending / failed with retry). Requires login (redirect to `/account`, return after).
- **Reader**: rewrite `ReaderPage` using `ReaderViewer` (blob-fetched page, prev/next, jump-to-page, keyboard, watermark with email + order no.), `IndexPanel` (SP-4 index), purchase CTA when not owned. New **Library** section on `AccountPage`.
- **Account**: orders from API with fulfilment/tracking.
- **Admin**: Orders (filters, detail drawer, fulfilment form, refund button with confirm), Customers, Referrals (approve/reject/toggle), Commissions (rates, per-order rows, mark-paid), Dashboard stats — all via admin token like `AdminBooksPage`.
- **Cleanup (SP-8)**: delete `CREATE_ORDER`, `MARK_ORDER_PAID`, commission/referral reducers and `data/referralPartners.js` once no page reads them. Existing tests that mock the reducer get rewritten to mock `api/*`.
- Referral link: `?ref=CODE` captured at app load → localStorage (try/catch as elsewhere).

## 8. Security requirements

- Price, availability, ownership computed server-side only; client amount ignored.
- Razorpay signature via `hash_equals`; webhook signature over **raw body** with a separate webhook secret; reject on mismatch with 400; dedupe by event id.
- `RAZORPAY_KEY_ID / KEY_SECRET / WEBHOOK_SECRET` in `.env`; `config/env.php` refuses placeholders (same pattern as `JWT_SECRET`). Secret never reaches frontend; only key id.
- All state changes in transactions with row locks; unique indexes make double-processing impossible even if code races.
- Ownership checks on every `/api/me/*` and order endpoint (IDOR): `WHERE id=? AND user_id=?`.
- Reader: authenticated, `no-store`, PDF never exposed, page files outside web root, path built from integer ids only (reuse SP-4 hardening), throttle per user.
- Admin endpoints `requireRole(['admin'])`; refund and mark-paid write an audit row (admin id, time).
- Public endpoints (applications, code validation) rate-limited — **needs a mechanism** (DB-backed counter table; no Redis in stack).
- Input validation via existing `Validator`; address/phone/pincode/UPI format checks; output never includes other users' data.
- Don't log full payment payloads at info level; `raw_response` kept in DB only. HTTPS required in production for webhook and checkout.
- Known inherited risk: JWT in localStorage (SP-2 decision) — XSS would expose tokens; out of scope, noted.

## 9. Error handling

Uniform `ApiException` codes (`already_owned`, `invalid_referral` (soft), `payment_verification_failed`, `amount_mismatch`, `gateway_error`, `not_found`, `forbidden`). Gateway timeouts → order stays `pending`, client shown "confirming payment…" and polls `GET /api/me/orders/{id}` while webhook lands (max ~60 s, then "we'll email/refresh later" copy). Razorpay 4xx/5xx mapped to 502 with generic message; details logged. Verify endpoint is idempotent: second call on a paid order returns success.

## 10. Test plan

Backend (shell HTTP style like `book_test.sh`, plus PHP unit tests with a `FakeRazorpay`):
1. Happy path digital: create → verify (valid sig) → paid, `book_access` row, reader page 200.
2. Tampered signature, wrong order id, amount mismatch → not paid, no access.
3. Webhook only (no verify call) → paid; replayed webhook → no duplicates; bad webhook signature → 400.
4. Verify + webhook racing → exactly one commission, one access row.
5. Digital re-purchase blocked; hard copy without/with bad address rejected; price change after order creation doesn't alter that order.
6. Referral: valid code attributed + commission snapshot; inactive/unknown code ignored; self-referral blocked; later rate change doesn't alter old commission.
7. IDOR: user B cannot read/verify user A's order or fetch A's reader pages; unauthenticated → 401; non-owner page fetch → 403.
8. Refund: access revoked, commission `reversed` (or `clawback_due` if paid); replayed refund webhook idempotent.
9. Fulfilment transitions legal/illegal; non-admin forbidden.
10. **SP-4 regression**: `book_test.sh` 23/23, backend suite 52/52, public book payloads unchanged, private PDF still 404 from web.
11. Pricing: quote equals order total for every book/type; inclusive and exclusive GST exact to the paise; zero shipping/GST renders as ₹0 lines.
12. Duplicate refund requests (double click, two tabs, replayed webhook) yield exactly one refund and one reversal.
13. Email: SMTP unset → order still paid, outbox `skipped`; SMTP down → order paid, outbox `pending`/`failed`; CR/LF in name/subject cannot inject headers.

**Scenario ownership** — SP-5: 1–7 (excluding reader page fetch), 10 (re-run), 11, 13, hard-copy order creation/address/fulfilment=`pending`, failed-payment and duplicate-webhook flows. SP-6: reader parts of 1 and 7, unauthorized/revoked reader access, 10 (re-run). SP-7: 8, 12, commission reversal/clawback. SP-8: 9, admin order views. Every sub-project re-runs the SP-4 suites.

Frontend (Vitest, mocked `api/*` + mocked Razorpay global): checkout types/address validation, success/failure/dismissed modal, polling state, reader gated vs owned, page navigation bounds, admin refund confirm, existing 63 tests still pass or are consciously rewritten.
Manual E2E in Razorpay **test mode** (test cards/UPI success + failure), plus a webhook run through a tunnel (e.g. ngrok) since localhost isn't reachable by Razorpay.

## 11. Dependencies and risks

**Dependencies**: Razorpay account + test keys, webhook secret and public HTTPS URL for webhooks; PDF rasteriser in the PHP image (recommend `poppler-utils` → `pdftoppm`, no PHP extension; alternative Imagick+Ghostscript); PHP `curl` (present in base image, verify); storage volume sized for page images (~100–300 KB × pages × books); admin-supplied commission/refund/shipping policy (§12).

| Risk | Mitigation |
|---|---|
| Paid but order not updated (tab closed) | webhook + idempotent `markPaid`, reconciliation script for orders pending > N min that queries Razorpay |
| Double fulfilment (verify vs webhook) | row lock + unique indexes |
| Page-image scraping / sharing | watermark, throttle, revocation; accept residual risk |
| PDF render slow/large on upload | render in background command or lazily with a lock; cap pages/size (50 MB already enforced) |
| Pending-order buildup | expiry sweep script |
| Commission paid then refund | `clawback_due` flag + admin report |
| Scope: mock reducer removal breaks tests | do it last (SP-8), page by page |
| No email infra | status visible in account; email left as Q6 |
| Bullseye PHP image is dev-only (Dockerfile note) | decide prod image before go-live |

## 12. Business decisions and open questions

### Resolved (owner, 2026-10-27)

| # | Decision | Where applied |
|---|---|---|
| R1 | Referral commission is a **flat ₹25** per eligible paid digital order and **₹25** per eligible paid hard-copy order. No percentages. Configurable (backend `settings`, admin UI in SP-7). | §4.4, SP-5 snapshot |
| R2 | Separate digital and hard-copy prices. Shipping and GST are **not assumed**; checkout displays price, shipping, GST and final payable amount. | §4.6 (config, defaults 0) |
| R3 | Refunds are **admin-reviewed requests** with explicit states; approval updates payment/order, reverses commission, revokes digital access; duplicates handled safely. | §4.5 (SP-7) |
| R4 | **No partner login/dashboard in v1**; admin manages partners, referrals, commissions; architecture left extensible. | §4.4 |
| R5 | **Email notifications** planned (order confirmation, payment status, order updates); SMTP via env only; processing works with SMTP unset. | §4.7 |
| R6 | Backend sets the payable amount; Razorpay verified server-side; webhook signature validated; webhook idempotent; access never granted from the frontend success callback. | §4.1, §8 |
| R7 | Original PDF stays private; reader content only via authorized endpoints for verified purchasers; Poppler added to the PHP image in SP-6; screenshot/screen-recording prevention is **not guaranteed** (documented in §4.2 and in the reader UI copy). | SP-6 |
| R8 | SP-4 stays intact; regression, plus end-to-end tests for success/failed payment, duplicate webhooks, refunds, commission reversal, unauthorized reader access, hard-copy handling. | §10 |

### Unresolved — flagged, need an owner answer

| # | Question | Needed by | Interim default (so work can proceed) |
|---|---|---|---|
| U1 | **Shipping**: flat charge or free? Different per state? Any shipping on digital (assumed none)? | SP-5 go-live | ₹0, config `shipping_charge_hardcopy` |
| U2 | **GST**: rate for digital and for printed books (may differ; confirm with your accountant), are listed prices GST-inclusive or exclusive, is GST charged on shipping, is a GST invoice with GSTIN required? | SP-5 go-live | 0%, `inclusive`, GST applies to price+shipping |
| U3 | **Refund policy**: who may request, time window for digital and hard copy, does opening the reader forfeit a digital refund, must goods be returned, is shipping refunded, partial refunds ever needed? | SP-7 | admin discretion; full refunds only; no auto-window |
| U4 | **Commission hold**: pay out right after payment or after a refund-window hold (proposed 7 days)? Earned only on a customer's first purchase? Payout frequency? | SP-7 | no hold; every eligible order earns; manual payout |
| U5 | **Email**: SMTP provider, sender address/name; language (English, Tamil, both); admin new-order email (`ADMIN_NOTIFY_EMAIL`)? | SP-5 go-live | emails skipped until `SMTP_HOST` set; English |
| U6 | **Razorpay**: account KYC status, test keys, webhook secret, payment methods enabled, auto-capture on (assumed yes). | Start of SP-5 E2E | none |
| U7 | **Hosting**: where production runs (HTTPS URL for webhook, apt access for Poppler, cron for sweeps/email retry, production PHP image; the current Dockerfile is a dev-only bullseye pin). | SP-5 go-live | dev only |
| U8 | **Guest checkout**: login required before paying (assumed yes)? Can a customer cancel a hard-copy order before it ships? | SP-5 | login required; cancel = refund request |
| U9 | **Invoices/receipts**: is the confirmation email enough or is a PDF invoice needed? | later | email only |
| U10 | **Data retention**: how long to keep abandoned-checkout addresses and unpaid orders? | SP-8 | keep; no deletion job |
| U11 | First live book: use the POCSO Tamil+English zip as the E2E test book? | SP-5 E2E | none |

## 13. Decisions I made (my calls, change if wrong)

- Four sub-projects SP-5 → SP-8; refunds grouped with referrals (SP-7); email infrastructure built once in SP-5 and reused.
- Raw curl Razorpay client behind a `Gateway` interface (no Composer/SDK), with a `fake` gateway for tests that **refuses to boot in production**.
- PHPMailer vendored as source under `backend/lib/phpmailer/` (no Composer: compose bind-mounts `./backend` over the image, so a build-time `vendor/` would be hidden).
- Page-image reader over streamed PDF (a streamed PDF is a full download).
- Referral by code / `?ref=` link, replacing the public partner-name dropdown (it leaks the partner list).
- Money computed in integer paise; DB stays DECIMAL(10,2).
- Additive migrations only, one per sub-project; `referral_mappings` left in place unused.
- Single-book orders only; the legacy mock checkout is replaced, not kept alongside, because mock book ids are no longer linked from the real catalogue.
- SP-5 merges to master before SP-6 but is **not released to production alone**: until SP-6 lands a buyer would pay and then hit the mock reader.
