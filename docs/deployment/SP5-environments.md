# SP-5 environments: staging and production configuration

Configuration guide for the API (`backend/.env`) and the frontend build. It lists variable **names** only; never put values in git, chat or screenshots. The rules below are the ones the code enforces (`backend/src/Support/Env.php`, `backend/src/Razorpay/Config.php`, `backend/scripts/check_config.php`). Hosting itself is a separate final stage and has not started: nothing here has been run on a real server.

Run `php scripts/check_config.php` on the server after every `.env` change. It prints PASS, WARN, INFO or FAIL per item, never prints a secret value, and exits 1 if any item FAILs. In Docker: `docker compose exec -T php php scripts/check_config.php`.

## 1. Do not use docker-compose.yml in production

The repository `docker-compose.yml` is a **development** stack:

- it contains development passwords (MySQL root password, the application database password `changeme`) written in the file;
- it publishes the MySQL port to the host (`3307:3306`), so the database is reachable from outside the container;
- it mounts the source tree as a volume, serves plain HTTP on port 8080, and uses the floating `nginx:alpine` and `mysql:8.0` tags.

Do not run it on a staging or production server. On a server: do not publish the database port (firewall open for 22, 80 and 443 only), use credentials created for that server, pin image versions, and terminate HTTPS in front of nginx.

Production database credentials must look like this (placeholders only):

```
DB_HOST=<private hostname or socket of the database server>
DB_PORT=3306
DB_NAME=<app database name>
DB_USER=<dedicated application user, not root>
DB_PASS=<random value, 12 characters or more, generated for this server; never reused from development>
```

The application user needs only the privileges on its own database (SELECT, INSERT, UPDATE, DELETE, plus CREATE/ALTER/INDEX/REFERENCES/DROP only while migrations run, ideally with a separate migration user). `check_config.php` FAILs outside development when `DB_PASS` is shorter than 12 characters or a placeholder.

## 2. APP_ENV rules

- Only the exact, case-sensitive values `development`, `dev`, `local`, `test`, `testing` count as development.
- **Anything else is treated as production**: unset, empty, `production`, `Production`, `prod` and `staging` all behave the same. Use `staging` for staging and `production` for production; the code treats both as production.
- Development allows the fake gateway and short placeholder secrets. Outside development the fake gateway is refused and placeholder or short secrets are rejected.

| | Local development / tests | Staging | Production |
|---|---|---|---|
| `APP_ENV` | `development` | `staging` | `production` |
| `RAZORPAY_GATEWAY` | `fake` (tests) or `live` | `live` with `rzp_test_` keys | `live` |
| Razorpay key id prefix | any | `rzp_test_` | `rzp_live_` only after the owner approves going live |
| Database | docker-compose MySQL | separate database with throwaway data | production database |
| `FRONTEND_ORIGIN` | `http://localhost:5173` | staging site origin (https) | production site origin (https) |
| Razorpay webhook | none | test-mode webhook | live-mode webhook with its own secret |

**The fake gateway accepts forged payment signatures.** It is for local development and the automated tests only. It must never be configured on staging or production (the app refuses it there, and `check_config.php` WARNs when it sees it).

**Razorpay keys.** Use `rzp_test_` keys on staging. `rzp_live_` keys go into production only after the Razorpay test-mode checklist ([SP5-razorpay-test-checklist.md](SP5-razorpay-test-checklist.md)) has passed and the owner has explicitly approved. Never put a live key in a staging or development file. If one is pasted into the wrong place, regenerate it in the Razorpay dashboard. `check_config.php` WARNs when the live gateway is configured with an `rzp_test_` key, which is the expected state on staging.

## 3. API variables (`backend/.env`)

| Name | Purpose | Required | Rule enforced |
|---|---|---|---|
| `APP_ENV` | Environment switch (section 2) | Required | See section 2 |
| `RAZORPAY_GATEWAY` | `live` (default when unset) or `fake` | Required in practice | Only `live` or `fake`; `fake` only with a development `APP_ENV` |
| `RAZORPAY_KEY_ID` | Razorpay key id | Required with `live` | Matches `rzp_test_` or `rzp_live_` plus 6 or more characters; no placeholder |
| `RAZORPAY_KEY_SECRET` | Razorpay key secret | Required with `live` | 20 or more characters outside development; no placeholder |
| `RAZORPAY_WEBHOOK_SECRET` | Signs webhook calls; must equal the secret entered in the Razorpay dashboard | Required | 32 or more characters outside development; empty is always rejected; no placeholder |
| `JWT_SECRET` | Signs login tokens | Required | 32 or more characters in every environment; placeholder rejected. Changing it logs everybody out |
| `CODE_SECRET` | HMAC and encryption key for digital access codes | Required | 32 or more characters; placeholder rejected. **Rotating it invalidates every unredeemed code** |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | MySQL connection | Required | `DB_PASS` 12 or more characters outside development, not a placeholder (section 1) |
| `FRONTEND_ORIGIN` | The one allowed CORS origin | Required | Bare origin: `scheme://host[:port]`, no path, no trailing slash; must be `https` outside development. Must equal the site's origin exactly (`https://example.com` and `https://www.example.com` differ) |
| `TRUSTED_PROXIES` | Comma list of proxy IPs/CIDRs allowed to set `X-Forwarded-For` for rate limits | Optional | Empty means rate limits use `REMOTE_ADDR`. Invalid entries are ignored (counted by `check_config.php`). Prefer fixing `REMOTE_ADDR` in the web server (`set_real_ip_from`) |
| `CONTACT_TO_EMAIL` | Mailbox that receives contact-form enquiries | Required for the contact form | Valid email address (FAIL outside development if missing) |
| `MAIL_FROM` | Sender address, a mailbox on the site's domain | Required for the contact form | Valid email address. The visitor is only put in Reply-To |
| `SMTP_HOST` | SMTP server | Required for the contact form | Non-empty |
| `SMTP_PORT` | SMTP port | Optional | Number; default 587 |
| `SMTP_USER`, `SMTP_PASS` | SMTP login (AUTH LOGIN) | Required for the contact form | Both non-empty |
| `SMTP_SECURE` | `tls` (STARTTLS, default), `ssl` (implicit TLS) or `none` | Optional | `none` sends the login unencrypted: local mail catcher only, FAIL outside development |

Notes:

- Razorpay and mail variables are validated lazily, when first used, not at boot. A server with a bad payment config still serves books and health checks; `check_config.php` is how you find the problem before the first customer does.
- Contact form (`POST /api/contact`): until `CONTACT_TO_EMAIL`, `MAIL_FROM`, `SMTP_HOST`, `SMTP_USER` and `SMTP_PASS` are set, the endpoint answers `502 email_failed` and the site shows an honest failure message. It has a honeypot field, a limit of 5 per hour per client IP, and does not store messages.
- Generate secrets with a random generator, for example `php -r 'echo bin2hex(random_bytes(32));'`. Use different values on staging and production.
- Keep `backend/.env` readable only by the deploy user and the PHP-FPM user, outside any web root listing, and out of git (`.gitignore` covers `.env*`).

## 4. Razorpay dashboard

- Webhook URL: `https://<api-host>/api/webhooks/razorpay`.
- Webhook secret: the same value as `RAZORPAY_WEBHOOK_SECRET`.
- Active events, exactly these four: `payment.captured`, `payment.failed`, `refund.processed`, `refund.failed`.
- Auto-capture ON.
- Test mode and live mode have separate keys and separate webhooks. Staging uses the test-mode webhook; production uses a new live-mode webhook with a new secret.
- Razorpay delivers only to public HTTPS URLs.

## 5. Frontend build (`VITE_API_URL`)

`VITE_API_URL` is read when the site is **built** and baked into the files. Set it on the build command:

```bash
npm ci
VITE_API_URL=https://<api-host> npm run build
```

- Use a different build for staging and production; the API origin is also written into the Content-Security-Policy of `dist/_headers` and `dist/.htaccess`.
- The production build **fails** if `VITE_API_URL` is missing, malformed, or points at localhost. `VITE_ALLOW_LOCAL_API=1` bypasses this for local verification builds only; never upload such a build.
- No trailing slash. The site must be served from a domain root. Host rules: [frontend-hosting.md](frontend-hosting.md).

## 6. Server requirements

- **PHP 8.4** FPM with `pdo_mysql`, `openssl`, `mbstring`, `json`. The repository image is `php:8.4-fpm-trixie`; the image used before is kept as `vtee-php:8.2-trixie-rollback`.
- **Poppler** (`poppler-utils`: `pdfinfo`, `pdftoppm`) for the secure reader. `prlimit` should also exist (reader resource limits; `check_config.php` WARNs without it).
- **PHP-FPM workers**: the reader renders pages synchronously (up to 30 s on the first render of a page). Set `pm.max_children` to at least 10; the development stack's 5 workers can be exhausted by a broken PDF.
- **PHP limits** (`backend/php/uploads.ini`): `upload_max_filesize = 50M`, `post_max_size = 55M`, `display_errors = Off`, errors logged to a file.
- **Storage**: `backend/storage/books` (private PDFs and rendered pages) must be **outside the web root** and writable by the PHP-FPM user; `backend/public/uploads` (covers, previews, index files) must be writable too. Web root is `backend/public`. `check_config.php` verifies all of this.
- **Cron reconciler** (mandatory): it is the only fallback for lost payment webhooks and also reconciles refunds and purges old rate-limit rows. Every 15 minutes, never overlapping:

  ```cron
  */15 * * * * cd /path/to/backend && flock -n /tmp/vtee-reconcile.lock php scripts/reconcile_orders.php >> /var/log/vtee-reconcile.log 2>&1
  ```

  Exit codes: 0 ok, 2 some orders errored (see the log), 1 fatal. Alert on a non-zero exit.
- **Database**: MySQL 8.4 LTS (or a tested MariaDB). `mysql:8.0` in the development compose file reached end of life in 2026 (verify the date). Apply migrations 001 to 008 in order after a backup ([SP5-backup-restore-rollback.md](SP5-backup-restore-rollback.md)), after `backend/scripts/premigration_checks.sql` returns zero rows.
- **Admin user**: `php scripts/seed_admin.php <username> <strong-password>` on the server (16 or more random characters; do not copy the development admin).

## 7. HTTPS and HSTS

- HTTPS is required on both the API and the site (Razorpay webhooks, secure payment, `FRONTEND_ORIGIN` must be `https`).
- HSTS is not enabled by default. It is a commented line in `backend/nginx/default.conf` (API) and in `public/_headers` / `public/.htaccess` (site). Enable it only once the exact host serves HTTPS correctly. Start at `max-age=300`, then raise it (86400, then 15552000). Do not add `includeSubDomains` or `preload` until every subdomain is on HTTPS.
- The site sends a Content-Security-Policy in Report-Only mode; enforce it after a clean observation period ([frontend-hosting.md](frontend-hosting.md), section 5).
- Behind a TLS proxy or load balancer: set `set_real_ip_from <proxy range>; real_ip_header X-Forwarded-For; real_ip_recursive on;` in nginx (commented example in `backend/nginx/default.conf`), or set `TRUSTED_PROXIES`. Without one of them every visitor shares one rate-limit bucket.

## 8. Go-live order (summary)

1. Staging: test keys, `check_config.php` exits 0, Razorpay checklist run and signed off by the owner.
2. Production: back up, premigration checks, migrations 001-008, production `.env`, `check_config.php` exits 0, cron installed, admin seeded.
3. Owner approves; live keys and a new live webhook; one small real payment and exception refund by the owner.
