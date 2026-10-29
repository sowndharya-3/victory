# Backend

PHP 8.2 + MySQL 8.0, no framework, no Composer. See
`docs/superpowers/specs/2026-10-08-backend-foundation-design.md` for the
full design.

## Local setup

1. `cp backend/.env.example backend/.env` (already done if you're reading
   this from a fresh clone that included it — otherwise copy it yourself)
1b. **Generate a real `JWT_SECRET`** — do not leave the placeholder from
   `.env.example`. Run `php -r 'echo bin2hex(random_bytes(32));'` (or use
   the `php` service: `docker compose exec -T php php -r '...'` once it's
   up) and paste the output into `backend/.env`. The server refuses to
   start with the placeholder or any secret under 32 bytes.
2. `docker compose up -d --build`
3. Apply the schema: see "Migrations" below.
4. API is reachable at `http://localhost:8080`.
5. In a production deployment, `backend/public/uploads/` and `backend/storage/`
   must exist and be writable by the PHP process's user before first use —
   they're gitignored (correctly, since they hold user-uploaded content) so a
   fresh deploy won't have them yet, and this codebase can't create them for
   itself if the process lacks permission to do so.

## Environment variables

See `backend/.env.example` for the full list: `DB_HOST`, `DB_PORT`,
`DB_NAME`, `DB_USER`, `DB_PASS`, `JWT_SECRET`, `FRONTEND_ORIGIN`, plus the SP-5 keys
`APP_ENV`, `RAZORPAY_GATEWAY` (`fake` is dev-only), `RAZORPAY_KEY_ID`,
`RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`, `CODE_SECRET` (>=32 random bytes).
These are validated lazily at use, so SP-4 endpoints work without them.

## Migrations

Schema lives in `backend/migrations/*.sql`, applied in filename order.
Apply the initial schema against the running stack:

```bash
docker compose exec -T mysql mysql -u vtee -pchangeme vtee < backend/migrations/001_init.sql
# then 002_book_management.sql and 003_orders_payments.sql, in order
```

Migrations are one-shot: re-applying a file fails on "already exists" /
duplicate column and changes no data. 003 is additive only; never drop the
volume to re-run it.

## API endpoints

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | /api/health | none | DB connectivity check |
| POST | /api/auth/register | none | `{name, email, phone?, password}` → 201 `{token, user}` or 409 if email taken |
| POST | /api/auth/login | none | `{email, password}` → 200 `{token, user}` or 401 |
| POST | /api/admin/login | none | `{username, password}` → 200 `{token, admin}` or 401 |

| GET | /api/me | Bearer JWT, any role | returns the caller's own profile, shape depends on role |

| GET | /api/books | none | Published, non-archived books. `[{id,title,description,category,coverUrl,onlinePrice,hardcopyPrice}]` |
| GET | /api/books/:id | none | One book + `indexUrl`, `previewPages`. 404 if missing/unpublished/archived |

| GET | /api/admin/books | admin | All books, any status, with `hasCover/hasIndex/hasPdf/previewPageCount` |
| GET | /api/admin/books/:id | admin | One book, any status |
| POST | /api/admin/books | admin | `{title, description?, category?, onlinePrice, hardcopyPrice}` → 201 |
| PUT | /api/admin/books/:id | admin | Partial update of any metadata field |
| POST | /api/admin/books/:id/publish | admin | sets published=1 |
| POST | /api/admin/books/:id/unpublish | admin | sets published=0 |
| POST | /api/admin/books/:id/archive | admin | sets archived=1, published=0 |
| POST | /api/admin/books/:id/cover | admin | multipart `file` (image, max 5MB) → `{coverUrl}` |
| POST | /api/admin/books/:id/index | admin | multipart `file` (image or PDF, max 5MB/50MB) → `{indexUrl}` |
| POST | /api/admin/books/:id/preview-pages/:pageNumber | admin | multipart `file` (image, max 5MB) → upserts that page, `{pageNumber, url}` |
| DELETE | /api/admin/books/:id/preview-pages/:pageNumber | admin | removes that page's file and row, `{deleted: true}` |
| POST | /api/admin/books/:id/pdf | admin | multipart `file` (PDF, max 50MB) → stores privately under `backend/storage/`, never publicly servable |

| GET | /api/me/orders/:id/access-code | customer | `{code}` (`XXXX-XXXX-XXXX-XXXX`) while the paid digital order's code is unredeemed; else 404 |
| POST | /api/me/redeem | customer | `{code}` → `{bookId, orderId}`; the only way digital access is granted. Errors `code_invalid` 400, `code_revoked`/`code_order_not_paid` 403, `code_already_redeemed`/`already_owned` 409. Failed attempts limited to 5/15 min per user, 20/15 min per IP (429) |
| POST | /api/me/orders/:id/reissue-code | customer (owner) | revokes the current code (and any access it granted), returns a new `{code}`; 3/day. Only when the current code is active, redeemed by the buyer, or revoked for `customer_request`; otherwise `reissue_requires_admin` 403 (e.g. a gifted code redeemed by someone else, or an admin revoke) |
| GET | /api/me/library | customer | `{books:[{bookId,title,coverUrl,orderId,purchasedAt,accessStatus}], pendingRedemptions:[{orderId,bookId,title,coverUrl,purchasedAt}]}` |
| GET | /api/track/:trackingCode | none | hard-copy status `{orderNumber,bookTitle,status,label,updatedAt,courier?,trackingNumber?}`; never address, email or codes; 10/min per IP |

Access codes are stored as HMAC-SHA256 (pepper from `CODE_SECRET`) plus last 4 chars; the plaintext is kept
AES-256-GCM-encrypted (order id as AAD, so a blob copied to another order won't decrypt) only until redeemed. Rotating `CODE_SECRET` invalidates every unredeemed code.

### Creating the first admin

No admin-creation endpoint exists yet (that's a later sub-project). Seed
one via the CLI:

```bash
docker compose exec -T php php scripts/seed_admin.php <username> <password>
```

### Reconciling pending orders (cron)

`scripts/reconcile_orders.php` fulfils pending orders (older than 15 min) that
Razorpay reports captured, via the normal paid path; cancels pending orders
older than 24 h with no capture (payment `failed`, reason `expired`); re-checks
`failed`/`cancelled` orders from the last 7 days and fulfils any Razorpay reports
captured (e.g. a retry succeeded but the webhook was lost; nothing else is changed
on those orders); and purges `rate_limits` rows older than 2 days. It prints
`paid=N cancelled=N errors=N purged=N` and exits `0` on success, `2` if any order
errored (counts still printed; see the log for details), `1` on a fatal error.

An order flagged `admin_flag = amount_mismatch` (captured amount differs from the
order) stays `pending` and is re-polled every run until an admin resolves it.

Run it every 15 minutes, with `flock -n` so a slow run is never overlapped:

```cron
*/15 * * * * cd /path/to/vtee && flock -n /tmp/vtee-reconcile.lock docker compose exec -T php php scripts/reconcile_orders.php >> /var/log/vtee-reconcile.log 2>&1
# without Docker: */15 * * * * cd /path/to/vtee/backend && flock -n /tmp/vtee-reconcile.lock php scripts/reconcile_orders.php >> /var/log/vtee-reconcile.log 2>&1
```

## Test results

See `backend/TEST_RESULTS.md` for the actual captured output of the last
full test run:

- `backend/tests/jwt_test.php` + `backend/tests/http_test.sh` — auth/health foundation
- `backend/tests/upload_validator_test.php` + `backend/tests/book_test.sh` — book management (create → publish → cover/index/preview-pages/PDF uploads → public response shape/privacy → edit/unpublish/archive → role separation → invalid-file rejection)

Re-run any of them against a live `docker compose up` stack any time to re-verify.
