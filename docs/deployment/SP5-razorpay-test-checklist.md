# SP-5 Razorpay test-mode checklist

Purpose: prove, with Razorpay's **test mode** only, that real checkout, server-side payment verification, signature validation, webhooks (including duplicates), refunds and access revocation work end to end. Until every required item below passes and the owner signs off, **live payments stay disabled**.

**Status: NOT RUN.** No Razorpay credentials exist in the project (only development placeholders; `rzp_test_` keys are still to be placed in the staging `.env`), so none of the rows below has been executed against real Razorpay, in test or live mode. All earlier tests used the fake gateway (`RAZORPAY_GATEWAY=fake`, development `APP_ENV` only), and Razorpay's checkout popup was replaced by a stub. The results table at the end records this row by row.

**Refund policy context.** There is no customer refund-request workflow and no general refund for purchased books (owner decision). Refunds in this checklist are admin **exception refunds** ("Refund this order"): duplicate payment, debited-but-failed, legal cases.

## 0. Ground rules (read first)

- **Test mode only.** Use keys that start with `rzp_test_`. Never put `rzp_live_` keys in any environment used for this checklist. If a live key is ever pasted into a test environment, regenerate it in the Razorpay dashboard immediately.
- Use a **separate staging environment** (a copy of the app with its own database), not the production database and not real customers' data.
- Use only Razorpay's **test cards, test UPI IDs and test bank**. Do not use a real card, real UPI ID or real bank account. Take the exact test values from Razorpay's current documentation ("Test Cards", "Test UPI", "Test Netbanking"), because they change over time.
- Keep secrets out of screenshots, chat and git. Only the person who owns the Razorpay account types the keys into the server's `.env` file. Evidence screenshots must not show keys, webhook secrets or full access codes.
- The fake gateway must stay refused outside development: with `APP_ENV` set to anything other than `development|dev|local|test|testing`, `RAZORPAY_GATEWAY=fake` makes the app refuse to start the gateway. That is intended.

## 1. Preconditions

- [ ] Staging server reachable over **HTTPS** (Razorpay only delivers webhooks to public HTTPS URLs; for a quick check an HTTPS tunnel to a local machine also works, but the secret and URL must be test-mode ones).
- [ ] Server `.env` (see [SP5-environments.md](SP5-environments.md)): `APP_ENV=staging` (or `production`), `RAZORPAY_GATEWAY=live` with **test** keys: `RAZORPAY_KEY_ID=rzp_test_...`, `RAZORPAY_KEY_SECRET` (at least 20 characters), `RAZORPAY_WEBHOOK_SECRET` (at least 32 random characters), plus strong `JWT_SECRET` and `CODE_SECRET` (at least 32 characters). `FRONTEND_ORIGIN` equals the staging site's exact origin.
- [ ] `php scripts/check_config.php` prints PASS for every item and exits 0.
- [ ] Migrations 001-008 applied; the pre-migration checks returned nothing. Contact-form SMTP variables are optional for this checklist.
- [ ] Razorpay dashboard in **Test Mode**, auto-capture ON, a **test-mode** webhook configured: URL `https://<staging-api>/api/webhooks/razorpay`, secret equal to `RAZORPAY_WEBHOOK_SECRET`, active events `payment.captured`, `payment.failed`, `refund.processed`, `refund.failed`. (Test-mode and live-mode webhooks are configured separately.)
- [ ] The two real books exist in staging, with prices set. One staging admin and two staging customer accounts (A and B) exist, with throwaway emails.
- [ ] Cron reconciler scheduled (or ready to run by hand): `php scripts/reconcile_orders.php`.

## 2. Checkout and payment

| # | Step | Expected result | Pass |
|---|---|---|---|
| 2.1 | As customer A, open the digital book, press Buy. | The price breakdown appears. The server creates a real Razorpay order (`order_...`). **Razorpay's real Checkout popup opens** with the right business name and branding (not a stub). | [ ] |
| 2.2 | Pay with a **test card** (success). | Popup closes; result page shows "Payment received"; the order is `paid`; a digital access code is shown once. | [ ] |
| 2.3 | Repeat with **test UPI** (success) and **test netbanking** (success). | Same as 2.2 for each method. | [ ] |
| 2.4 | Buy the hard-copy book with a valid address. | Order `paid`; a tracking code is shown; `/track` shows "Order Confirmed". | [ ] |
| 2.5 | Pay with a **failing** test method (test card/UPI that Razorpay documents as failing). | The popup shows the failure; the order becomes `failed`; the result page says payment failed; no access code. | [ ] |
| 2.6 | After 2.5, retry in the same popup with a success method. | Order becomes `paid` and the code is issued once. | [ ] |
| 2.7 | Close the popup/tab **before** the payment finishes (or right after paying, before the success handler runs). | No order is paid without a captured payment. If the payment was captured, the **`payment.captured` webhook alone** marks the order `paid` within seconds, and the code appears under My Books / order details. | [ ] |

## 3. Server-side payment verification (the app must not trust the browser)

| # | Step | Expected result | Pass |
|---|---|---|---|
| 3.1 | In the browser developer tools, edit `razorpay_signature` in the verify request before it is sent (or replay the request with a changed signature). | Server answers 400 `payment_verification_failed`. The order is **not** marked paid by that request. | [ ] |
| 3.2 | Send a valid payment's id and signature but for a **different** order id. | Rejected. One payment can never pay two orders. | [ ] |
| 3.3 | Send the same valid verify request twice. | Second call is harmless: the order stays `paid`, exactly one access code exists. | [ ] |
| 3.4 | In the dashboard check that the amount captured equals the order total in the app. | Amounts match to the paisa. (The server compares the amount it expects, not the amount the browser sends.) | [ ] |
| 3.5 | With auto-capture **off** (optional test): pay, do not capture. | `/verify` returns 202 and the order stays pending; the reconciler leaves it pending until captured or auto-refunded. | [ ] |

## 4. Webhook signature and duplicate handling

| # | Step | Expected result | Pass |
|---|---|---|---|
| 4.1 | Dashboard, Webhooks, delivery log: look at deliveries for `payment.captured` and `payment.failed`. | HTTP 2xx for each. Every delivery carries the `X-Razorpay-Event-Id` header (the app returns 400 without it). | [ ] |
| 4.2 | In the dashboard use **Redeliver** on a delivered `payment.captured`. | Response body `{"status":"duplicate"}`; no second access code; order unchanged. | [ ] |
| 4.3 | Temporarily set a wrong webhook secret in the dashboard and trigger an event (or send a hand-made request with a wrong signature). | App returns 400; Razorpay shows a failed delivery; nothing changes in the app. Restore the correct secret afterwards. | [ ] |
| 4.4 | Send an event type that the app does not handle. | 2xx response and no state change (it is ignored, not an error). | [ ] |
| 4.5 | Block the webhook (point the dashboard at a wrong URL), pay, wait 15 minutes, run `php scripts/reconcile_orders.php`. | The reconciler finds the captured payment and marks the order `paid`. Exit code 0. Restore the URL afterwards. | [ ] |

## 5. Refunds

| # | Step | Expected result | Pass |
|---|---|---|---|
| 5.1 | As admin, open a paid **digital** order that customer A has paid and press "Refund this order" (confirm the "Confirm refund" step), entering an exception reason such as "duplicate payment". | Razorpay creates a refund for the **full** amount. The app shows the refund as `refunded`, or as `processing` if Razorpay answers "pending" at normal speed. Note which one test mode returns. | [ ] |
| 5.2 | If the refund is `processing`, wait for the **`refund.processed` webhook**. | The refund becomes `refunded` by itself. Redelivering the event returns `duplicate`. | [ ] |
| 5.3 | Admin "Refund this order" directly on a paid hard-copy order that is still `confirmed`. | Full refund; fulfilment becomes `cancelled`; order `refunded`. | [ ] |
| 5.4 | Try to start a second refund on an already refunded or already open order. | Refused or returns the existing open refund; never two refunds for one payment. | [ ] |
| 5.5 | If test mode allows it, simulate a **failed refund** (`refund.failed`). | The refund becomes `failed`, the order stays `paid`, access stays; the admin can retry. | [ ] |
| 5.6 | In the dashboard compare the refund amount to the original payment. | Full amount refunded. Note whether the Razorpay fee is returned (it normally is **not**); this feeds the partial-refund decision. | [ ] |

## 6. Access revocation after a refund

Run after 5.1 or 5.2 on the digital order that customer A had **redeemed** and read.

| # | Step | Expected result | Pass |
|---|---|---|---|
| 6.1 | Customer A reloads the reader page. | "Access revoked"; page images are no longer served (the server answers 403 on the next page request). | [ ] |
| 6.2 | Customer A opens My Books. | The book is gone or marked revoked. | [ ] |
| 6.3 | Redeem the same code again (as A or as B). | Refused: the code is revoked (`code_revoked`). | [ ] |
| 6.4 | Admin verifies the code in the code panel. | Status shows `revoked`. | [ ] |
| 6.5 | If the order carried a referral: open the commission list. | The commission is unchanged (same status as before; no `reversed`, no `clawback_due`). The audit log has a `refund.commission_unchanged` row for the order. | [ ] |
| 6.6 | Customer B (who never bought the book) tries to open customer A's reader page URL. | 403; no page image is served. | [ ] |

## 7. Refund reconciliation (implemented, never tried against real Razorpay)

Run `php scripts/reconcile_orders.php` after each step (it also runs from cron every 15 minutes).

| # | Step | Expected | Notes |
|---|---|---|---|
| 7.1 | Refund a paid app order in full **from the Razorpay dashboard**. | The reconciler records it: refund finalised, order `refunded`, access revoked, commission unchanged (audit row `refund.commission_unchanged`). | Gap F-1 in the premerge review, now implemented. |
| 7.2 | Make a **partial** refund from the dashboard. | Never finalised. The order is flagged for an admin (`admin_flag`); order stays `paid`. | |
| 7.3 | Lose a `refund.processed` webhook (wrong URL while refunding in the app). | The reconciler finalises the `processing` refund. | |

## 8. Settlement, fees and dashboard configuration

- [ ] The dashboard shows fees and GST-on-fees deducted from the test settlement. Note whether fees are returned on refund.
- [ ] Only the four events are active on the webhook; test and live webhooks are separate.
- [ ] Auto-capture is ON in the account settings used for live.

## 9. Stop conditions

Stop and tell the owner before continuing if any of these happen: a payment marks an order paid without a valid signature or captured payment; two access codes for one payment; a refund larger than the payment; a customer reaching another customer's book; a secret value appearing in a response, log or screenshot; the app starting the fake gateway outside development.

## 10. Evidence to keep (never include secrets)

For each section, keep screenshots or short notes of: the dashboard payment and refund ids, the webhook delivery log (status codes only), the app's order/refund status, and the reader result. Store them outside the git repository unless they contain no personal or paid content.

## 11. Results (real Razorpay test mode)

Date of last update: 2026-11-06. **Every row is "Not run - awaiting rzp_test_ keys in staging .env".** Fill in the date and result when the owner supplies test keys and a staging environment exists.

| Section | Result |
|---|---|
| 1 Preconditions (staging, `.env`, `check_config.php`, webhook) | Not run - awaiting rzp_test_ keys in staging .env |
| 2.1 Real Checkout popup opens with real order id | Not run - awaiting rzp_test_ keys in staging .env |
| 2.2 Test card success | Not run - awaiting rzp_test_ keys in staging .env |
| 2.3 Test UPI and netbanking success | Not run - awaiting rzp_test_ keys in staging .env |
| 2.4 Hard copy purchase and tracking code | Not run - awaiting rzp_test_ keys in staging .env |
| 2.5 Failing payment method | Not run - awaiting rzp_test_ keys in staging .env |
| 2.6 Retry after failure | Not run - awaiting rzp_test_ keys in staging .env |
| 2.7 Tab closed, webhook alone marks paid | Not run - awaiting rzp_test_ keys in staging .env |
| 3.1 Tampered signature | Not run - awaiting rzp_test_ keys in staging .env |
| 3.2 Payment replayed for another order | Not run - awaiting rzp_test_ keys in staging .env |
| 3.3 Verify replayed twice | Not run - awaiting rzp_test_ keys in staging .env |
| 3.4 Captured amount equals order total | Not run - awaiting rzp_test_ keys in staging .env |
| 3.5 Auto-capture off (optional) | Not run - awaiting rzp_test_ keys in staging .env |
| 4.1 Webhook deliveries 2xx with event id header | Not run - awaiting rzp_test_ keys in staging .env |
| 4.2 Redelivery returns duplicate | Not run - awaiting rzp_test_ keys in staging .env |
| 4.3 Wrong webhook secret rejected | Not run - awaiting rzp_test_ keys in staging .env |
| 4.4 Unhandled event type ignored | Not run - awaiting rzp_test_ keys in staging .env |
| 4.5 Reconciler recovers a lost payment webhook | Not run - awaiting rzp_test_ keys in staging .env |
| 5.1 Admin exception refund, digital | Not run - awaiting rzp_test_ keys in staging .env |
| 5.2 `refund.processed` webhook finalises | Not run - awaiting rzp_test_ keys in staging .env |
| 5.3 Admin exception refund, hard copy | Not run - awaiting rzp_test_ keys in staging .env |
| 5.4 No second refund on one payment | Not run - awaiting rzp_test_ keys in staging .env |
| 5.5 `refund.failed` | Not run - awaiting rzp_test_ keys in staging .env |
| 5.6 Refund amount and fees in dashboard | Not run - awaiting rzp_test_ keys in staging .env |
| 6.1-6.6 Access revocation after refund | Not run - awaiting rzp_test_ keys in staging .env |
| 7.1-7.3 Refund reconciliation | Not run - awaiting rzp_test_ keys in staging .env |
| 8 Settlement, fees, dashboard configuration | Not run - awaiting rzp_test_ keys in staging .env |

### What was verified on the fake gateway instead (not a substitute)

The automated suites on the Docker stack (`APP_ENV=development`, `RAZORPAY_GATEWAY=fake`, see `backend/TEST_RESULTS.md`) cover the application logic behind the rows above: signature check and tamper rejection, one payment cannot pay two orders, idempotent verify, webhook signature, duplicate event handling, event id requirement, unknown event types, the order reconciler, exception refunds (full amount, one open refund per order, `refund.processed` and `refund.failed` handling, late webhooks), refund reconciliation against a scripted gateway, access revocation and commission left unchanged, and the rule that the fake gateway is refused outside development. What the fake gateway cannot show: Razorpay's real Checkout popup, real signatures and payloads, real webhook delivery and retries, real refund timing (`processed` vs `pending`), real fees and settlement.

## 12. Sign-off

| Item | Done by | Date | Result |
|---|---|---|---|
| Sections 1-6 all pass | | | Not run |
| Section 7 reconciliation behaves as described | | | Not run |
| Section 8 reviewed | | | Not run |
| Owner approves moving to live | | | Not given |

## 13. Switching to live (only after sign-off, and only with explicit owner approval)

1. New live keys and a **new** live webhook secret; update the server `.env`; run `php scripts/check_config.php`.
2. Create the live-mode webhook in the dashboard with the four events.
3. Make one small real payment and one real exception refund using the owner's own card or UPI, and check the full flow (sections 2, 5 and 6) once.
4. Keep the reconciler cron running and alerting on failure.
