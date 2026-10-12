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

(filled in by Task 2)

## API endpoints

(filled in as each endpoint is built)

## Test results

See `backend/TEST_RESULTS.md` (written by Task 8).
