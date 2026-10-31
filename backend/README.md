# Backend

PHP 8.2 + MySQL 8.0, no framework, no Composer. See
`docs/superpowers/specs/2026-10-08-backend-foundation-design.md` for the
full design.

## Local setup

1. `cp backend/.env.example backend/.env` (already done if you're reading
   this from a fresh clone that included it — otherwise copy it yourself)
1b. **Generate a real `JWT_SECRET`** — do not leave the placeholder from
   `.env.example`. Run `php -r 'echo bin2hex(random_bytes(32));'` (or use
   the `php` service: `docker compose exec -T php php -r '...'` once it's
   up) and paste the output into `backend/.env`. The server refuses to
   start with the placeholder or any secret under 32 bytes.
2. `docker compose up -d --build`
3. Apply the schema: see "Migrations" below.
4. API is reachable at `http://localhost:8080`.
5. In a production deployment, `backend/public/uploads/` and `backend/storage/`
   must exist and be writable by the PHP process's user before first use —
   they're gitignored (correctly, since they hold user-uploaded content) so a
   fresh deploy won't have them yet, and this codebase can't create them for
   itself if the process lacks permission to do so.

## Environment variables

See `backend/.env.example` for the full list: `DB_HOST`, `DB_PORT`,
`DB_NAME`, `DB_USER`, `DB_PASS`, `JWT_SECRET`, `FRONTEND_ORIGIN`, plus the SP-5 keys
`APP_ENV`, `RAZORPAY_GATEWAY` (`fake` is dev-only), `RAZORPAY_KEY_ID`,
`RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`, `CODE_SECRET` (>=32 random bytes).
These are validated lazily at use, so SP-4 endpoints work without them. Use placeholders
in docs and commits; real values live only in `backend/.env` (gitignored). In `live` mode
(the default when `RAZORPAY_GATEWAY` is unset) the three Razorpay keys must be real values;
`fake` is refused when `APP_ENV=production`. An empty `RAZORPAY_WEBHOOK_SECRET` is never
accepted for signature checks (fails closed, even in `fake` mode).

Email/SMTP notifications are a later phase: nothing sends mail yet and there are no SMTP keys.

## Migrations

Schema lives in `backend/migrations/*.sql`, applied in filename order.
Apply the initial schema against the running stack:

```bash
docker compose exec -T mysql mysql -u vtee -pchangeme vtee < backend/migrations/001_init.sql
# then 002_book_management.sql, 003_orders_payments.sql, 004_refunds.sql, 005_books_page_count.sql, 006_audit_logs_entity_index.sql and 007_commission_payable_at.sql, in order
```

Migrations are one-shot: re-applying a file fails on "already exists" /
duplicate column and changes no data. 003 to 007 are additive only; never drop the
volume to re-run them.

## API endpoints

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | /api/health | none | DB connectivity check |
| POST | /api/auth/register | none | `{name, email, phone?, password}` → 201 `{token, user}` or 409 if email taken |
| POST | /api/auth/login | none | `{email, password}` → 200 `{token, user}` or 401 |
| POST | /api/admin/login | none | `{username, password}` → 200 `{token, admin}` or 401 |

| GET | /api/me | Bearer JWT, any role | returns the caller's own profile, shape depends on role |

| GET | /api/books | none | Published, non-archived books. `[{id,title,description,category,coverUrl,onlinePrice,hardcopyPrice}]` |
| GET | /api/books/:id | none | One book + `indexUrl`, `previewPages`. 404 if missing/unpublished/archived |

| GET | /api/admin/books | admin | All books, any status, with `hasCover/hasIndex/hasPdf/previewPageCount` |
| GET | /api/admin/books/:id | admin | One book, any status |
| POST | /api/admin/books | admin | `{title, description?, category?, onlinePrice, hardcopyPrice}` → 201 |
| PUT | /api/admin/books/:id | admin | Partial update of any metadata field |
| POST | /api/admin/books/:id/publish | admin | sets published=1 |
| POST | /api/admin/books/:id/unpublish | admin | sets published=0 |
| POST | /api/admin/books/:id/archive | admin | sets archived=1, published=0 |
| POST | /api/admin/books/:id/cover | admin | multipart `file` (image, max 5MB) → `{coverUrl}` |
| POST | /api/admin/books/:id/index | admin | multipart `file` (image or PDF, max 5MB/50MB) → `{indexUrl}` |
| POST | /api/admin/books/:id/preview-pages/:pageNumber | admin | multipart `file` (image, max 5MB) → upserts that page, `{pageNumber, url}` |
| DELETE | /api/admin/books/:id/preview-pages/:pageNumber | admin | removes that page's file and row, `{deleted: true}` |
| POST | /api/admin/books/:id/pdf | admin | multipart `file` (PDF, max 50MB) → stores privately under `backend/storage/`, never publicly servable |

| GET | /api/checkout/quote?bookId=&type= | none | `type` = `digital`\|`hardcopy` → `{bookId,purchaseType,currency,subtotal,shipping,gst,gstPercent,gstMode,total}` (rupees). 400 `validation_error`, 404 `not_found` (missing/unpublished/archived book) |
| GET | /api/referral/validate/:code | none | `{valid:false}` or `{valid:true, partnerName:"A***"}` (masked). 30/min per IP → 429 `rate_limited` |
| POST | /api/orders | customer | `{bookId, purchaseType, referralCode?, address? (hardcopy)}` → 201 order + `razorpay:{keyId,orderId,amount,currency}`; amounts are always computed server-side. 400 `validation_error`, 404 `not_found`, 409 `already_owned` (digital already bought/redeemed), 429 `rate_limited` (20/hour per user), 502 `gateway_error` |
| POST | /api/orders/:id/verify | customer (owner) | `{razorpay_order_id, razorpay_payment_id, razorpay_signature}` → `{order}`; 200 paid/failed, 202 payment only authorized (webhook or reconcile finishes it). Status and amount are re-fetched from Razorpay, never trusted from the client. 400 `validation_error`/`payment_verification_failed`, 404 `not_found`, 409 `payment_amount_mismatch`, 502 `gateway_error` |
| GET | /api/me/orders | customer | caller's orders, newest first |
| GET | /api/me/orders/:id | customer (owner) | one order (hard copy includes its address); 404 `not_found` for anyone else's |
| POST | /api/webhooks/razorpay | none (HMAC signature) | Razorpay server-to-server events, see "Razorpay webhook setup". `{status: ok\|ignored\|duplicate\|flagged}`; 400 `invalid_signature` / `validation_error` |
| GET | /api/me/orders/:id/access-code | customer | `{code}` (`XXXX-XXXX-XXXX-XXXX`) while the paid digital order's code is unredeemed; else 404 |
| POST | /api/me/redeem | customer | `{code}` → `{bookId, orderId}`; the only way digital access is granted. Errors `code_invalid` 400, `code_revoked`/`code_order_not_paid` 403, `code_already_redeemed`/`already_owned` 409. Failed attempts limited to 5/15 min per user, 20/15 min per IP (429) |
| POST | /api/me/orders/:id/reissue-code | customer (owner) | revokes the current code (and any access it granted), returns a new `{code}`; 3/day. Only when the current code is active, redeemed by the buyer, or revoked for `customer_request`; otherwise `reissue_requires_admin` 403 (e.g. a gifted code redeemed by someone else, or an admin revoke) |
| GET | /api/me/library | customer | `{books:[{bookId,title,coverUrl,orderId,purchasedAt,accessStatus}], pendingRedemptions:[{orderId,bookId,title,coverUrl,purchasedAt}]}` |
| GET | /api/track/:trackingCode | none | hard-copy status `{orderNumber,bookTitle,status,label,updatedAt,courier?,trackingNumber?}`; never address, email or codes; 10/min per IP |
| POST | /api/me/orders/:id/refund-request | customer (owner) | `{reason}` (trimmed, 1–500 chars) → 201 `{refund}`, or 200 with the existing open/refunded refund (never a duplicate). `refund` = `{id,orderId,status,reason,amount,createdAt,adminNote}` (`adminNote` only when `rejected`). 400 `validation_error` (`fields.reason`), 404 `not_found` (not yours), 409 `not_refundable` (order not paid) |
| GET | /api/me/refunds | customer | caller's refunds (on their orders), newest first, same shape as above |
| GET | /api/admin/refunds?status= | admin | newest 200 refunds `[{id,orderId,orderNumber,purchaseType,userId,status,reason,adminNote,amount,razorpayRefundId,requestedByUserId,reviewedByAdminId,createdAt,updatedAt}]`; bad `status` → 400 |
| POST | /api/admin/refunds/:id/approve | admin | `{note?}` → `{refund}` `refunded` (gateway processed) or `processing` (finalised by the `refund.processed` webhook); idempotent once processing/refunded. 404, 409 `invalid_transition` (rejected) / `not_refundable` / `refund_conflict`, 502 `gateway_error` (refund left `failed`; approve again retries) |
| POST | /api/admin/refunds/:id/reject | admin | `{note}` required (1–500) → `{refund}` `rejected`; 409 `invalid_transition` once processing/refunded |
| POST | /api/admin/orders/:id/refund | admin | `{reason}` → 201 `{refund}` opened as `approved` and processed at once; 200 with the existing refund if one is open (an open customer request is approved). 404, 409 `not_refundable`, 502 `gateway_error` |
| GET | /api/books/:id/access | customer | `{owned, status: "active" / "revoked" / "none", pageCount?}` (`pageCount` only when active; `Cache-Control: private, no-store`). 404 `not_found` (id not plain digits), 500 `render_failed` |
| GET | /api/books/:id/read/pages/:n | customer + active `book_access` | `image/jpeg` page `n` (1..pageCount), `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`. 403 `forbidden` (no or revoked access; admins too), 404 `not_found` (bad id / page out of range), 429 `rate_limited` (240 per 5 min per user), 500 `render_failed` |
| GET | /api/admin/dashboard | admin | `{publishedBooks, totalCustomers, digitalPurchases, hardcopyOrders, successfulPayments, pendingOrders, revenue, refundedAmount, pendingRefunds, commissionPending, commissionPayable}`. Purchases/revenue count `paid` orders only (revenue is net: refunded orders leave `paid`); `successfulPayments` = orders `paid` or `refunded`; `pendingOrders` = paid hard copies `confirmed`/`processing`; `pendingRefunds` = refunds `requested`/`approved`/`processing`; `commissionPending`/`commissionPayable` = ₹ sums by commission status. Amounts are ₹ numbers |
| GET | /api/admin/orders?q=&type=&status=&fulfilment=&page=&perPage= | admin | `{items, page, perPage, total}`, newest first; `perPage` default 25, max 100. `q` (≤100 chars) matches order number / customer name / email / phone (substring, `%` `_` literal), tracking code (exact, any case), digital code last 4, or a pasted full code (exact, via its hash). Item: `{id, orderNumber, customer:{id,name,email}, book:{id,title}, purchaseType, status, amount, paidAt, createdAt, razorpayPaymentId, digitalCode:{status,last4}\|null, trackingCode, fulfilmentStatus, adminFlag}`. 400 `validation_error` (bad filter/page) |
| GET | /api/admin/orders/:id | admin | list item + `customer.phone`, `breakdown:{subtotal,shipping,gst,gstPercent,gstMode,total,currency}`, `address` (hard copy, else null), `courier`, `trackingNumber`, `shippedAt`, `referralCode`, `refunds[]` (admin refund shape), `commission` (or null), `auditLog[]` (order, its codes and refunds). Never a plaintext code, `code_enc` or hash. 404 |
| PUT | /api/admin/orders/:id/fulfilment | admin | `{status, courier?, trackingNumber?}` on paid hard-copy orders: `confirmed→processing→shipped→out_for_delivery→delivered` (`out_for_delivery` may be skipped), `cancelled` only from `shipped`/`out_for_delivery` and needs `reason` (1–255, kept in the audit row). `shipped` needs `courier` (1–100), sets `shipped_at`; `courier`/`trackingNumber` must not contain control characters. Checked under a row lock; writes an `order.fulfilment` audit row; customer `/api/me/orders/:id` and `/api/track/:code` show it at once. Returns the detail. 400 `invalid_transition` (illegal move, digital or unpaid order) / `validation_error`, 404, 409 `refund_required` when cancelling a `confirmed`/`processing` order: refund it with `POST /api/admin/orders/:id/refund` instead (completing the refund cancels fulfilment). Cancelling after shipping moves no money |
| GET | /api/admin/customers?q=&page=&perPage= | admin | `{items:[{id,name,email,phone,createdAt,orderCount,paidTotal}], page, perPage, total}`; `q` matches name/email/phone |
| GET | /api/admin/customers/:id | admin | customer + `orders[]` (newest 200, list item shape). 404 |
| POST | /api/admin/codes/verify | admin | `{code}` → `{status, bookTitle, orderNumber, orderStatus, redeemed}` (hash lookup; never echoes the code). 404 unknown, 400 non-string. 30 per 10 min per admin (429) |
| POST | /api/admin/orders/:id/code/revoke | admin | `{reason}` (1–255, not `customer_request`) revokes the order's current code (decided under the order lock) and any access it granted; returns the detail. 404 no code |
| POST | /api/admin/orders/:id/code/reissue | admin | `{reason}` (1–255, not `customer_request`) revokes the current code and returns `{code}` (`Cache-Control: no-store`): the only admin response that ever contains a plaintext code; give it to the customer, it is not shown again. 404 (not a paid digital order). 30 per 10 min per admin (429) |
| GET | /api/admin/audit-logs?entityType=&entityId=&page=&perPage= | admin | `{items:[{id,adminId,userId,action,entityType,entityId,detail,createdAt}], page, perPage, total}`, newest first |
| POST | /api/referral/applications | none | Partner sign-up `{name, qualification?, place?, district?, status (Student/Scholarship/Faculty/Institution), phone, whatsapp (10-digit Indian mobile, `+91`/spaces/dashes normalised), upiId? (name@bank or a 10-digit mobile), gmail}` (lengths per `referral_applications`; gmail lowercased) → 201 `{id, applicationStatus:"pending"}`. 400 `validation_error` with `fields`; 409 `duplicate_application` if that gmail has a pending/approved application. Free text is Unicode-trimmed (NBSP counts as space) and control characters (NUL, CR/LF, ANSI escapes) or invalid UTF-8 are rejected. Body over 16 KB → 413 `payload_too_large`. 5 valid submissions per hour per IP (`REMOTE_ADDR`; no `X-Forwarded-For` trust) → 429; the counter runs before the duplicate check, so a 409 duplicate uses budget, and the 409 does reveal that an email has applied (accepted: rate-limited, applicant-friendly) |
| GET | /api/admin/referrals?status=&page=&perPage= | admin | `{applications:{items:[{id,name,qualification,place,district,status,phone,whatsapp,upiId,gmail,applicationStatus,reviewedAt,createdAt,partnerId}],page,perPage,total}, partners:[{id,applicationId,name,phone,whatsapp,upiId,gmail,referralCode,active,createdAt,updatedAt}]}`; `status` filters applications (pending/approved/rejected); partners unpaginated |
| POST | /api/admin/referrals/:id/approve | admin | Only from `pending` (row-locked): creates the partner with `referral_code` `VTP-` + 6 random Crockford base32 chars (unique, retried on collision) → `{application, partner}`. 409 `duplicate_partner` if a partner already has that gmail; 400 `invalid_transition` otherwise, 404 |
| POST | /api/admin/referrals/:id/reject | admin | `{reason?}` (≤500, audit only); only from `pending` → `{application}`; no partner row |
| POST | /api/admin/partners/:id/toggle-active | admin | Flip `active` → partner. Inactive codes are unknown to validate/checkout; existing orders and commissions are untouched |
| PUT | /api/admin/partners/:id | admin | Partial `{name?, phone?, whatsapp?, upiId?, gmail?}` (same rules as the application; `upiId` ""/null clears) → partner. Any other key → 400 (`referral_code`/`active` not editable here); gmail already used by another partner → 409 `duplicate_partner` |
| GET | /api/admin/commissions?status=&partnerId=&page=&perPage= | admin | `{items:[{id,orderId,orderNumber,partner:{id,name,referralCode,active},purchaseType,amount,status,payoutReference,payableAt,paidAt,clawbackDue,refundOpen,reversedAt,createdAt}], page, perPage, total, totals:{pending,payable,paid,reversed,clawbackDue:{count,amount}}}`; totals honour `partnerId` only. Amounts are `"25.00"` strings |
| POST | /api/admin/commissions/:id/payable | admin | `pending` → `payable` (`payable_at`) → commission. Anything else 400 `invalid_transition` (`reversed` can never be paid). 409 `refund_open` while the order has a requested/approved/processing refund or is no longer `paid` (same hold for `paid`) |
| POST | /api/admin/commissions/:id/paid | admin | `{payoutReference}` (required, 1–100 of letters, digits, space `. / # : _ -`) ; `payable` → `paid` (`paid_at`) → commission. 400 `validation_error` / `invalid_transition` |
| GET / PUT | /api/admin/settings/commission | admin | `{digital, hardcopy}` flat ₹ per paid referral order, `"25.00"` strings; PUT needs both, 0–10000 with ≤2 decimals (string or number). Applies to orders paid afterwards only: amounts are snapshotted by `markPaid` |
| GET / PUT | /api/admin/settings/pricing | admin | `{shippingChargeDigital, shippingChargeHardcopy, gstPercentDigital, gstPercentHardcopy, gstMode}` (U1/U2). PUT is partial: shipping 0–10000 ₹, GST 0–100 %, ≤2 decimals, `gstMode` inclusive/exclusive; unknown keys / empty body 400. Applies to new quotes/orders only |

Access codes are stored as HMAC-SHA256 (pepper from `CODE_SECRET`) plus last 4 chars; the plaintext is kept
AES-256-GCM-encrypted (order id as AAD, so a blob copied to another order won't decrypt) only until redeemed. Rotating `CODE_SECRET` invalidates every unredeemed code.

Refunds are full refunds only, for the captured `payments.amount` (never a client value). States:
`requested` → `approved` → `processing` → `refunded`, or `rejected` / `failed`. One open refund per order is
enforced by the database (`UNIQUE(order_id, is_open)`). The Razorpay call (`receipt` = `refund_<id>`) runs under
a row lock on the refund, so two approvals can't both refund. On `refunded` (one transaction): payment and order
`refunded`; digital: the order's current code `revoked` (reason `refund`) and its redeemer's `book_access.revoked_at`
set; hard copy: fulfilment `cancelled` unless already shipped/out for delivery/delivered (then unchanged and an
`refund.already_shipped` audit row); commission `pending`/`payable` → `reversed`, `paid` stays paid with
`clawback_due=1`. A refunded order is never re-fulfilled. Every admin action writes `audit_logs` (`entity_type='refund'`).

Referral/commission admin actions are audited too: `referral.approve`/`referral.reject` (`referral_application`), `partner.toggle_active`/`partner.update` (`referral_partner`, from/to), `commission.payable`/`commission.paid` (`commission`), `settings.commission`/`settings.pricing` (`settings`, from/to). Commission rows are created only by `markPaid`; rate changes, partner edits and deactivation never touch existing rows. Attribution is snapshotted on the order at creation, so a deactivated partner still earns on orders created before deactivation once they are paid. Payout (payable/paid) is held with 409 `refund_open` while a refund is open; a refund that lands after payout leaves the row `paid` with `clawback_due=1`.

Secure reader: the PDF stays in `storage/books/{id}/book.pdf` (outside the web root, no route; nginx also 404s `/uploads/books/*/book.pdf` and `/uploads/books/*/pages/`). Pages are rendered lazily with Poppler (`poppler-utils`: `pdfinfo`, `pdftoppm -jpeg -r 110`, argument-array `proc_open`, 30 s timeout, per-page `flock`, temp file + atomic rename) into `storage/books/{id}/pages/{n}.jpg`; the page count is cached in `books.page_count`. A new PDF upload clears both. Render errors return a generic `render_failed` (ids and exit code logged, never paths or stderr). Access = a non-revoked `book_access` row for the caller; a book later unpublished/archived stays readable by its owners. The public index/preview images are unchanged SP-4 uploads and never include reader pages. Production image needs `poppler-utils`.

Common error shape: `{"error":{"code":"...","message":"...","fields"?:{...}}}`. Other codes seen across
the API: `unauthorized` 401, `forbidden` 403 (wrong role), `not_found` 404, `validation_error` 400,
`rate_limited` 429, `invalid_transition` 400 (admin fulfilment, referral applications, commissions; 409 on refunds), `refund_required` 409 (admin cancel of an unshipped paid hard copy), `duplicate_application` / `duplicate_partner` / `refund_open` 409, `payload_too_large` 413, `render_failed` 500 (reader), `server_error` 500 (generic body; the class and message are written to the PHP error log).

### Razorpay webhook setup

1. In the Razorpay dashboard (Settings > Webhooks) add a webhook with URL
   `https://<your-domain>/api/webhooks/razorpay` and active events `payment.captured`,
   `payment.failed`, `refund.processed` and `refund.failed` (other events are accepted and ignored). `refund.processed`
   finalises our refund matched by refund id (or by payment id if the id was never stored, preferring the order's
   open refund) when the amount is the full refund; anything else is `ignored` and logged (ids only).
   `refund.failed` moves a `processing` refund to `failed` (approve again retries); nothing else changes.
2. Enter a webhook secret there and set the same value as `RAZORPAY_WEBHOOK_SECRET` in `backend/.env`.
3. Requests must carry `X-Razorpay-Signature` (HMAC-SHA256 of the raw body) and `X-Razorpay-Event-Id`
   (used for idempotency; a repeated id returns `duplicate`). A bad signature is 400 `invalid_signature`.
4. Local testing: expose `localhost:8080` with a tunnel (e.g. `ngrok http 8080`) and use the
   `https://<tunnel-host>/api/webhooks/razorpay` URL in a test-mode webhook.

### Creating the first admin

No admin-creation endpoint exists yet (that's a later sub-project). Seed
one via the CLI:

```bash
docker compose exec -T php php scripts/seed_admin.php <username> <password>
```

### Reconciling pending orders (cron)

`scripts/reconcile_orders.php` fulfils pending orders (older than 15 min) that
Razorpay reports captured, via the normal paid path; cancels pending orders
older than 24 h with no capture (payment `failed`, reason `expired`); re-checks
`failed`/`cancelled` orders from the last 7 days and fulfils any Razorpay reports
captured (e.g. a retry succeeded but the webhook was lost; nothing else is changed
on those orders); and purges `rate_limits` rows older than 2 days. It prints
`paid=N cancelled=N errors=N purged=N` and exits `0` on success, `2` if any order
errored (counts still printed; see the log for details), `1` on a fatal error.

An order flagged `admin_flag = amount_mismatch` (captured amount differs from the
order) stays `pending` and is re-polled every run until an admin resolves it.

Run it every 15 minutes, with `flock -n` so a slow run is never overlapped:

```cron
*/15 * * * * cd /path/to/vtee && flock -n /tmp/vtee-reconcile.lock docker compose exec -T php php scripts/reconcile_orders.php >> /var/log/vtee-reconcile.log 2>&1
# without Docker: */15 * * * * cd /path/to/vtee/backend && flock -n /tmp/vtee-reconcile.lock php scripts/reconcile_orders.php >> /var/log/vtee-reconcile.log 2>&1
```

## Test results

See `backend/TEST_RESULTS.md` for the actual captured output of the last
full test run:

- `backend/tests/jwt_test.php` + `backend/tests/http_test.sh` — auth/health foundation
- `backend/tests/upload_validator_test.php` + `backend/tests/book_test.sh` — book management (create → publish → cover/index/preview-pages/PDF uploads → public response shape/privacy → edit/unpublish/archive → role separation → invalid-file rejection)

- `backend/tests/{money_pricing,address_validator,signature_gateway,order_fulfillment,codes,reconciler,race,middleware,refund,reader,admin_authz,commission}_test.php` + `backend/tests/order_test.sh` — SP-5 orders, Razorpay, access codes, tracking, reconcile, refunds, secure reader, admin API, referral partners and commissions (run the PHP ones with `docker compose exec -T php php tests/<file>.php`)

Re-run any of them against a live `docker compose up` stack any time to re-verify.
