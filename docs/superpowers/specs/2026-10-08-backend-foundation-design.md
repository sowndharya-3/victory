# Backend Foundation & Authentication (Sub-project 2 of the book-sales platform)

## Context

Phase 1 (React frontend, mock data) is merged to `master`. This sub-project
replaces the mock `SessionContext` with a real PHP + MySQL backend — but
only the foundation: project skeleton, full database schema for the whole
platform, and authentication (customer + admin). Books, orders, referrals,
commissions, and all admin CRUD get their own endpoints in later
sub-projects (3–5); this one does not implement them, only the tables they
will eventually use.

## Goals

- A running PHP + MySQL API, proven by real HTTP requests against a real
  Docker-based stack — not just code that looks correct.
- Complete database schema for the entire platform (every table the full
  spec needs), so later sub-projects add endpoints against an already-
  stable schema instead of migrating it repeatedly.
- Customer registration/login and admin login, both JWT-based, with a
  `GET /api/me` that proves the token round-trips correctly.
- A structure (router, middleware, controllers) that later sub-projects
  extend by adding files, not by restructuring what exists.

## Non-goals (future sub-projects)

Book/order/referral/commission/admin-CRUD endpoints, Razorpay integration,
the digital reader's access-controlled file serving, hard-copy address
collection endpoints, wiring the React frontend to any of this beyond
login/register/me. The existing React mock-data flow is untouched in this
sub-project — no frontend UI changes.

## Architecture

```
backend/
├── public/
│   └── index.php          # the only web-exposed file; all requests land here
├── src/
│   ├── Router/
│   │   └── Router.php     # method+path → handler, supports {param} segments
│   ├── Database/
│   │   └── Db.php         # PDO singleton, built from env config
│   ├── Auth/
│   │   ├── Jwt.php        # encode/decode, HMAC-SHA256 via hash_hmac, no Composer
│   │   └── Password.php   # thin wrapper over password_hash/password_verify
│   ├── Controllers/
│   │   ├── AuthController.php    # register, login (customer)
│   │   ├── AdminController.php   # login (admin)
│   │   └── MeController.php      # GET /api/me
│   └── Middleware/
│       ├── Cors.php        # single allowed origin from env, handles OPTIONS
│       ├── AuthMiddleware.php  # verifies Bearer JWT, enforces role
│       └── ErrorHandler.php    # uncaught-exception → consistent JSON error
├── migrations/
│   └── 001_init.sql        # full schema, all tables below
└── config/
    └── env.php             # loads backend/.env (KEY=VALUE, gitignored) into getenv()

docker-compose.yml           # repo root: php-fpm + nginx + mysql
backend/.env.example         # checked in; backend/.env is gitignored
```

**Request flow:** nginx proxies every request under `/api/` to PHP-FPM,
which always runs `public/index.php`. `index.php` boots env config, DB
connection, and the router; `Cors` middleware runs first (handles
`OPTIONS` preflight and sets `Access-Control-Allow-Origin` to the single
configured frontend origin — never `*`); `ErrorHandler` wraps the rest so
any uncaught exception becomes a JSON error response instead of a raw PHP
error. Protected routes declare the roles they accept; `AuthMiddleware`
verifies the `Authorization: Bearer <jwt>` header before the controller
runs.

**Why a front controller (not endpoint-per-file):** CORS, JWT
verification, and error formatting are identical across every endpoint
this platform will ever have. Centralizing them once in `index.php` +
`Middleware/` means sub-projects 3–5 add a route line and a controller
method, not a 15-line copy-pasted boilerplate header in every new file.

## Database schema (complete platform schema, this sub-project only wires up `admins` + `users`)

```sql
CREATE TABLE admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(20) NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE books (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  category VARCHAR(100) NULL,
  cover_url VARCHAR(500) NULL,
  online_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  hardcopy_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  digital_file_path VARCHAR(500) NULL COMMENT 'server-side path only, never exposed directly to clients',
  published TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE referral_applications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  qualification VARCHAR(255) NULL,
  place VARCHAR(100) NULL,
  district VARCHAR(100) NULL,
  status ENUM('Student','Scholarship','Faculty','Institution') NOT NULL,
  phone VARCHAR(20) NOT NULL,
  whatsapp VARCHAR(20) NOT NULL,
  upi_id VARCHAR(100) NULL,
  gmail VARCHAR(190) NOT NULL,
  application_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE referral_partners (
  id INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NULL,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  whatsapp VARCHAR(20) NOT NULL,
  upi_id VARCHAR(100) NULL,
  gmail VARCHAR(190) NOT NULL,
  referral_code VARCHAR(30) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES referral_applications(id)
);

CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(50) NOT NULL UNIQUE,
  user_id INT NOT NULL,
  book_id INT NOT NULL,
  purchase_type ENUM('digital','hardcopy') NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  referral_partner_id INT NULL,
  status ENUM('pending','paid','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (book_id) REFERENCES books(id),
  FOREIGN KEY (referral_partner_id) REFERENCES referral_partners(id)
);

CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  razorpay_order_id VARCHAR(100) NULL,
  razorpay_payment_id VARCHAR(100) NULL,
  razorpay_signature VARCHAR(255) NULL,
  amount DECIMAL(10,2) NOT NULL,
  status ENUM('created','authorized','captured','failed','refunded') NOT NULL DEFAULT 'created',
  raw_response JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id)
);

CREATE TABLE book_access (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  book_id INT NOT NULL,
  order_id INT NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_book (user_id, book_id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (book_id) REFERENCES books(id),
  FOREIGN KEY (order_id) REFERENCES orders(id)
);

CREATE TABLE hardcopy_addresses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL UNIQUE,
  full_name VARCHAR(150) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  address_line TEXT NOT NULL,
  city VARCHAR(100) NOT NULL,
  district VARCHAR(100) NOT NULL,
  state VARCHAR(100) NOT NULL,
  pincode VARCHAR(10) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id)
);

CREATE TABLE referral_mappings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  referral_partner_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (referral_partner_id) REFERENCES referral_partners(id)
);

CREATE TABLE commissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL UNIQUE,
  referral_partner_id INT NOT NULL,
  purchase_type ENUM('digital','hardcopy') NOT NULL,
  amount DECIMAL(10,2) NOT NULL COMMENT 'snapshotted at order-paid time, not recomputed later',
  status ENUM('pending','payable','paid') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (referral_partner_id) REFERENCES referral_partners(id)
);

CREATE TABLE settings (
  `key` VARCHAR(100) PRIMARY KEY,
  `value` VARCHAR(255) NOT NULL
);
-- seeded with commission_rate_digital=25, commission_rate_hardcopy=25
```

`commissions.amount` is snapshotted at creation (not recomputed from
`settings` later) — the Phase 1 mock build hit exactly this bug (a rate
change retroactively rewrote past commissions) and it's designed out here
from the start.

## Auth design

**JWT (no Composer):** `base64url(header) + "." + base64url(payload) + "." + base64url(HMAC-SHA256(header.payload, JWT_SECRET))`.
Header is always `{"alg":"HS256","typ":"JWT"}`. Payload: `{"sub": <id>, "role": "customer"|"admin", "iat": <unix>, "exp": <unix>}`.
Expiry: 7 days for customers (so "stay logged in" works without re-login
every session), 2 hours for admins (shorter — more sensitive panel).
`Jwt::decode()` verifies the signature with `hash_equals` (timing-safe)
and checks `exp` before returning the payload; any failure throws a
typed exception `AuthMiddleware` turns into a 401.

**Passwords:** `password_hash($plain, PASSWORD_DEFAULT)` on
register, `password_verify($plain, $hash)` on login. Minimum 8 characters,
checked server-side before hashing.

**Separation:** `admins` and `users` are different tables with different
login endpoints and different JWT `role` claims — an admin JWT cannot
authenticate as a customer and vice versa, enforced by `AuthMiddleware`
checking `role` against the route's declared allowed roles.

## Endpoints (this sub-project)

| Method | Path | Auth | Body / Response |
|---|---|---|---|
| GET | `/api/health` | none | `{"status":"ok"}` — also confirms DB connectivity by running `SELECT 1` |
| POST | `/api/auth/register` | none | `{name, email, phone?, password}` → 201 `{token, user: {id, name, email}}` or 409 if email taken |
| POST | `/api/auth/login` | none | `{email, password}` → 200 `{token, user: {...}}` or 401 |
| POST | `/api/admin/login` | none | `{username, password}` → 200 `{token, admin: {id, username}}` or 401 |
| GET | `/api/me` | Bearer JWT, any role | 200 `{role, id, ...profile fields}` from the `role`+`sub` in the token, re-fetched from DB (not trusted from the token body) |

**Validation:** manual (no library) — required fields present, `email`
via `filter_var(..., FILTER_VALIDATE_EMAIL)`, password length ≥ 8.
Failures return 400 with `{"error": {"code": "validation_error", "message": "...", "fields": {...}}}`.

**Error shape (all non-2xx responses):** `{"error": {"code": "<snake_case>", "message": "<human readable>"}}`
with the matching HTTP status (400 validation, 401 unauthorized, 403
wrong role, 404 not found, 409 conflict, 500 unhandled).

## CORS

`Cors` middleware reads `FRONTEND_ORIGIN` from env (e.g.
`http://localhost:5173` for dev) and sets `Access-Control-Allow-Origin` to
exactly that value — never `*`, since credentials (the Authorization
header) are involved. Handles `OPTIONS` preflight by responding 204 with
`Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS` and
`Access-Control-Allow-Headers: Content-Type, Authorization` before the
router runs.

## Environment config

`backend/.env` (gitignored) with `backend/.env.example` checked in:
```
DB_HOST=mysql
DB_PORT=3306
DB_NAME=vtee
DB_USER=vtee
DB_PASS=changeme
JWT_SECRET=changeme-generate-a-real-random-secret
FRONTEND_ORIGIN=http://localhost:5173
```
`config/env.php` parses `KEY=VALUE` lines (skipping blank lines and `#`
comments) into `$_ENV`/`getenv()` — no Composer `vlucas/phpdotenv`, per
the no-Composer constraint.

## Docker Compose (repo root)

Three services: `mysql` (official `mysql:8.0`, env-configured db/user/pass,
named volume for persistence), `php` (official `php:8.2-fpm` + `pdo_mysql`
extension installed via a small Dockerfile), `nginx` (official
`nginx:alpine`, config proxies `.php` requests to the `php` service and
routes everything under `/` to `public/index.php`). Nginx exposed on a
host port (`8080`) so the React dev server (`5173`) can call
`http://localhost:8080/api/...` cross-origin.

## Testing (this sub-project's actual verification, not aspirational)

1. `docker compose up -d`, wait for MySQL healthy.
2. Apply `migrations/001_init.sql` against the running MySQL container.
3. Seed one admin row (password hashed via a one-off PHP script, since
   there's no admin-creation endpoint yet — that's admin-CRUD, a later
   sub-project) and the two `settings` rows.
4. Run real `curl` requests against the running stack for every scenario
   the user specified: health check, register, login, `GET /api/me` with
   the returned token, admin login, a protected route with no/garbage
   token (expect 401), an expired token (construct one with a past `exp`),
   wrong credentials (expect 401), a CORS preflight `OPTIONS` request
   (expect the configured origin echoed back, not `*`), and confirm DB
   connectivity via the health check actually querying MySQL, not just
   returning a static 200.
5. Capture actual request/response pairs as evidence in the implementation
   report — not "should work," but "ran this, got that."

## What's explicitly out of scope here

No book/order/referral/commission endpoints (tables exist, no controllers
yet). No Razorpay. No digital file serving. No hardcopy address endpoint.
No React frontend changes — the mock `SessionContext` keeps working
exactly as today; wiring the frontend to this backend is a later
sub-project once more endpoints exist to wire it to.
