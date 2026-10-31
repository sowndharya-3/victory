# Backend Foundation — Test Results

Run date: 2026-10-15T06:06:44Z

```
PASS: decode returns correct sub claim
PASS: decode returns correct role claim
PASS: tampered token is rejected
PASS: expired token is rejected

All JWT tests passed.

=== 1. Health check ===
PASS: health returns 200
PASS: health reports ok status
=== 2. Customer registration ===
PASS: register returns 201
PASS: register returns a token
=== 2b. Duplicate registration ===
PASS: duplicate email returns 409
=== 3. Customer login ===
PASS: login returns 200
PASS: login returns a token
=== 4. GET /api/me with customer token ===
PASS: me returns 200
PASS: me returns correct email
=== 5. Admin login ===
PASS: admin login returns 200
PASS: admin login returns a token
=== 6. Protected route with admin token ===
PASS: admin me returns 200
PASS: admin me reports admin role
=== 7. Invalid / expired JWT handling ===
PASS: no token returns 401
PASS: garbage token returns 401
PASS: expired token returns 401
PASS: expired token error code is token_expired
=== 8. Invalid credentials ===
PASS: wrong password returns 401
=== 9. CORS preflight ===
PASS: preflight echoes exact configured origin
PASS: preflight does not use wildcard origin
=== 10. Database connectivity ===
PASS: health check queried a live database (already proven in step 1)

Results: 21 passed, 0 failed
```

## Sub-project 4: Book Management

Run date: 2026-10-22T13:22:44Z

```
PASS: real JPEG sniffs as image/jpeg
PASS: real PDF sniffs as application/pdf
PASS: PDF content renamed to .jpg still sniffs as application/pdf, not trusted by extension

All upload validator tests passed.

=== Setup: admin token, test files ===
PASS: admin token obtained
PASS: customer token obtained
=== 1. Create a book ===
PASS: create returns 201
PASS: create returns an id
=== 2. New book is not public yet ===
PASS: unpublished book absent from public list
=== 3. Publish, then it's public ===
PASS: published book appears in public list
=== 4. Cover, index, preview-page, PDF uploads ===
PASS: cover upload returns a coverUrl
PASS: index upload returns an indexUrl
PASS: second preview page upload succeeds
PASS: PDF upload returns pdfUploaded true
=== 5. Public detail shows assets, never the private PDF path ===
PASS: public detail includes coverUrl
PASS: public detail includes indexUrl
PASS: public detail includes 2 previewPages
PASS: public detail never mentions digital_file_path or storage path
PASS: public list never mentions digital_file_path
=== 6. Private PDF genuinely unreachable ===
PASS: direct PDF URL guess returns 404 (storage/ not in docroot)
=== 7. Edit propagates ===
PASS: edited title appears in public list
=== 8. Unpublish removes from public list ===
PASS: unpublished book absent from public list
=== 9. Archive hides even if republished ===
PASS: archived book absent from public list even though published=1 was set first
=== 10. Role separation on admin endpoints ===
PASS: create with no token returns 401
PASS: create with customer-role token returns 403
PASS: cover upload with customer-role token returns 403
=== 11. Invalid file rejected ===
PASS: non-image file upload to cover endpoint returns 400

Results: 23 passed, 0 failed
```

## Sub-project 5: Orders, Razorpay, Access Codes (backend)

Run date: 2026-10-30 (re-run after the Task 16 review fixes: late refund webhooks, refund.failed). Gateway mode `fake` (dev), docker compose stack. Zero FAIL lines in every suite.

| Suite | Command | Result |
|---|---|---|
| money_pricing_test.php | `docker compose exec -T php php -d display_errors=1 tests/money_pricing_test.php` | 14 passed, 0 failed |
| address_validator_test.php | same, `tests/address_validator_test.php` | 14 passed, 0 failed |
| signature_gateway_test.php | same | 27 passed, 0 failed (incl. empty webhook/checkout secret fails closed) |
| order_fulfillment_test.php | same | 27 passed, 0 failed |
| codes_test.php | same | 56 passed, 0 failed |
| reconciler_test.php | same | 25 passed, 0 failed |
| race_test.php | same | 1 passed, 0 failed |
| middleware_test.php | same | 5 passed, 0 failed (incl. ErrorHandler logs 500s, not ApiException) |
| jwt_test.php | same | 6 passed, 0 failed |
| upload_validator_test.php | same | 3 passed, 0 failed |
| refund_test.php | same | 69 passed, 0 failed (Task 16; incl. two racing requests -> one row, two racing approves -> one gateway call, late webhook on a failed refund with a newer open one; run 3 times green) |
| order_test.sh | `bash backend/tests/order_test.sh` | 177 passed, 0 failed (incl. unknown-order capture is logged; §7 refunds over HTTP, refund.failed) |
| book_test.sh | `bash backend/tests/book_test.sh` | 25 passed, 0 failed |
| http_test.sh | `bash backend/tests/http_test.sh` | 23 passed, 0 failed |

Additional checks:

- SP-4 payload unchanged: `GET /api/books` items have exactly `id,title,description,category,coverUrl,onlinePrice,hardcopyPrice`;
  `GET /api/books/:id` has those plus `indexUrl,previewPages`. The private PDF path returns 404 via nginx (`book_test.sh` section 6).
- Lazy validation: with every `RAZORPAY_*` and `SMTP_*` line removed from `backend/.env` and the php container recreated,
  `book_test.sh` passes 25/25 and `/api/health` and `/api/checkout/quote` still return 200. `.env` was then restored byte-identical
  and `order_test.sh` passed again (132/132).
- Test data: `order_test.sh` cleans up after itself. The SP-4 `book_test.sh`/`http_test.sh` leave an archived book and test users per run
  by design; the ones created during this run were deleted.

## Task 17: Secure reader backend

Run date: 2026-10-30, after migration 005 and the php image rebuild with `poppler-utils` 20.09 (`pdftoppm -v`, `pdfinfo -v` OK in the container).

| Suite | Result |
|---|---|
| reader_test.php (new) | 33 passed, 0 failed (id parsing, pageCount vs pdfinfo + cache, JPEG magic, cache reuse with mtime unchanged, range 404s, 3 concurrent renders, invalidate, missing/corrupt PDF -> `render_failed` without paths/stderr) |
| order_test.sh | 190 passed, 0 failed (177 + §8: real book 2 redeemed -> page JPEG + headers, 401/403/404, direct private paths 404, 241st call 429 per user, refund -> `revoked` + 403) |
| refund 69, reconciler 25, codes 56, order_fulfillment 27, money_pricing 14, signature_gateway 27, address_validator 14, middleware 5, jwt 6, upload_validator 3, race 1 | all passed, 0 failed |
| book_test.sh / http_test.sh | 25/25, 23/23 |

Books 1 and 2: `/api/books`, `/api/books/1`, `/api/books/2` responses and both PDFs md5-identical before/after. Test rows removed (book_test/http_test leftovers of this run too); book 2's page cache (`storage/books/2/pages`, gitignored) left in place.

## Task 19: Admin backend (dashboard, orders, customers, codes, fulfilment, audit)

Run date: 2026-10-30, after migration 006 (audit_logs `(entity_type, entity_id)` index).

| Suite | Result |
|---|---|
| admin_authz_test.php (new) | 63 passed, 0 failed (10 Task 19 routes registered; all 26 admin routes from routes.php: 401 without token, 403 with a customer token) |
| order_test.sh | 235 passed, 0 failed (190 + §9: dashboard equals SQL and seeded deltas, search by order number / email / name / phone / tracking code / full code / last4, literal `%` `_`, filters, paging, detail, fulfilment chain + illegal moves + courier rule + digital/unpaid 400 + customer and tracking page see it at once, verify/revoke/reissue, old code `code_revoked`, new code redeems, audit rows, no plaintext/hash/code_enc in admin JSON, verify limit 31st 429) |
| reader 33, refund 69, reconciler 25, codes 56, order_fulfillment 27, money_pricing 14, signature_gateway 27, address_validator 14, middleware 5, jwt 6, upload_validator 3, race 1 | all passed, 0 failed |
| book_test.sh / http_test.sh | 25/25, 23/23 |

Books 1 and 2: `/api/books` byte-identical before/after. Test rows removed (order_test cleans itself; this run's book_test/http_test book and users deleted).

## Task 20: Referral partners and commissions backend

Run date: 2026-10-30, after migration 007 (`commissions.payable_at`).

| Suite | Result |
|---|---|
| commission_test.php (new) | 41 passed, 0 failed (VTP- codes format/distinct, rate validation incl. sign/exponent/3 decimals/padding/bounds 0 and 10000, audit from/to, snapshot rule 25.00 -> setRates('30','25') -> old row 25.00, new digital 30.00 / hard copy 25.00, pending->paid and reversed->payable/paid `invalid_transition`, reference required/charset/length, payable_at/paid_at, inactive partner history unchanged) |
| admin_authz_test.php | 99 passed, 0 failed (22 routes registered incl. 12 new; all 38 admin routes 401 without token / 403 customer) |
| order_test.sh | 269 passed, 0 failed (235 + §10: application validation/normalisation, duplicate 409, 5/hour per IP and X-Forwarded-For ignored, approve/reject/transition guards, approved code validates and is attributed on a paid order, toggle makes the code unknown, partner edits, commission list + totals, payable/paid over HTTP, admin refund reverses -> payable 400, pricing settings change the quote, every action audited) |
| reader 33, refund 69, reconciler 25, codes 56, order_fulfillment 27, money_pricing 14, signature_gateway 27, address_validator 14, middleware 5, jwt 6, upload_validator 3, race 1 | all passed, 0 failed |
| book_test.sh / http_test.sh | 25/25, 23/23 |

Books 1 and 2: `/api/books` md5-identical before/after. Settings back to their prior values; test applications, partners, commissions, users, rate_limits and audit rows removed (this run's book_test/http_test book 152, its upload dirs and 2 users deleted).

## Task 19 fix round 1 (revoke under lock, refund required to cancel unshipped hard copies)

Run date: 2026-10-30, on top of Task 20.

| Suite | Result |
|---|---|
| codes_test.php | 60 passed, 0 failed (+4: reserved `customer_request` admin reason -> 400; revokeCurrent after a racing reissue revokes the new code with an audit row; idempotent; no code -> 404) |
| order_test.sh | 271 passed, 0 failed (§9: cancel from confirmed/processing -> 409 `refund_required`, cancel after shipping needs a reason kept in audit, control chars in courier/tracking number -> 400) |
| admin_authz 99, commission 41, reader 33, refund 69, reconciler 25, order_fulfillment 27, money_pricing 14, signature_gateway 27, address_validator 14, middleware 5, jwt 6, upload_validator 3, race 1 | all passed, 0 failed |
| book_test.sh / http_test.sh | 25/25, 23/23 |

Books 1 and 2: `/api/books` byte-identical before/after; this run's book_test/http_test book and users deleted.

### Task 20 fix round 1 (refund hold, control characters, duplicate partner, body cap)

Run date: 2026-10-31. One full gate run after the fix:

| Suite | Result |
|---|---|
| commission_test.php | 48 passed, 0 failed (+7: requested / approved / processing refund blocks payable and paid with 409 `refund_open`, works again after rejected / failed, refunded order blocks, `refundOpen` flag) |
| order_test.sh | 277 passed, 0 failed (§10 adds: NUL / LF / ANSI / DEL and CRLF rejected, NBSP-only name Required, invalid UTF-8 400, 17 KB body 413, Unicode-trimmed name stored, partner edit control chars 400, duplicate gmail on edit and approve 409 `duplicate_partner`, open customer refund request blocks payable and paid over HTTP, `refundOpen` in the list) |
| admin_authz 99, codes 60, reader 33, refund 69, reconciler 25, order_fulfillment 27, money_pricing 14, signature_gateway 27, address_validator 14, middleware 5, jwt 6, upload_validator 3, race 1 | all passed, 0 failed |
| book_test.sh / http_test.sh | 25/25, 23/23 |

Books 1 and 2: `/api/books` md5-identical before/after. Settings unchanged (25/25/inclusive/0/0/0/0); no partner, application, commission or ordertest rows left; this run's book_test/http_test book 186, its upload dirs and 2 users deleted.

## Final verification (plan Task 15): full pass, real-browser E2E, responsive

Run date: 2026-10-31, branch `sp5-orders-codes` from 8748b0b, docker compose stack, `RAZORPAY_GATEWAY=fake`, `APP_ENV=development`.

### Step 1: automated pass (zero failures)

| Suite | Result |
|---|---|
| order_test.sh | 277 passed, 0 failed |
| admin_authz 99, refund 69, codes 60, commission 48, reader 33, order_fulfillment 27, signature_gateway 27, reconciler 25, money_pricing 14, address_validator 14, jwt 6, middleware 5, upload_validator 3, race 1 | all passed, 0 failed |
| book_test.sh / http_test.sh | 25/25, 23/23 |
| `npm test` | 141 passed before the fixes below; 144 passed after them (29 files) |
| `npm run build` | ok |

Leftovers of this run removed (book_test book 195 and its upload/storage dirs, 2 users); earlier archived SP-4 leftovers kept.
`/api/books` md5 identical before and after the whole session; settings unchanged.

### Step 2: browser E2E (Playwright MCP, Chromium, real browser)

Vite dev server on `http://localhost:5173` (`VITE_API_URL=http://localhost:8080`, matches `FRONTEND_ORIGIN`), both real books.
Razorpay's `checkout.js` was replaced by a stub through request interception; the fake payment signature was computed inside
the php container (the secret never left it) and passed to the page's own `handler`, so the real `/verify` path ran.
A throwaway admin was created with `seed_admin.php` and deleted afterwards.

| Flow | Result |
|---|---|
| (a) admin login (wrong password shows "Invalid username or password"), both books listed, description edited and restored | pass (description editing added, see fixes); `/api/books` byte-identical after restore |
| (b) register, buy digital English book (₹99), code shown, invalid code -> "Invalid code", redeem -> Access: Active, reader pages 1-3 via Next/Next/Prev, swipe left/right (touch events), ArrowRight | pass; real PDF pages rendered (176 pages); watermark coverage fixed (see below) |
| (c) hard-copy Tamil book: pincode `12345` -> "Must be a valid 6-digit pincode" next to the field; valid address -> paid, tracking code `VTT-…`, `/track` shows Order Confirmed | pass |
| (d) admin Processing -> Shipped (India Post + tracking number) -> `/track` and Account show Shipped with courier/number | pass; from processing only "shipped" is offered (cancel needs a refund) |
| (e) code redeemed by customer 1 tried by customer 2 -> "This code has already been redeemed"; admin reissue -> old code -> "This code is no longer valid", new code redeems; failed payment -> checkout message + button re-enabled; signed `payment.failed` webhook -> order failed (`card declined`), same event id again -> `duplicate`, bad signature -> 400; result page "Payment failed … Try again" | pass |
| (f) referral application (browser) -> admin approve (`VTP-…`) -> customer 2 buys digital Tamil book via `?ref=` -> commission ₹25 pending; refund request (API, no customer UI) -> admin Approve + Confirm in browser -> refund `refunded`, commission `reversed`, `/api/books/1/access` `{owned:false,status:"revoked"}`, page API 403, reader shows "access was revoked", My Books "Access: Revoked" | pass |
| (g) customer 2 opening `/read/2` (customer 1's book) -> "You don't have access to this book"; `GET /api/books/2/read/pages/1` 403 | pass |
| Form errors visible, no empty red boxes (checkout pincode, redeem, admin wrong password; every `.form-error` with empty text is `display:none`) | pass |

Defects found in the browser and fixed (each its own commit, tests added): cover/index images loaded from the frontend origin
(broken on any split deploy), admin could not edit descriptions, watermark covered only a few strips of the page (M6), result page
promised a shipping email, no customer log out, responsive issues below.

Watermark coverage (honest): before the fix 24 tiles in a box twice the page size left most of the page unmarked
(`reader-page1-1280.jpg`, taken before the fix). After it (200 tiles) diagonal rows run about 150 px apart over the whole page
(`reader-watermark-full-page.jpg`, `reader-390.jpg`); it is a deterrent, not DRM, and can be removed with dev tools.

### Step 3: responsive (1280 / 820 / 390 px)

Books, Book detail, Checkout (hard copy), My Books, Reader, Admin Orders (with detail open): horizontal page overflow
`scrollWidth - clientWidth = 0` on all 18 after the fixes (before: header nav overflowed by 370 px at 820; book detail
cover by 270/700 px at 820/390; pages had no side gutter). The admin orders table scrolls inside its own container.

### Step 4: real Razorpay test mode

Not run: keys required (U6). The Razorpay Checkout popup itself was therefore not exercised.

### Screenshots (`docs/deployment/screenshots/`, JPEG, 2.5 MB total)

- Responsive: `{books,book-detail,checkout,my-books,reader,admin-orders}-{1280,820,390}.jpg`
- Admin: `admin-wrong-password.jpg`, `admin-edit-description.jpg`, `admin-order-shipped.jpg`, `admin-reissue-code.jpg`, `admin-refund-approved.jpg`
- Customer: `order-result-digital.jpg`, `order-result-hardcopy.jpg`, `order-result-failed.jpg`, `checkout-invalid-pincode.jpg`, `checkout-payment-failed.jpg`,
  `redeem-invalid-code.jpg`, `redeem-code-used-by-other.jpg`, `redeem-revoked-code.jpg`, `track-confirmed.jpg`, `track-shipped.jpg`, `referral-application-submitted.jpg`
- Reader: `reader-page1-1280.jpg` (before watermark fix), `reader-page3-1280.jpg`, `reader-watermark-full-page.jpg`, `reader-tamil-book-page1.jpg`,
  `reader-access-revoked.jpg`, `reader-denied-second-customer.jpg`

Codes, emails and order numbers in the screenshots belong to test rows that were deleted after the run.

### Cleanup

Removed: customers `e2e-c1/e2e-c2@example.com`, their 4 orders with payments, address, access codes, book access, refund,
commission, referral mapping, the test referral application and partner, their audit rows, webhook events, rate-limit rows,
and the throwaway admin (only `admin` remains). Books 1 and 2 unchanged (`/api/books` md5 identical). Dev server and browser stopped.

> Note: the seven reader screenshots that show pages of the owner's real books (`reader-1280/820/390.jpg`, `reader-page1-1280.jpg`, `reader-page3-1280.jpg`, `reader-tamil-book-page1.jpg`, `reader-watermark-full-page.jpg`) are kept locally in `docs/deployment/screenshots-private/` and are deliberately not committed, to avoid publishing paid book content.
