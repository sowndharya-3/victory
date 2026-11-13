# Victory Today — live deployment package (Hostinger, same domain)

Built 13 Nov 2026 from `master` 2b9f91b (= `sp5-finalize` 877a3de). Site origin `https://victorytodayeducation.com`,
API on the same host under `/api`. Nothing in this folder contains a credential; all secrets go only into the
server `.env`.

## Layout on the server

```
/home/<user>/domains/victorytodayeducation.com/
├── public_html/              <- contents of ./public_html (index.html, assets/, .htaccess — dotfile!)
│   └── api -> ../vtee-backend/public      (symlink, step 4)
└── vtee-backend/             <- contents of ./vtee-backend (config, src, scripts, migrations, public, storage, .env)
```

`vtee-backend/` sits beside `public_html`, outside the web root. `public_html/api` is a symlink to
`vtee-backend/public`, so the API's own `.htaccess` and `index.php` work unchanged and admin uploads land in a
web-served folder. `/uploads/...` is rewritten to `/api/uploads/...` by `public_html/.htaccess`.

## Server requirements (check BEFORE buying/choosing the plan)

| Need | Why | Shared hosting? |
|---|---|---|
| PHP 8.2+ (tested 8.4) with pdo_mysql | API | yes, pick 8.2+ in hPanel |
| MySQL 8.0 | DB | yes |
| Cron | `reconcile_orders.php` every 15 min | yes (hPanel Cron Jobs) |
| SSH | `ln -s`, `php scripts/...`, cron | Premium/Business plans |
| **`pdfinfo` + `pdftoppm` (poppler-utils) and PHP `proc_open`** | the **secure book reader** renders private PDF pages with them | **usually NOT available on shared hosting** |
| `/usr/bin/prlimit` | reader resource limits | warning only |

Run `php scripts/check_config.php` (step 7). If it fails on `pdfinfo`/`pdftoppm`, digital books cannot be read on
this host: move the backend to a Hostinger VPS (Docker stack in the repo: `backend/Dockerfile`,
`backend/nginx/default.conf`) or any server where poppler-utils can be installed. Everything else in this package
still applies.

## Steps

1. **DNS/HTTPS**: `victorytodayeducation.com` with SSL active in hPanel. Redirect `www.` to the bare domain (the
   build and `FRONTEND_ORIGIN` use the bare domain; the other origin would fail CORS).
2. **Upload** `public_html/*` (including `.htaccess`) into `public_html/`, and `vtee-backend/` beside it.
3. **PHP version** in hPanel: 8.2 or newer. Upload limits: `upload_max_filesize`/`post_max_size` ≥ 64M (book PDFs).
4. **Symlink** (SSH): `cd ~/domains/victorytodayeducation.com/public_html && ln -s ../vtee-backend/public api`
   Check: `https://victorytodayeducation.com/api/health` returns JSON.
5. **Database**: create DB + user in hPanel. Run `migrations/001_init.sql` … `008_partner_email_unique.sql` in
   order (phpMyAdmin or `mysql <`). `scripts/premigration_checks.sql` first on an existing DB. Rollback: `migrations/ROLLBACK.md`.
6. **`.env`**: `cp vtee-backend/.env.template vtee-backend/.env`, fill every blank:
   - `DB_*` from step 5
   - `JWT_SECRET`, `CODE_SECRET`: `php -r 'echo bin2hex(random_bytes(32));'` each (different values)
   - `RAZORPAY_*`: **test keys first** (`rzp_test_…`, test-mode webhook secret ≥ 32 chars). Live keys only after
     the test checklist passes and the owner approves.
   - `SMTP_PASS` = mailbox password of `info@victorytodayeducation.com`. Confirm host/port in hPanel → Emails →
     Configuration (`smtp.hostinger.com` 465 SSL is the usual; 587 → `SMTP_SECURE=tls`).
   - `chmod 600 .env`
7. **Verify**: `cd vtee-backend && php scripts/check_config.php` must exit 0 (every line OK; `prlimit` may WARN).
8. **Permissions**: `storage/books` and `public/uploads` writable by the PHP user (usually already, same user).
9. **Admin**: `php scripts/seed_admin.php <username> <password-16+chars>` (prefix with a space to keep it out of
   shell history). Login at `https://victorytodayeducation.com/admin`.
10. **Cron** (hPanel → Cron Jobs, every 15 minutes):
    `php /home/<user>/domains/victorytodayeducation.com/vtee-backend/scripts/reconcile_orders.php >> /home/<user>/vtee-reconcile.log 2>&1`
11. **Razorpay dashboard** (test mode first): Webhooks → Add → URL
    `https://victorytodayeducation.com/api/webhooks/razorpay`, secret = `RAZORPAY_WEBHOOK_SECRET`, events
    `payment.captured`, `payment.failed`, `refund.processed`, `refund.failed`. Enable auto-capture.
12. **Books**: Admin → Books → Add Book, upload cover / index / full PDF, Publish (repo docs
    `docs/deployment/SP5-handover.md` §8 lists the two books and local file paths). Book files are NOT in this package.
13. **Live test** (test keys): `docs/deployment/SP5-razorpay-test-checklist.md` — one digital purchase, one hard-copy
    purchase, one failed payment, one admin exception refund, duplicate webhook, My Books, reader, contact form.
14. **Go live** (owner approval): swap to `rzp_live_` keys + live-mode webhook in `.env`, re-run
    `check_config.php`, one real small purchase, then refund it as an admin exception.

## Smoke checks

- `/` loads; `/books`, `/track`, `/admin` deep links load after refresh (SPA fallback).
- `/api/health` → JSON; `/api/.env` and `/api/config/` → 404.
- Book cover URL `/uploads/books/<id>/cover.jpg` serves after upload.
- Browser console: no CSP *errors* (Report-Only warnings are logged, not blocking).

## Not included / still pending

Book PDFs and covers; GST rate and shipping charge (placeholders unchanged in code); policy pages stay drafts;
founder title verification; HSTS line in `.htaccess` (enable after HTTPS is confirmed). Nothing here deploys itself.
