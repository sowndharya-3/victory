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
