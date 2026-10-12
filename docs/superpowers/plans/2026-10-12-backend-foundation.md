# Backend Foundation & Auth Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up a real PHP + MySQL backend (Docker-based locally) with the complete platform schema and working JWT auth (customer register/login, admin login, `GET /api/me`, `GET /api/health`), proven by actual HTTP requests against the running stack — then wire the existing React frontend's account login to it.

**Architecture:** A minimal front-controller PHP API (`backend/public/index.php` → `Router` → `Controllers`, with `Cors`/`ErrorHandler`/`AuthMiddleware` centralized) behind nginx + PHP-FPM, backed by MySQL — all three as Docker Compose services. No Composer, no framework: hand-rolled HMAC-SHA256 JWT via `hash_hmac`, PDO prepared statements, `password_hash`/`password_verify`.

**Tech Stack:** PHP 8.2-FPM, MySQL 8.0, nginx (alpine), Docker Compose. Frontend: existing React/Vite app, adding a small `fetch`-based API client.

**Spec:** `docs/superpowers/specs/2026-10-08-backend-foundation-design.md`

## Global Constraints

- PHP + MySQL only — no Node.js, MongoDB, Firebase, Laravel, or other framework for the backend.
- No Composer — JWT is hand-rolled with `hash_hmac('sha256', ...)`.
- All secrets (JWT secret, DB credentials, frontend origin) come from `backend/.env` (gitignored), never hardcoded. `backend/.env.example` is checked in.
- Every SQL query uses PDO prepared statements — no string-interpolated SQL anywhere.
- Passwords hashed with `password_hash(..., PASSWORD_DEFAULT)`, verified with `password_verify`.
- CORS allows exactly one origin (`FRONTEND_ORIGIN` from env), never `*`.
- JWT payload: `{sub, role, iat, exp}`. Customer tokens expire in 7 days, admin tokens in 2 hours.
- This plan implements ONLY auth (`register`, `login`, `admin/login`, `me`, `health`). Book/order/referral/commission/admin-CRUD endpoints are later sub-projects — their tables are created now (full schema) but no controllers for them exist yet.
- Do not modify the existing Book Listing UI, referral registration flow, or any other currently-working frontend feature. The only frontend change in scope is wiring real login/register into the existing Account page's login gate.
- Every task's HTTP-facing claim must be verified against the actually-running Docker stack via real `curl` requests — not inferred from reading the code.

## Review Focus

- **Duplicate email on register** — spec implies "409 if email taken" but doesn't dwell on it; a naive implementation might 500 on the unique-constraint violation instead of a clean 409. Task 5's test covers this explicitly.
- **Expired vs. merely-invalid JWT** — the spec says "invalid/expired JWT handling" as one phrase; it's easy to only test garbage tokens and never actually construct a real expired one. Task 8's test suite constructs a genuinely expired token (negative TTL) and checks for `token_expired`, not just a blanket 401.
- **CORS wildcard regression** — a dev-convenience change to `Access-Control-Allow-Origin: *` would pass every other test silently (requests still succeed) while violating the "never wildcard with credentials" constraint. Task 8's CORS test asserts the exact configured origin is echoed back, not merely that CORS headers exist.
- **Admin/customer role confusion** — a customer JWT hitting an admin-only check (or vice versa) should 403, not silently succeed with the wrong profile shape. Task 7's `/api/me` test checks both roles return their own distinct shape, and Task 8 cross-checks an admin token still gets `role: "admin"` back.
- **Frontend wiring breaking existing mock purchase flow** — AccountPage's order list today depends on `SessionContext.customerEmail` being set via the existing `LOGIN_CUSTOMER` mock action. Task 9 must keep dispatching that alongside the new real login, or the existing (already-tested) purchased-books list silently breaks. Task 9's test covers this explicitly.

---

## File Structure

```
docker-compose.yml                          # repo root
backend/
├── Dockerfile                               # php:8.2-fpm + pdo_mysql
├── .env.example                             # checked in
├── .gitignore                               # ignores .env
├── nginx/
│   └── default.conf
├── public/
│   └── index.php                            # front controller
├── config/
│   └── env.php                              # loadEnv()
├── src/
│   ├── routes.php                           # route table, included by index.php
│   ├── Http/
│   │   └── ApiException.php
│   ├── Database/
│   │   └── Db.php
│   ├── Validation/
│   │   └── Validator.php
│   ├── Auth/
│   │   ├── Password.php
│   │   └── Jwt.php
│   ├── Router/
│   │   └── Router.php
│   ├── Middleware/
│   │   ├── Cors.php
│   │   ├── ErrorHandler.php
│   │   └── AuthMiddleware.php
│   └── Controllers/
│       ├── HealthController.php
│       ├── AuthController.php
│       ├── AdminController.php
│       └── MeController.php
├── scripts/
│   ├── seed_admin.php
│   └── make_expired_token.php
├── migrations/
│   └── 001_init.sql
├── tests/
│   ├── jwt_test.php
│   └── http_test.sh
├── TEST_RESULTS.md                          # written by Task 8
└── README.md                                # setup/env/migrations/endpoints docs

src/api/
├── client.js                                # fetch wrapper
└── auth.js                                  # registerCustomer, loginCustomer, fetchMe
src/pages/AccountPage.jsx                    # modified: real login/register gate
.env.example                                 # repo root, adds VITE_API_URL (frontend)
```

---

### Task 1: Docker Compose scaffold (MySQL + PHP-FPM + nginx)

**Files:**
- Create: `docker-compose.yml`, `backend/Dockerfile`, `backend/nginx/default.conf`, `backend/.env.example`, `backend/.gitignore`, `backend/public/index.php` (placeholder), `backend/README.md` (skeleton)
- Modify: repo-root `.gitignore` (add `backend/.env` if not already covered by `backend/.gitignore`)

**Interfaces:**
- Produces: a running 3-container stack (`mysql`, `php`, `nginx`) reachable at `http://localhost:8080`. Later tasks assume this is already up when they add routes.

- [ ] **Step 1: Write `backend/Dockerfile`**

```dockerfile
FROM php:8.2-fpm

RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /var/www/html
```

- [ ] **Step 2: Write `backend/nginx/default.conf`**

```nginx
server {
    listen 80;
    index index.php;
    root /var/www/html/public;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass php:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

- [ ] **Step 3: Write `backend/.env.example`**

```
DB_HOST=mysql
DB_PORT=3306
DB_NAME=vtee
DB_USER=vtee
DB_PASS=changeme
JWT_SECRET=changeme-generate-a-real-random-secret
FRONTEND_ORIGIN=http://localhost:5173
```

- [ ] **Step 4: Write `backend/.gitignore`**

```
.env
```

- [ ] **Step 5: Create `backend/.env` for local dev**

Copy `backend/.env.example` to `backend/.env` unchanged — these are throwaway local-Docker-only credentials (MySQL is only reachable from inside the Compose network), not production secrets.

```bash
cp backend/.env.example backend/.env
```

- [ ] **Step 6: Write `docker-compose.yml`** (repo root)

```yaml
services:
  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: rootpass
      MYSQL_DATABASE: vtee
      MYSQL_USER: vtee
      MYSQL_PASSWORD: changeme
    ports:
      - "3307:3306"
    volumes:
      - vtee_mysql_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-u", "root", "-prootpass"]
      interval: 5s
      timeout: 5s
      retries: 10

  php:
    build:
      context: ./backend
      dockerfile: Dockerfile
    volumes:
      - ./backend:/var/www/html
    env_file:
      - ./backend/.env
    depends_on:
      mysql:
        condition: service_healthy

  nginx:
    image: nginx:alpine
    ports:
      - "8080:80"
    volumes:
      - ./backend:/var/www/html
      - ./backend/nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - php

volumes:
  vtee_mysql_data:
```

- [ ] **Step 7: Write a placeholder `backend/public/index.php`**

```php
<?php
header('Content-Type: application/json');
echo json_encode(['status' => 'scaffold-ok']);
```

- [ ] **Step 8: Write `backend/README.md` skeleton**

```markdown
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
```

- [ ] **Step 9: Bring up the stack and verify**

```bash
docker compose up -d --build
docker compose ps
```

Expected: all three services show `running`/`healthy`.

```bash
curl -s http://localhost:8080/
```

Expected: `{"status":"scaffold-ok"}`

- [ ] **Step 10: Commit**

```bash
git add docker-compose.yml backend/Dockerfile backend/nginx backend/.env.example backend/.gitignore backend/public/index.php backend/README.md
git commit -m "chore: scaffold Docker Compose stack (nginx + php-fpm + mysql)"
```

---

### Task 2: Env loader, DB connection, and full platform schema

**Files:**
- Create: `backend/config/env.php`, `backend/src/Database/Db.php`, `backend/migrations/001_init.sql`
- Modify: `backend/public/index.php` (wire autoloader + env loading), `backend/README.md` (fill in "Migrations" section)

**Interfaces:**
- Produces: `loadEnv(string $path): void` (global function). `App\Database\Db::connection(): PDO` (singleton). An autoloader registered in `index.php` that maps `App\Foo\Bar` → `backend/src/Foo/Bar.php` — every later task's classes rely on this existing.
- Consumes: `backend/.env` (Task 1).

- [ ] **Step 1: Write `backend/config/env.php`**

```php
<?php

function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}
```

- [ ] **Step 2: Write `backend/src/Database/Db.php`**

```php
<?php

namespace App\Database;

use PDO;

class Db
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $host = getenv('DB_HOST');
            $port = getenv('DB_PORT');
            $name = getenv('DB_NAME');
            $user = getenv('DB_USER');
            $pass = getenv('DB_PASS');
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$instance;
    }
}
```

- [ ] **Step 3: Write `backend/migrations/001_init.sql`** (complete platform schema, from the spec verbatim)

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

INSERT INTO settings (`key`, `value`) VALUES
  ('commission_rate_digital', '25'),
  ('commission_rate_hardcopy', '25');
```

- [ ] **Step 4: Apply the migration against the running stack**

```bash
docker compose exec -T mysql mysql -u vtee -pchangeme vtee < backend/migrations/001_init.sql
```

- [ ] **Step 5: Verify the schema landed**

```bash
docker compose exec -T mysql mysql -u vtee -pchangeme vtee -e "SHOW TABLES;"
```

Expected: all 11 tables listed (`admins`, `book_access`, `books`, `commissions`, `hardcopy_addresses`, `orders`, `payments`, `referral_applications`, `referral_mappings`, `referral_partners`, `settings`, `users`).

```bash
docker compose exec -T mysql mysql -u vtee -pchangeme vtee -e "SELECT * FROM settings;"
```

Expected: the two seeded commission-rate rows.

- [ ] **Step 6: Replace `backend/public/index.php`'s placeholder with the autoloader + env bootstrap**

```php
<?php

require_once __DIR__ . '/../config/env.php';
loadEnv(__DIR__ . '/../.env');

spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

header('Content-Type: application/json');

try {
    \App\Database\Db::connection();
    echo json_encode(['status' => 'db-connected']);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'db-connection-failed']);
}
```

- [ ] **Step 7: Verify DB connectivity through the running web stack (not just the CLI)**

```bash
curl -s http://localhost:8080/
```

Expected: `{"status":"db-connected"}`

- [ ] **Step 8: Fill in `backend/README.md`'s "Migrations" section**

```markdown
## Migrations

Schema lives in `backend/migrations/*.sql`, applied in filename order.
Apply the initial schema against the running stack:

\`\`\`bash
docker compose exec -T mysql mysql -u vtee -pchangeme vtee < backend/migrations/001_init.sql
\`\`\`
```

- [ ] **Step 9: Commit**

```bash
git add backend/config backend/src/Database backend/migrations backend/public/index.php backend/README.md
git commit -m "feat: env loader, PDO connection, full platform schema (11 tables)"
```

---

### Task 3: JWT, password hashing, validation, and API exceptions

**Files:**
- Create: `backend/src/Http/ApiException.php`, `backend/src/Validation/Validator.php`, `backend/src/Auth/Password.php`, `backend/src/Auth/Jwt.php`, `backend/tests/jwt_test.php`

**Interfaces:**
- Consumes: the autoloader from Task 2 (classes auto-load from `backend/src/`).
- Produces:
  - `App\Http\ApiException extends \Exception` — constructor `(string $message, int $statusCode, string $errorCode, array $fields = [])`, public properties `$statusCode`, `$errorCode`, `$fields`. Later tasks throw this for every error condition.
  - `App\Validation\Validator::required(array $data, array $fields): void`, `::email(string $email): void`, `::minLength(string $value, int $min, string $field): void` — all throw `ApiException` with code `validation_error` on failure.
  - `App\Auth\Password::hash(string $plain): string`, `::verify(string $plain, string $hash): bool`.
  - `App\Auth\Jwt::encode(array $payload, int $expiresInSeconds): string`, `::decode(string $token): array` — `decode` throws `ApiException` (401, `invalid_token` or `token_expired`) on any failure.

- [ ] **Step 1: Write `backend/src/Http/ApiException.php`**

```php
<?php

namespace App\Http;

use Exception;

class ApiException extends Exception
{
    public int $statusCode;
    public string $errorCode;
    public array $fields;

    public function __construct(string $message, int $statusCode, string $errorCode, array $fields = [])
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->fields = $fields;
    }
}
```

- [ ] **Step 2: Write `backend/src/Validation/Validator.php`**

```php
<?php

namespace App\Validation;

use App\Http\ApiException;

class Validator
{
    public static function required(array $data, array $fields): void
    {
        $errors = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
                $errors[$field] = 'This field is required';
            }
        }
        if ($errors) {
            throw new ApiException('Validation failed', 400, 'validation_error', $errors);
        }
    }

    public static function email(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('Validation failed', 400, 'validation_error', ['email' => 'Invalid email address']);
        }
    }

    public static function minLength(string $value, int $min, string $field): void
    {
        if (strlen($value) < $min) {
            throw new ApiException('Validation failed', 400, 'validation_error', [$field => "Must be at least {$min} characters"]);
        }
    }
}
```

- [ ] **Step 3: Write `backend/src/Auth/Password.php`**

```php
<?php

namespace App\Auth;

class Password
{
    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }
}
```

- [ ] **Step 4: Write `backend/src/Auth/Jwt.php`**

```php
<?php

namespace App\Auth;

use App\Http\ApiException;

class Jwt
{
    public static function encode(array $payload, int $expiresInSeconds): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $expiresInSeconds;

        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));
        $signature = hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", self::secret(), true);
        $signatureEncoded = self::base64UrlEncode($signature);

        return "{$headerEncoded}.{$payloadEncoded}.{$signatureEncoded}";
    }

    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new ApiException('Malformed token', 401, 'invalid_token');
        }
        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        $expectedSignature = self::base64UrlEncode(
            hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", self::secret(), true)
        );

        if (!hash_equals($expectedSignature, $signatureEncoded)) {
            throw new ApiException('Invalid token signature', 401, 'invalid_token');
        }

        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);
        if (!is_array($payload) || !isset($payload['exp'])) {
            throw new ApiException('Malformed token payload', 401, 'invalid_token');
        }

        if (time() >= $payload['exp']) {
            throw new ApiException('Token expired', 401, 'token_expired');
        }

        return $payload;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        $padded = $remainder ? str_pad($data, strlen($data) + 4 - $remainder, '=') : $data;
        return base64_decode(strtr($padded, '-_', '+/'));
    }

    private static function secret(): string
    {
        $secret = getenv('JWT_SECRET');
        if (!$secret) {
            throw new \RuntimeException('JWT_SECRET is not configured');
        }
        return $secret;
    }
}
```

- [ ] **Step 5: Write `backend/tests/jwt_test.php`** (self-contained script, no PHPUnit)

```php
<?php

putenv('JWT_SECRET=test-secret-for-unit-tests');

spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

use App\Auth\Jwt;
use App\Http\ApiException;

$failures = 0;

function assertTrue(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        echo "PASS: {$message}\n";
    } else {
        echo "FAIL: {$message}\n";
        $failures++;
    }
}

$token = Jwt::encode(['sub' => 42, 'role' => 'customer'], 3600);
$payload = Jwt::decode($token);
assertTrue($payload['sub'] === 42, 'decode returns correct sub claim');
assertTrue($payload['role'] === 'customer', 'decode returns correct role claim');

$parts = explode('.', $token);
$tamperedPayload = base64_encode(json_encode(['sub' => 999, 'role' => 'admin', 'iat' => time(), 'exp' => time() + 3600]));
$parts[1] = rtrim(strtr($tamperedPayload, '+/', '-_'), '=');
$tampered = implode('.', $parts);
try {
    Jwt::decode($tampered);
    assertTrue(false, 'tampered token is rejected');
} catch (ApiException $e) {
    assertTrue($e->errorCode === 'invalid_token', 'tampered token is rejected');
}

$expired = Jwt::encode(['sub' => 1, 'role' => 'customer'], -10);
try {
    Jwt::decode($expired);
    assertTrue(false, 'expired token is rejected');
} catch (ApiException $e) {
    assertTrue($e->errorCode === 'token_expired', 'expired token is rejected');
}

if ($failures > 0) {
    echo "\n{$failures} test(s) FAILED.\n";
    exit(1);
}
echo "\nAll JWT tests passed.\n";
```

- [ ] **Step 6: Run the self-test inside the PHP container**

```bash
docker compose exec -T php php tests/jwt_test.php
```

Expected: `All JWT tests passed.` with no `FAIL` lines.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Http backend/src/Validation backend/src/Auth backend/tests/jwt_test.php
git commit -m "feat: JWT (hand-rolled HMAC-SHA256), password hashing, validation, ApiException"
```

---

### Task 4: Router, middleware (CORS/error-handling), and `GET /api/health`

**Files:**
- Create: `backend/src/Router/Router.php`, `backend/src/Middleware/Cors.php`, `backend/src/Middleware/ErrorHandler.php`, `backend/src/Controllers/HealthController.php`, `backend/src/routes.php`
- Modify: `backend/public/index.php` (replace Task 2's inline DB-check with full router wiring)

**Interfaces:**
- Consumes: `App\Http\ApiException` (Task 3), `App\Database\Db` (Task 2).
- Produces: `App\Router\Router` with `->get(string $path, callable $handler)`, `->post(...)`, `->dispatch(string $method, string $path): void`. `App\Middleware\Cors::handle(): void`. `App\Middleware\ErrorHandler::wrap(callable $fn): void`. `backend/src/routes.php` is `require`d from inside `index.php`'s dispatch closure, with `$router` in scope — every later task appends its own route lines to this same file.

- [ ] **Step 1: Write `backend/src/Router/Router.php`**

```php
<?php

namespace App\Router;

class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $this->routes[] = ['method' => $method, 'path' => $path, 'handler' => $handler];
    }

    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            $pattern = '#^' . preg_replace('#\{[a-zA-Z_]+\}#', '([^/]+)', $route['path']) . '$#';
            if (preg_match($pattern, $path, $matches)) {
                array_shift($matches);
                call_user_func_array($route['handler'], $matches);
                return;
            }
        }
        http_response_code(404);
        echo json_encode(['error' => ['code' => 'not_found', 'message' => 'Route not found']]);
    }
}
```

- [ ] **Step 2: Write `backend/src/Middleware/Cors.php`**

```php
<?php

namespace App\Middleware;

class Cors
{
    public static function handle(): void
    {
        $origin = getenv('FRONTEND_ORIGIN') ?: '';
        if ($origin !== '') {
            header("Access-Control-Allow-Origin: {$origin}");
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Vary: Origin');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
```

- [ ] **Step 3: Write `backend/src/Middleware/ErrorHandler.php`**

```php
<?php

namespace App\Middleware;

use App\Http\ApiException;
use Throwable;

class ErrorHandler
{
    public static function wrap(callable $fn): void
    {
        try {
            $fn();
        } catch (ApiException $e) {
            http_response_code($e->statusCode);
            $body = ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
            if ($e->fields) {
                $body['error']['fields'] = $e->fields;
            }
            echo json_encode($body);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => ['code' => 'server_error', 'message' => 'Something went wrong']]);
        }
    }
}
```

- [ ] **Step 4: Write `backend/src/Controllers/HealthController.php`**

```php
<?php

namespace App\Controllers;

use App\Database\Db;

class HealthController
{
    public function check(): void
    {
        Db::connection()->query('SELECT 1');
        echo json_encode(['status' => 'ok']);
    }
}
```

- [ ] **Step 5: Write `backend/src/routes.php`**

```php
<?php

use App\Controllers\HealthController;

$router->get('/api/health', [new HealthController(), 'check']);
```

- [ ] **Step 6: Replace `backend/public/index.php`** with full wiring

```php
<?php

require_once __DIR__ . '/../config/env.php';
loadEnv(__DIR__ . '/../.env');

spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

use App\Middleware\Cors;
use App\Middleware\ErrorHandler;
use App\Router\Router;

header('Content-Type: application/json');

Cors::handle();

ErrorHandler::wrap(function () {
    $router = new Router();
    require __DIR__ . '/../src/routes.php';
    $router->dispatch($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
});
```

- [ ] **Step 7: Verify against the running stack**

```bash
curl -s http://localhost:8080/api/health
```

Expected: `{"status":"ok"}`

```bash
curl -s http://localhost:8080/api/does-not-exist
```

Expected: `{"error":{"code":"not_found","message":"Route not found"}}`

- [ ] **Step 8: Commit**

```bash
git add backend/src/Router backend/src/Middleware backend/src/Controllers/HealthController.php backend/src/routes.php backend/public/index.php
git commit -m "feat: router, CORS/error middleware, GET /api/health wired end-to-end"
```

---

### Task 5: Customer registration and login

**Files:**
- Create: `backend/src/Controllers/AuthController.php`
- Modify: `backend/src/routes.php` (add two routes), `backend/README.md` (document the two endpoints)

**Interfaces:**
- Consumes: `Validator`, `Password`, `Jwt`, `ApiException` (Task 3), `Db` (Task 2), `Router` (Task 4).
- Produces: `POST /api/auth/register`, `POST /api/auth/login`.

- [ ] **Step 1: Write `backend/src/Controllers/AuthController.php`**

```php
<?php

namespace App\Controllers;

use App\Auth\Jwt;
use App\Auth\Password;
use App\Database\Db;
use App\Http\ApiException;
use App\Validation\Validator;

class AuthController
{
    public function register(): void
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        Validator::required($data, ['name', 'email', 'password']);
        Validator::email($data['email']);
        Validator::minLength($data['password'], 8, 'password');

        $db = Db::connection();

        $stmt = $db->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $data['email']]);
        if ($stmt->fetch()) {
            throw new ApiException('Email already registered', 409, 'email_taken');
        }

        $stmt = $db->prepare(
            'INSERT INTO users (name, email, phone, password_hash) VALUES (:name, :email, :phone, :password_hash)'
        );
        $stmt->execute([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password_hash' => Password::hash($data['password']),
        ]);

        $userId = (int)$db->lastInsertId();
        $token = Jwt::encode(['sub' => $userId, 'role' => 'customer'], 7 * 24 * 60 * 60);

        http_response_code(201);
        echo json_encode([
            'token' => $token,
            'user' => ['id' => $userId, 'name' => $data['name'], 'email' => $data['email']],
        ]);
    }

    public function login(): void
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        Validator::required($data, ['email', 'password']);

        $db = Db::connection();
        $stmt = $db->prepare('SELECT id, name, email, password_hash FROM users WHERE email = :email');
        $stmt->execute(['email' => $data['email']]);
        $user = $stmt->fetch();

        if (!$user || !Password::verify($data['password'], $user['password_hash'])) {
            throw new ApiException('Invalid email or password', 401, 'invalid_credentials');
        }

        $token = Jwt::encode(['sub' => (int)$user['id'], 'role' => 'customer'], 7 * 24 * 60 * 60);

        echo json_encode([
            'token' => $token,
            'user' => ['id' => (int)$user['id'], 'name' => $user['name'], 'email' => $user['email']],
        ]);
    }
}
```

- [ ] **Step 2: Add routes to `backend/src/routes.php`**

```php
use App\Controllers\AuthController;

$router->post('/api/auth/register', [new AuthController(), 'register']);
$router->post('/api/auth/login', [new AuthController(), 'login']);
```

- [ ] **Step 3: Verify registration against the running stack**

```bash
curl -s -X POST http://localhost:8080/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Jane Doe","email":"jane@example.com","password":"password123"}'
```

Expected: HTTP 201, JSON with `token` and `user.email == "jane@example.com"`.

- [ ] **Step 4: Verify duplicate-email conflict**

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8080/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Jane Doe","email":"jane@example.com","password":"password123"}'
```

Expected: `409`

- [ ] **Step 5: Verify login**

```bash
curl -s -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"jane@example.com","password":"password123"}'
```

Expected: HTTP 200, JSON with a `token`.

- [ ] **Step 6: Verify wrong password**

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"jane@example.com","password":"wrongpassword"}'
```

Expected: `401`

- [ ] **Step 7: Document in `backend/README.md`'s "API endpoints" section**

```markdown
## API endpoints

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | /api/health | none | DB connectivity check |
| POST | /api/auth/register | none | `{name, email, phone?, password}` → 201 `{token, user}` or 409 if email taken |
| POST | /api/auth/login | none | `{email, password}` → 200 `{token, user}` or 401 |
```

- [ ] **Step 8: Commit**

```bash
git add backend/src/Controllers/AuthController.php backend/src/routes.php backend/README.md
git commit -m "feat: customer register/login endpoints"
```

---

### Task 6: Admin login and admin-seeding script

**Files:**
- Create: `backend/src/Controllers/AdminController.php`, `backend/scripts/seed_admin.php`
- Modify: `backend/src/routes.php`, `backend/README.md`

**Interfaces:**
- Consumes: same as Task 5, plus the `admins` table (Task 2).
- Produces: `POST /api/admin/login`. A CLI script to create the first admin (no admin-creation endpoint exists yet — that's admin-CRUD, a later sub-project).

- [ ] **Step 1: Write `backend/src/Controllers/AdminController.php`**

```php
<?php

namespace App\Controllers;

use App\Auth\Jwt;
use App\Auth\Password;
use App\Database\Db;
use App\Http\ApiException;
use App\Validation\Validator;

class AdminController
{
    public function login(): void
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        Validator::required($data, ['username', 'password']);

        $db = Db::connection();
        $stmt = $db->prepare('SELECT id, username, password_hash FROM admins WHERE username = :username');
        $stmt->execute(['username' => $data['username']]);
        $admin = $stmt->fetch();

        if (!$admin || !Password::verify($data['password'], $admin['password_hash'])) {
            throw new ApiException('Invalid username or password', 401, 'invalid_credentials');
        }

        $token = Jwt::encode(['sub' => (int)$admin['id'], 'role' => 'admin'], 2 * 60 * 60);

        echo json_encode([
            'token' => $token,
            'admin' => ['id' => (int)$admin['id'], 'username' => $admin['username']],
        ]);
    }
}
```

- [ ] **Step 2: Write `backend/scripts/seed_admin.php`**

```php
<?php

require_once __DIR__ . '/../config/env.php';
loadEnv(__DIR__ . '/../.env');

spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

use App\Auth\Password;
use App\Database\Db;

[, $username, $password] = $argv + [null, null, null];

if (!$username || !$password) {
    fwrite(STDERR, "Usage: php scripts/seed_admin.php <username> <password>\n");
    exit(1);
}

$db = Db::connection();
$stmt = $db->prepare('SELECT id FROM admins WHERE username = :username');
$stmt->execute(['username' => $username]);
if ($stmt->fetch()) {
    fwrite(STDERR, "Admin '{$username}' already exists.\n");
    exit(1);
}

$stmt = $db->prepare('INSERT INTO admins (username, password_hash) VALUES (:username, :password_hash)');
$stmt->execute(['username' => $username, 'password_hash' => Password::hash($password)]);

echo "Admin '{$username}' created.\n";
```

- [ ] **Step 3: Add route to `backend/src/routes.php`**

```php
use App\Controllers\AdminController;

$router->post('/api/admin/login', [new AdminController(), 'login']);
```

- [ ] **Step 4: Seed an admin and verify login against the running stack**

```bash
docker compose exec -T php php scripts/seed_admin.php admin adminpass123
```

Expected: `Admin 'admin' created.`

```bash
curl -s -X POST http://localhost:8080/api/admin/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"adminpass123"}'
```

Expected: HTTP 200, JSON with a `token` and `admin.username == "admin"`.

- [ ] **Step 5: Verify wrong admin credentials**

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8080/api/admin/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"wrongpassword"}'
```

Expected: `401`

- [ ] **Step 6: Document in `backend/README.md`**

```markdown
| POST | /api/admin/login | none | `{username, password}` → 200 `{token, admin}` or 401 |

### Creating the first admin

No admin-creation endpoint exists yet (that's a later sub-project). Seed
one via the CLI:

\`\`\`bash
docker compose exec -T php php scripts/seed_admin.php <username> <password>
\`\`\`
```

- [ ] **Step 7: Commit**

```bash
git add backend/src/Controllers/AdminController.php backend/scripts/seed_admin.php backend/src/routes.php backend/README.md
git commit -m "feat: admin login endpoint + seed_admin CLI script"
```

---

### Task 7: Auth middleware and `GET /api/me`

**Files:**
- Create: `backend/src/Middleware/AuthMiddleware.php`, `backend/src/Controllers/MeController.php`
- Modify: `backend/src/routes.php`, `backend/README.md`

**Interfaces:**
- Consumes: `Jwt::decode` (Task 3), `ApiException` (Task 3).
- Produces: `App\Middleware\AuthMiddleware::requireRole(array $allowedRoles): array` — returns the decoded JWT payload (`sub`, `role`) or throws `ApiException` (401 if missing/invalid/expired token, 403 if role not allowed). `GET /api/me`.

- [ ] **Step 1: Write `backend/src/Middleware/AuthMiddleware.php`**

```php
<?php

namespace App\Middleware;

use App\Auth\Jwt;
use App\Http\ApiException;

class AuthMiddleware
{
    public static function requireRole(array $allowedRoles): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/', $header, $matches)) {
            throw new ApiException('Missing or malformed Authorization header', 401, 'unauthorized');
        }

        $payload = Jwt::decode($matches[1]);

        if (!in_array($payload['role'] ?? null, $allowedRoles, true)) {
            throw new ApiException('Insufficient permissions', 403, 'forbidden');
        }

        return $payload;
    }
}
```

- [ ] **Step 2: Write `backend/src/Controllers/MeController.php`**

```php
<?php

namespace App\Controllers;

use App\Database\Db;
use App\Http\ApiException;
use App\Middleware\AuthMiddleware;

class MeController
{
    public function show(): void
    {
        $payload = AuthMiddleware::requireRole(['customer', 'admin']);
        $db = Db::connection();

        if ($payload['role'] === 'admin') {
            $stmt = $db->prepare('SELECT id, username FROM admins WHERE id = :id');
            $stmt->execute(['id' => $payload['sub']]);
            $admin = $stmt->fetch();
            if (!$admin) {
                throw new ApiException('Admin not found', 404, 'not_found');
            }
            echo json_encode(['role' => 'admin', 'id' => (int)$admin['id'], 'username' => $admin['username']]);
            return;
        }

        $stmt = $db->prepare('SELECT id, name, email, phone FROM users WHERE id = :id');
        $stmt->execute(['id' => $payload['sub']]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new ApiException('User not found', 404, 'not_found');
        }
        echo json_encode([
            'role' => 'customer',
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
        ]);
    }
}
```

- [ ] **Step 3: Add route to `backend/src/routes.php`**

```php
use App\Controllers\MeController;

$router->get('/api/me', [new MeController(), 'show']);
```

- [ ] **Step 4: Verify with a real customer token**

```bash
TOKEN=$(curl -s -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"jane@example.com","password":"password123"}' | grep -o '"token":"[^"]*"' | cut -d'"' -f4)

curl -s http://localhost:8080/api/me -H "Authorization: Bearer $TOKEN"
```

Expected: HTTP 200, `{"role":"customer","id":...,"name":"Jane Doe","email":"jane@example.com",...}`.

- [ ] **Step 5: Verify with a real admin token**

```bash
ADMIN_TOKEN=$(curl -s -X POST http://localhost:8080/api/admin/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"adminpass123"}' | grep -o '"token":"[^"]*"' | cut -d'"' -f4)

curl -s http://localhost:8080/api/me -H "Authorization: Bearer $ADMIN_TOKEN"
```

Expected: HTTP 200, `{"role":"admin","id":...,"username":"admin"}`.

- [ ] **Step 6: Verify missing/garbage token**

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/api/me
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/api/me -H "Authorization: Bearer not.a.jwt"
```

Expected: both `401`.

- [ ] **Step 7: Document in `backend/README.md`**

```markdown
| GET | /api/me | Bearer JWT, any role | returns the caller's own profile, shape depends on role |
```

- [ ] **Step 8: Commit**

```bash
git add backend/src/Middleware/AuthMiddleware.php backend/src/Controllers/MeController.php backend/src/routes.php backend/README.md
git commit -m "feat: AuthMiddleware + GET /api/me"
```

---

### Task 8: Full HTTP integration test suite + results documentation

**Files:**
- Create: `backend/scripts/make_expired_token.php`, `backend/tests/http_test.sh`, `backend/TEST_RESULTS.md`
- Modify: `backend/README.md` (point "Test results" section at `TEST_RESULTS.md`)

**Interfaces:**
- Consumes: the entire running stack from Tasks 1–7.
- Produces: `backend/tests/http_test.sh`, a repeatable script any later sub-project can re-run to confirm auth still works before adding more endpoints.

- [ ] **Step 1: Write `backend/scripts/make_expired_token.php`**

```php
<?php

require_once __DIR__ . '/../config/env.php';
loadEnv(__DIR__ . '/../.env');

spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

use App\Auth\Jwt;

echo Jwt::encode(['sub' => 1, 'role' => 'customer'], -10);
```

- [ ] **Step 2: Write `backend/tests/http_test.sh`**

```bash
#!/usr/bin/env bash
set -uo pipefail

BASE_URL="${BASE_URL:-http://localhost:8080}"
PASS=0
FAIL=0

check() {
  local desc="$1"
  local condition="$2"
  if [ "$condition" = "true" ]; then
    echo "PASS: $desc"
    PASS=$((PASS+1))
  else
    echo "FAIL: $desc"
    FAIL=$((FAIL+1))
  fi
}

echo "=== 1. Health check ==="
HEALTH=$(curl -s -o /tmp/health.json -w "%{http_code}" "$BASE_URL/api/health")
check "health returns 200" "$([ "$HEALTH" = "200" ] && echo true || echo false)"
check "health reports ok status" "$(grep -q '"status":"ok"' /tmp/health.json && echo true || echo false)"

echo "=== 2. Customer registration ==="
EMAIL="test-$(date +%s)@example.com"
REG=$(curl -s -o /tmp/register.json -w "%{http_code}" -X POST "$BASE_URL/api/auth/register" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Test User\",\"email\":\"$EMAIL\",\"password\":\"password123\"}")
check "register returns 201" "$([ "$REG" = "201" ] && echo true || echo false)"
TOKEN=$(grep -o '"token":"[^"]*"' /tmp/register.json | cut -d'"' -f4)
check "register returns a token" "$([ -n "$TOKEN" ] && echo true || echo false)"

echo "=== 2b. Duplicate registration ==="
DUP=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/auth/register" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Test User\",\"email\":\"$EMAIL\",\"password\":\"password123\"}")
check "duplicate email returns 409" "$([ "$DUP" = "409" ] && echo true || echo false)"

echo "=== 3. Customer login ==="
LOGIN=$(curl -s -o /tmp/login.json -w "%{http_code}" -X POST "$BASE_URL/api/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"$EMAIL\",\"password\":\"password123\"}")
check "login returns 200" "$([ "$LOGIN" = "200" ] && echo true || echo false)"
LOGIN_TOKEN=$(grep -o '"token":"[^"]*"' /tmp/login.json | cut -d'"' -f4)
check "login returns a token" "$([ -n "$LOGIN_TOKEN" ] && echo true || echo false)"

echo "=== 4. GET /api/me with customer token ==="
ME=$(curl -s -o /tmp/me.json -w "%{http_code}" "$BASE_URL/api/me" -H "Authorization: Bearer $LOGIN_TOKEN")
check "me returns 200" "$([ "$ME" = "200" ] && echo true || echo false)"
check "me returns correct email" "$(grep -q "\"email\":\"$EMAIL\"" /tmp/me.json && echo true || echo false)"

echo "=== 5. Admin login ==="
ADMIN_LOGIN=$(curl -s -o /tmp/admin_login.json -w "%{http_code}" -X POST "$BASE_URL/api/admin/login" \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"adminpass123"}')
check "admin login returns 200" "$([ "$ADMIN_LOGIN" = "200" ] && echo true || echo false)"
ADMIN_TOKEN=$(grep -o '"token":"[^"]*"' /tmp/admin_login.json | cut -d'"' -f4)
check "admin login returns a token" "$([ -n "$ADMIN_TOKEN" ] && echo true || echo false)"

echo "=== 6. Protected route with admin token ==="
ADMIN_ME=$(curl -s -o /tmp/admin_me.json -w "%{http_code}" "$BASE_URL/api/me" -H "Authorization: Bearer $ADMIN_TOKEN")
check "admin me returns 200" "$([ "$ADMIN_ME" = "200" ] && echo true || echo false)"
check "admin me reports admin role" "$(grep -q '"role":"admin"' /tmp/admin_me.json && echo true || echo false)"

echo "=== 7. Invalid / expired JWT handling ==="
NO_TOKEN=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/me")
check "no token returns 401" "$([ "$NO_TOKEN" = "401" ] && echo true || echo false)"

GARBAGE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/api/me" -H "Authorization: Bearer not.a.jwt")
check "garbage token returns 401" "$([ "$GARBAGE" = "401" ] && echo true || echo false)"

EXPIRED_TOKEN=$(docker compose exec -T php php scripts/make_expired_token.php)
EXPIRED=$(curl -s -o /tmp/expired.json -w "%{http_code}" "$BASE_URL/api/me" -H "Authorization: Bearer $EXPIRED_TOKEN")
check "expired token returns 401" "$([ "$EXPIRED" = "401" ] && echo true || echo false)"
check "expired token error code is token_expired" "$(grep -q '"code":"token_expired"' /tmp/expired.json && echo true || echo false)"

echo "=== 8. Invalid credentials ==="
BAD_LOGIN=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"$EMAIL\",\"password\":\"wrongpassword\"}")
check "wrong password returns 401" "$([ "$BAD_LOGIN" = "401" ] && echo true || echo false)"

echo "=== 9. CORS preflight ==="
CORS=$(curl -s -D - -o /dev/null -X OPTIONS "$BASE_URL/api/auth/login" \
  -H "Origin: http://localhost:5173" \
  -H "Access-Control-Request-Method: POST")
check "preflight echoes exact configured origin" "$(echo "$CORS" | grep -qi "access-control-allow-origin: http://localhost:5173" && echo true || echo false)"
check "preflight does not use wildcard origin" "$(echo "$CORS" | grep -qi "access-control-allow-origin: \*" && echo false || echo true)"

echo "=== 10. Database connectivity ==="
check "health check queried a live database (already proven in step 1)" "$([ "$HEALTH" = "200" ] && echo true || echo false)"

echo ""
echo "Results: $PASS passed, $FAIL failed"
if [ "$FAIL" -gt 0 ]; then
  exit 1
fi
```

- [ ] **Step 3: Make it executable and run it against the live stack**

```bash
chmod +x backend/tests/http_test.sh
./backend/tests/http_test.sh
```

Expected: every line `PASS:`, final line `Results: N passed, 0 failed`. If anything fails, fix the underlying code (not the test) and re-run until clean.

- [ ] **Step 4: Capture the real output into `backend/TEST_RESULTS.md`**

Run the script again, redirect its real output into the file (don't hand-write this — it must be the actual captured run):

```bash
echo "# Backend Foundation — Test Results" > backend/TEST_RESULTS.md
echo "" >> backend/TEST_RESULTS.md
echo "Run date: $(date -u +%Y-%m-%dT%H:%M:%SZ)" >> backend/TEST_RESULTS.md
echo "" >> backend/TEST_RESULTS.md
echo '```' >> backend/TEST_RESULTS.md
docker compose exec -T php php tests/jwt_test.php >> backend/TEST_RESULTS.md
echo '' >> backend/TEST_RESULTS.md
./backend/tests/http_test.sh >> backend/TEST_RESULTS.md 2>&1
echo '```' >> backend/TEST_RESULTS.md
```

- [ ] **Step 5: Update `backend/README.md`'s "Test results" section**

```markdown
## Test results

See `backend/TEST_RESULTS.md` for the actual captured output of the last
full test run (`backend/tests/jwt_test.php` + `backend/tests/http_test.sh`).
Re-run both against a live `docker compose up` stack any time to re-verify.
```

- [ ] **Step 6: Commit**

```bash
git add backend/scripts/make_expired_token.php backend/tests/http_test.sh backend/TEST_RESULTS.md backend/README.md
git commit -m "test: full HTTP integration suite against the live backend, capture results"
```

---

### Task 9: Wire the React frontend's Account page to real customer auth

**Files:**
- Create: `src/api/client.js`, `src/api/auth.js`, `src/api/client.test.js`
- Modify: `src/pages/AccountPage.jsx`, `src/pages/AccountPage.test.jsx`, repo-root `.env.example` (add `VITE_API_URL`)

**Interfaces:**
- Consumes: the backend's `POST /api/auth/register`, `POST /api/auth/login` (Task 5), `GET /api/me` (Task 7), running at `http://localhost:8080` in dev.
- Produces: `apiRequest(path, {method, body, token}): Promise<object>` (throws on non-2xx, with `.message` from the backend's `error.message`). `registerCustomer({name, email, password, phone})`, `loginCustomer({email, password})`, `fetchMe(token)`.

This task does NOT remove or alter the existing mock purchase/order flow (`SessionContext`, `CheckoutPage`, `ReaderPage`, book data) — only the login gate at the top of `AccountPage` changes, and it still dispatches the existing `LOGIN_CUSTOMER` mock action on success so the rest of `AccountPage` (and every other page's `customerEmail`-based logic) keeps working exactly as it does today.

- [ ] **Step 1: Write `src/api/client.test.js`**

```js
import { apiRequest } from './client'

function mockFetch(responseBody, ok = true, status = 200) {
  global.fetch = vi.fn(() =>
    Promise.resolve({
      ok,
      status,
      json: () => Promise.resolve(responseBody),
    })
  )
}

afterEach(() => {
  vi.restoreAllMocks()
})

test('apiRequest resolves with parsed JSON on success', async () => {
  mockFetch({ token: 'abc123' })
  const result = await apiRequest('/api/auth/login', { method: 'POST', body: { email: 'a@b.com', password: 'x' } })
  expect(result).toEqual({ token: 'abc123' })
})

test('apiRequest throws the backend error message on failure', async () => {
  mockFetch({ error: { code: 'invalid_credentials', message: 'Invalid email or password' } }, false, 401)
  await expect(apiRequest('/api/auth/login', { method: 'POST', body: {} })).rejects.toThrow('Invalid email or password')
})

test('apiRequest sends Authorization header when token is provided', async () => {
  mockFetch({ role: 'customer' })
  await apiRequest('/api/me', { token: 'my-token' })
  const [, options] = global.fetch.mock.calls[0]
  expect(options.headers.Authorization).toBe('Bearer my-token')
})
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `npx vitest run src/api/client.test.js --exclude ".worktrees/**"`
Expected: FAIL (`src/api/client.js` does not exist yet — "Failed to resolve import" or similar)

- [ ] **Step 3: Write `src/api/client.js`**

```js
const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080'

export async function apiRequest(path, { method = 'GET', body, token } = {}) {
  const headers = { 'Content-Type': 'application/json' }
  if (token) headers.Authorization = `Bearer ${token}`

  const res = await fetch(`${API_BASE_URL}${path}`, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  })

  const data = await res.json().catch(() => ({}))

  if (!res.ok) {
    throw new Error(data?.error?.message || 'Request failed')
  }

  return data
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `npx vitest run src/api/client.test.js --exclude ".worktrees/**"`
Expected: PASS (3/3)

- [ ] **Step 5: Write `src/api/auth.js`**

```js
import { apiRequest } from './client'

export function registerCustomer({ name, email, password, phone }) {
  return apiRequest('/api/auth/register', { method: 'POST', body: { name, email, password, phone } })
}

export function loginCustomer({ email, password }) {
  return apiRequest('/api/auth/login', { method: 'POST', body: { email, password } })
}

export function fetchMe(token) {
  return apiRequest('/api/me', { token })
}
```

- [ ] **Step 6: Read the current `src/pages/AccountPage.jsx` and `src/pages/AccountPage.test.jsx`**

Read both files in full before editing — they've evolved since the original
Phase 1 build (localStorage persistence was added to `SessionContext`
afterward). Confirm the exact current shape of the pre-login gate (the
`if (!state.customerEmail) { ... }` block) and the email input's current
`id`/label before changing anything, so the replacement integrates with
what's actually there rather than a stale assumption.

- [ ] **Step 7: Replace the pre-login gate in `AccountPage.jsx`** with real register/login, keeping the rest of the component (the paid-orders list and everything below the gate) untouched

```jsx
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useSession } from '../context/SessionContext'
import { registerCustomer, loginCustomer } from '../api/auth'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

function AccountPage() {
  const { state, dispatch } = useSession()
  const [mode, setMode] = useState('login') // 'login' | 'register'
  const [form, setForm] = useState({ name: '', email: '', password: '' })
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const update = (field) => (e) => setForm(f => ({ ...f, [field]: e.target.value }))

  const handleSubmit = async (e) => {
    e.preventDefault()
    setError('')
    setSubmitting(true)
    try {
      const action = mode === 'login' ? loginCustomer : registerCustomer
      const result = await action(form)
      localStorage.setItem('vtee-auth-token', result.token)
      // Keep the existing mock purchase/order flow working unchanged —
      // AccountPage's order list and every other page's purchased-gate
      // still key off SessionContext.customerEmail, not the real backend.
      dispatch({ type: 'LOGIN_CUSTOMER', payload: { email: result.user.email } })
    } catch (err) {
      setError(err.message)
    } finally {
      setSubmitting(false)
    }
  }

  if (!state.customerEmail) {
    return (
      <>
        <Header />
        <div className="container">
          <h2>{mode === 'login' ? 'Log In' : 'Create Account'}</h2>
          {error && <p className="form-error">{error}</p>}
          <form onSubmit={handleSubmit}>
            {mode === 'register' && (
              <>
                <label htmlFor="account-name">Name</label>
                <input id="account-name" className="form-control" value={form.name} onChange={update('name')} required />
              </>
            )}
            <label htmlFor="account-email">Email</label>
            <input id="account-email" type="email" className="form-control" value={form.email} onChange={update('email')} required />
            <label htmlFor="account-password">Password</label>
            <input id="account-password" type="password" className="form-control" value={form.password} onChange={update('password')} required minLength={8} />
            <button type="submit" className="btn btn-primary" disabled={submitting}>
              {mode === 'login' ? 'Log In' : 'Create Account'}
            </button>
          </form>
          <button type="button" className="btn btn-outline" onClick={() => setMode(m => (m === 'login' ? 'register' : 'login'))}>
            {mode === 'login' ? 'Need an account? Register' : 'Already have an account? Log In'}
          </button>
        </div>
        <Footer />
      </>
    )
  }

  const myPaidOrders = state.orders.filter(o => o.customerEmail === state.customerEmail && o.paymentStatus === 'paid')

  return (
    <>
      <Header />
      <div className="container">
        <h2>My Books</h2>
        {myPaidOrders.length === 0 && <p>No purchased books yet.</p>}
        <ul>
          {myPaidOrders.map(order => {
            const book = state.books.find(b => b.id === order.bookId)
            return (
              <li key={order.id}>
                {book?.title} ({order.purchaseType})
                {order.purchaseType === 'digital' && <Link to={`/read/${order.bookId}`}> Read</Link>}
              </li>
            )
          })}
        </ul>
      </div>
      <Footer />
    </>
  )
}

export default AccountPage
```

(The post-login order-list portion above is copied from the existing
component as a reference shape — if Step 6's reading of the real current
file shows it differs even slightly, e.g. additional markup added since,
keep that part exactly as it already is; only the pre-login gate is being
replaced.)

- [ ] **Step 8: Update `src/pages/AccountPage.test.jsx`**

Read the existing test file (it currently fills the mock email-only gate
and asserts on `"View My Books"` or similar — confirm the exact prior
button text/label before editing). Replace the login-gate test with one
that mocks `src/api/auth.js`:

```jsx
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { vi } from 'vitest'
import { SessionProvider } from '../context/SessionContext'
import AccountPage from './AccountPage'
import * as authApi from '../api/auth'

test('logging in with real backend credentials sets customerEmail and shows the order list', async () => {
  vi.spyOn(authApi, 'loginCustomer').mockResolvedValue({
    token: 'fake-token',
    user: { id: 1, name: 'Jane', email: 'jane@example.com' },
  })

  render(
    <SessionProvider>
      <MemoryRouter><AccountPage /></MemoryRouter>
    </SessionProvider>
  )

  fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'jane@example.com' } })
  fireEvent.change(screen.getByLabelText(/password/i), { target: { value: 'password123' } })
  fireEvent.click(screen.getByRole('button', { name: /log in/i }))

  await waitFor(() => {
    expect(screen.getByText(/no purchased books yet/i)).toBeInTheDocument()
  })
})

test('shows the backend error message on failed login', async () => {
  vi.spyOn(authApi, 'loginCustomer').mockRejectedValue(new Error('Invalid email or password'))

  render(
    <SessionProvider>
      <MemoryRouter><AccountPage /></MemoryRouter>
    </SessionProvider>
  )

  fireEvent.change(screen.getByLabelText(/email/i), { target: { value: 'jane@example.com' } })
  fireEvent.change(screen.getByLabelText(/password/i), { target: { value: 'wrong' } })
  fireEvent.click(screen.getByRole('button', { name: /log in/i }))

  await waitFor(() => {
    expect(screen.getByText(/invalid email or password/i)).toBeInTheDocument()
  })
})
```

- [ ] **Step 9: Add `VITE_API_URL` to the repo-root `.env.example`**

Create it if it doesn't exist yet:

```
VITE_API_URL=http://localhost:8080
```

- [ ] **Step 10: Run the full frontend test suite**

Run: `npx vitest run --exclude ".worktrees/**" --exclude "node_modules/**"`
Expected: all tests pass, including the new `client.test.js` and the updated `AccountPage.test.jsx`.

- [ ] **Step 11: Manual verification against the real running backend**

With `docker compose up -d` still running (Tasks 1–8) and `npm run dev`
running:
1. Open `/account` in a browser.
2. Register a new account with a real email/password — confirm it
   succeeds and the page shows "No purchased books yet."
3. Reload the page — confirm `localStorage['vtee-auth-token']` is set
   (devtools) and the existing mock purchase flow (checkout a book,
   return to `/account`) still lists it, proving `LOGIN_CUSTOMER` is still
   being dispatched correctly alongside the real backend call.
4. Try logging in with a wrong password — confirm the backend's actual
   error message appears on the page, not a generic/fake one.

- [ ] **Step 12: Confirm the production build still succeeds**

Run: `npm run build`
Expected: succeeds, no errors.

- [ ] **Step 13: Commit**

```bash
git add src/api src/pages/AccountPage.jsx src/pages/AccountPage.test.jsx .env.example
git commit -m "feat: wire AccountPage login/register to the real backend"
```

---

### Task 10: Final verification — full stack, full suite, documented results

**Files:** none (verification only, no code changes)

- [ ] **Step 1: Confirm the full Docker stack is healthy from a cold start**

```bash
docker compose down
docker compose up -d --build
docker compose ps
```

Expected: all three services `running`/`healthy`.

- [ ] **Step 2: Re-apply migrations and re-seed (fresh volume if `down -v` was used, otherwise skip if data persisted)**

If the MySQL volume was wiped, re-run Task 2 Step 4 and Task 6 Step 4.
Otherwise confirm existing data is intact:

```bash
docker compose exec -T mysql mysql -u vtee -pchangeme vtee -e "SELECT COUNT(*) FROM admins; SELECT COUNT(*) FROM users;"
```

- [ ] **Step 3: Re-run the full backend test suite and confirm it's still clean**

```bash
docker compose exec -T php php tests/jwt_test.php
./backend/tests/http_test.sh
```

Expected: both clean, `0 failed`.

- [ ] **Step 4: Re-run the full frontend test suite and build**

```bash
npm test
npm run build
```

Expected: all tests pass, build succeeds.

- [ ] **Step 5: Report**

Summarize for the user: what's actually running and verified (backend
stack up, schema applied, all 5 auth endpoints tested against real HTTP
requests, frontend wired and tested) versus what's still mock (books,
orders, referrals, admin CRUD — unchanged, still in-memory, per the
explicit non-goals). Point to `backend/TEST_RESULTS.md` and
`backend/README.md` as the durable record. Do not describe anything as
"working" that wasn't exercised by Task 8's or Task 9's actual runs.
