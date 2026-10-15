# Backend

PHP 8.2 + MySQL 8.0, no framework, no Composer. See
`docs/superpowers/specs/2026-10-08-backend-foundation-design.md` for the
full design.

## Local setup

1. `cp backend/.env.example backend/.env` (already done if you're reading
   this from a fresh clone that included it — otherwise copy it yourself)
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
