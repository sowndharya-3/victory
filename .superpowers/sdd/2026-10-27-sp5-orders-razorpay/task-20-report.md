# Task 20 report: Referral partners and commissions backend

Status: DONE. Base 0fef968.

- New: `ReferralApplicationController` (public POST /api/referral/applications, 5 valid submissions/hour per REMOTE_ADDR, 409 `duplicate_application` on pending/approved gmail), `AdminReferralController` (list, approve/reject from pending under FOR UPDATE, toggle-active, partial PUT with unknown-key rejection), `AdminCommissionController` (list + totals, payable/paid, commission + pricing settings), `Referral/CommissionService` (ratesRupees, setRates, markPayable, markPaid, audit), migration 007 `commissions.payable_at` (applied to dev DB).
- Reuse: `CodeGenerator::partner()` (VTP- + 6 Crockford, DB-unique, 5 retries on 1062), `AddressValidator::mobile()` (extracted, `\z`), `Settings::set()`, `Money::parseAmount()` (string/int/float -> "N.NN", no float arithmetic), AdminOrderController paging/id/body/text helpers.
- Decisions: `invalid_transition` = 400 (Task 19 admin convention; refunds stay 409). Money out as "25.00" strings in the new endpoints (Task 19 order detail still emits floats). Totals honour partnerId, not status. Rate limit counted after validation (typos free). gmail accepts any valid email (lowercased). upiId = VPA or 10-digit mobile. Inactive partners' earned commissions can still be paid out. Partners list unpaginated (ponytail).
- Tests: commission 41, admin_authz 99, order_test 269 (235 + 34), all other gates unchanged and green; book_test 25, http_test 23.
- Minors: application dedupe is check-then-insert (no unique key; race can create two rows); approve does not check for an existing partner with the same gmail; validate endpoint does not fold O/I/L in codes; partners list unpaginated; Task 21 must send rates/pricing as strings or plain numbers.

## Fix round 1 (review: changes required)

- I1: `CommissionService::transition` refuses payable/paid with 409 `refund_open` while the order has a requested/approved/processing refund or is no longer `paid` (plain non-locking read after the commission lock; we take no refund/order lock, so no inversion with RefundService). Commission rows carry `refundOpen`. Residual: a refund opened after that read still ends as `clawback_due`.
- I2: shared `AdminOrderController::clean()` (Unicode trim incl. NBSP, rejects `\p{Cc}` and invalid UTF-8), used by `text()` and by application + partner-edit validation.
- Minors: approve / partner gmail edit -> 409 `duplicate_partner`; public application body capped at 16 KB -> 413 `payload_too_large` (read with `stream_get_contents`); README notes the rate counter runs before the duplicate check (409 uses budget, reveals that an email applied) and that attribution is snapshotted (deactivated partners still earn on earlier orders).
- Test note: on this Windows host, non-ASCII bytes in curl arguments get mangled, so the test script sends ` ` JSON escapes, never raw NBSP characters.
