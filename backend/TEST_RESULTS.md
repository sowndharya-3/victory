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

Run date: 2026-10-29. Gateway mode `fake` (dev), docker compose stack. Zero FAIL lines in every suite.

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
| order_test.sh | `bash backend/tests/order_test.sh` | 132 passed, 0 failed (incl. unknown-order capture is logged) |
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
