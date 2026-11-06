# SP-5 flag cleanup plan (documented only, nothing built)

Owner decision: no admin "clear flag" action for now. This file records how flags are used and the safe manual resolution.

## Where flags live
- `orders.admin_flag` (first flag wins, never overwritten): `refund_external_unmatched` (a signed `refund.processed` webhook or the cron sweep found money refunded that the app cannot tie to an admin-opened full refund; also writes audit row `refund.external_unmatched`), `duplicate_payment`, `currency_mismatch`, `amount_mismatch`. Shown as a badge in Admin, Orders. Nothing clears it.
- `commissions.clawback_due` is a legacy column: refunds no longer reverse or claw back commission, so nothing sets or shows it. The `reversed` status and `reversed_at` are likewise unused. No migration; the 004 rollback stays correct. Optional later tidy-up: drop `clawbackDue` from API payloads.
- Finalising an admin refund writes audit row `refund.commission_unchanged` (order, commission id, status, amount): the list of commissions that belong to refunded orders.

## Risks
- A flagged order is never reviewed if nobody opens the Orders page (badge only, no counter or email).
- First-wins: a later flag is hidden behind an earlier one; audit rows keep every event.
- A dashboard refund leaves access active and the order `paid` until an admin acts.
- Clearing a flag with raw SQL and no audit row loses the review trail.

## Safe manual resolution for `refund_external_unmatched`
1. Admin, Orders: open the order, note the order number and Razorpay payment id.
2. Read the audit row: `SELECT created_at, detail FROM audit_logs WHERE action='refund.external_unmatched' AND entity_type='order' AND entity_id=<orderId>;`
3. In the Razorpay dashboard, confirm the refund id, amount, status and reason.
4. Decide case by case. A full refund that should count: revoke the code/access with the existing admin revoke action and note it (a second Razorpay refund would be rejected). A partial or mistaken refund: nothing to do in the app.
5. Only then clear the flag, after a backup, in one transaction with an audit row:
```sql
-- backup first: mysqldump --single-transaction <db> orders audit_logs > flag-backup.sql
START TRANSACTION;
UPDATE orders SET admin_flag = NULL WHERE id = <orderId> AND admin_flag = 'refund_external_unmatched';
INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, detail)
VALUES (<adminId>, 'order.flag_cleared', 'order', <orderId>, JSON_OBJECT('flag', 'refund_external_unmatched', 'note', '<what was checked>'));
COMMIT;
```

## Check before go-live on any real database
```sql
SELECT admin_flag, COUNT(*) FROM orders WHERE admin_flag IS NOT NULL GROUP BY admin_flag;
SELECT status, clawback_due, COUNT(*) FROM commissions WHERE clawback_due = 1 OR status = 'reversed' GROUP BY status, clawback_due;
```

## If it is ever built
`POST /api/admin/orders/:id/clear-flag {note}` doing exactly the transaction above, plus a "Flagged" filter and count on the orders page.

Dev test data cleanup is a separate step awaiting owner approval; a backup exists outside the repository.
