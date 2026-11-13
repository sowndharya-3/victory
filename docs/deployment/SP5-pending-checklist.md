# SP-5 pending requirements checklist

Last updated: 12 November 2026. Branch `sp5-finalize`. No merge, deployment, data deletion or Razorpay Live activation happens without the owner's explicit approval. Credentials are never stored in Git or source code.

## Confirmed owner decisions (no further work planned)

| Topic | Decision |
|---|---|
| Customer GST invoice | Not required. No customer GST invoice is generated or sent. Razorpay's own fee invoices are separate from book purchases |
| GST configuration | Placeholders stay unchanged (GST 0 percent, inclusive) until the rate and price structure are confirmed |
| Order emails | Customer order confirmation, payment success and admin new-order emails are not built |
| Contact enquiries | Emailed to `info@victorytodayeducation.com`; not stored in the database; no new tables |
| Legacy column | `commissions.clawback_due` stays unchanged; nothing sets or shows it. Check dependencies and backward compatibility before ever removing it |
| Refunds and commission | No customer refund request, no commission hold, no automatic commission reversal. Exception refunds are admin-approved only |
| Test data | The 8 development test orders and their records stay untouched until the owner approves deletion |
| Policy pages | Four drafts stay unpublished until reviewed and approved |

## Pending, tracked separately

| # | Item | Owner | Status | Blocks |
|---|---|---|---|---|
| 1 | Create the pull request `sp5-finalize` into `master` (description: `SP5-PR-description.md`), then review CI checks | Owner (GitHub login) | Waiting on GitHub authentication | Review |
| 2 | Razorpay Test Mode credentials (`RAZORPAY_KEY_ID` starting `rzp_test_`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`) in the staging `.env`, and a test-mode webhook with `payment.captured`, `payment.failed`, `refund.processed`, `refund.failed`. Then run the real test checklist | Owner provides, then development | Not started | Real payment verification |
| 3 | SMTP configuration (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_SECURE`, `MAIL_FROM`; `CONTACT_TO_EMAIL` is confirmed) and contact-form delivery test, including the failure case | Owner provides, then development | Not started | Contact form in production |
| 4 | Hardcopy shipping charge and courier details | Owner | Pending | Hardcopy checkout |
| 5 | GST rate and whether book prices include GST or GST is added on top; whether GST applies to shipping | Owner | Pending | Hardcopy and digital pricing |
| 6 | Review and approve the four policy drafts; fill the placeholders (last-updated date, other service providers, retention period, legal requirements) | Owner | Pending | Publishing policies |
| 7 | Verify the founder's doctorate and title | Owner | Pending | Founder section wording |
| 8 | Hosting provider, HTTPS API address, server requirements (PHP-FPM, MySQL 8, Poppler, cron) | Owner | Pending, separate final stage | Deployment |
| 9 | Final deployment handover document | Development | After items 2 to 8 | Handover |

## Standing items

- Rotate any Razorpay key that was ever pasted into chat. Live keys go only into the production server `.env`.
- Real Razorpay test results are recorded in `SP5-razorpay-test-checklist.md`; it stays marked not run until item 2 is done.
- Unverified homepage claims have been removed and may be re-added only after the business verifies them.
- Final merge, production deployment and Razorpay Live activation each need a separate explicit approval.
