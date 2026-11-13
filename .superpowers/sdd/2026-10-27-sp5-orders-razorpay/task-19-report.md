# Task 19 report: Admin backend

Status: DONE. Commit: see `git log` (`feat(sp5): admin api for orders, customers, codes, fulfilment and dashboard`).

Files: `backend/src/Controllers/Admin{Dashboard,Order,Customer,Code}Controller.php`, `routes.php` (10 routes),
`migrations/006_audit_logs_entity_index.sql` (carried Task 1 minor; applied to dev DB), `tests/admin_authz_test.php` (new),
`tests/order_test.sh` §9 + cleanup (order audit rows, `admin-code-%` buckets), README, TEST_RESULTS.

TDD: admin_authz_test RED first (10 routes missing), then green. order_test §9 written before the controllers; first run 235/235.

Gates: admin_authz 63, order_test 235/235 (190 + 45), reader 33, refund 69, reconciler 25, codes 56, order_fulfillment 27,
money_pricing 14, signature_gateway 27, address_validator 14, middleware 5, jwt 6, upload_validator 3, race 1, book 25/25, http 23/23.
Books 1/2 `/api/books` byte-identical; this run's book_test/http_test leftovers (book 140, 2 users) deleted.

Rulings (my calls):
- Dashboard: purchases/revenue = `status='paid'` (net of refunds); `successfulPayments` = paid + refunded; `pendingOrders` = paid hard copies
  `confirmed`/`processing`; `pendingRefunds` = requested/approved/processing (not `failed`); commissionPending/Payable are ₹ sums.
- Fulfilment `invalid_transition` is 400 (plan), while refunds use 409 for the same code. Digital/unpaid orders also 400 `invalid_transition`.
- Customer `orderCount` counts all orders (any status); `paidTotal` sums `paid` only.
- Revoke returns the order detail; verify returns 404 for unknown/malformed codes; verify + reissue limits count every attempt.

Minors / open:
- Admin cancel does not trigger a refund (spec §4.3 said it would); admin must open a refund separately. Decide before Task 21 UI.
- No same-state update (e.g. fix a typo in courier/tracking number after `shipped`); add a `PATCH` or allow shipped->shipped if needed.
- Revoke reads the latest code id before taking the lock; a reissue racing in between leaves the new code active (admin sees it in the response).
- Reissue rate limit (30/10 min) not exercised by a test (same RateLimiter path as verify, which is).
- Customer detail returns newest 200 orders unpaged.

## Fix round 1 (review: changes required)

- I1: `AccessCodeService::revokeCurrent(orderId, adminId, reason)` locks the order, then the latest code (FOR UPDATE), revokes it and writes the audit row in one transaction (404 if the order has no code). The admin revoke endpoint uses it; `revokeForRefund` shares the same private `revokeLatest` (runs in the caller's transaction, same lock order). codes_test: a reissue landing between the admin's view and the revoke -> the NEW code is revoked, with an audit row; the second call is idempotent; no code -> 404.
- I2: cancelling a `confirmed`/`processing` paid hard copy -> 409 `refund_required` (points to POST /api/admin/orders/{id}/refund; refund completion cancels fulfilment). Cancelling from `shipped`/`out_for_delivery` needs `reason` (1-255), which is stored in the audit row.
- M1: admin revoke/revokeCurrent/reissue reject the reason `customer_request` (trimmed, any case) with 400 fields.reason, enforced in AccessCodeService. codes_test now sets the customer_request revoke through SQL.
- M3: control characters (\p{Cc}) are rejected in courier/trackingNumber and in admin reasons (shared `text()` helper).
- M6: `AdminOrderController::select()` throws a LogicException if the OrderFormatter::SELECT prefix is missing.
