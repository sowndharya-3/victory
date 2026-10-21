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

## Environment variables

See `backend/.env.example` for the full list: `DB_HOST`, `DB_PORT`,
`DB_NAME`, `DB_USER`, `DB_PASS`, `JWT_SECRET`, `FRONTEND_ORIGIN`.

## Migrations

Schema lives in `backend/migrations/*.sql`, applied in filename order.
Apply the initial schema against the running stack:

```bash
docker compose exec -T mysql mysql -u vtee -pchangeme vtee < backend/migrations/001_init.sql
```

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

### Creating the first admin

No admin-creation endpoint exists yet (that's a later sub-project). Seed
one via the CLI:

```bash
docker compose exec -T php php scripts/seed_admin.php <username> <password>
```

## Test results

See `backend/TEST_RESULTS.md` for the actual captured output of the last
full test run (`backend/tests/jwt_test.php` + `backend/tests/http_test.sh`).
Re-run both against a live `docker compose up` stack any time to re-verify.
