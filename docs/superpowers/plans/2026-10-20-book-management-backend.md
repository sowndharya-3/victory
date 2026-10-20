# Book Management Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Real book CRUD + file-upload APIs (cover, index, 1–2 preview pages, private complete PDF) against the live PHP+MySQL backend, with both the public `BooksPage` and the admin `AdminBooksPage`/`AdminLoginPage` wired to it — proven end-to-end through the real database, not just by each side's own tests passing in isolation.

**Architecture:** Two new backend controllers (`BookController` for the two public read-only endpoints, `AdminBookController` for everything admin-authenticated — CRUD, status transitions, file uploads) following the exact patterns sub-project 2 established (`AuthMiddleware::requireRole`, `ApiException`, PDO prepared statements, the existing `Router`/`routes.php` convention). A new `FileValidator` helper does MIME-sniffed validation for all upload endpoints. Public assets (cover/index/preview images) are stored under `backend/public/uploads/` and served directly by nginx's existing `try_files`; the complete PDF is stored under `backend/storage/` — outside the docroot, unreachable by any route in this plan.

**Tech Stack:** Same as sub-project 2 — PHP 8.2 (bullseye), MySQL 8.0, nginx, Docker Compose, no Composer. Frontend: existing React/Vite app.

**Spec:** `docs/superpowers/specs/2026-10-19-book-management-backend-design.md`

## Global Constraints

- No Composer, no framework — same hand-rolled conventions as sub-project 2.
- Every query uses PDO prepared statements.
- Every admin endpoint requires `AuthMiddleware::requireRole(['admin'])` as the first line of the method — before any DB lookup, so an unauthenticated caller never learns whether a given book id exists.
- Uploaded file MIME types are sniffed with `finfo`, never trusted from the client's `Content-Type` header or filename. Stored filenames are always server-generated (`cover.<ext>`, `index.<ext>`, `preview-{n}.<ext>`, `book.pdf`), never the client's original filename.
- The complete book PDF (`digital_file_path`) is never returned by any endpoint in this plan, public or admin-summary — only `hasPdf: true/false` in the admin detail view.
- Archive, not hard delete — no endpoint in this plan permanently removes a `books` row.
- Public endpoints (`GET /api/books`, `GET /api/books/:id`) only ever return `published=1 AND archived=0` rows.
- `AdminBooksPage`/`AdminLoginPage`'s existing visual structure (table layout, form fields, button labels) is preserved — only their data source changes from mock `SessionContext` dispatch to real API calls, per spec.
- `BookDetailPage.jsx` is explicitly NOT touched in this plan (deferred to sub-project 5).

## Review Focus

- **Public endpoint leaking a restricted field** — the single highest-consequence mistake in this plan is `GET /api/books` or `GET /api/books/:id` accidentally including `digital_file_path` (or any path revealing where the private PDF lives) in its JSON response. Task 3's test explicitly asserts its absence by string-matching the raw response body, not just checking the expected fields are present.
- **Archived book still visible publicly** — `archived=1` must hide a book from both public endpoints even when `published=1` (e.g., a book archived without being unpublished first). Task 10's integration suite exercises this exact ordering.
- **Upload endpoint accepting a disguised file** — a `.jpg`-named file containing PHP or an oversized file with a spoofed small `Content-Length` must be rejected on real sniffed content/size, not the client-supplied metadata. Task 2's `FileValidator` is unit-tested against a real PDF renamed to `.jpg` and a file exceeding the byte cap.
- **Customer-role JWT reaching an admin endpoint** — every new admin endpoint must reject a valid-but-wrong-role token with 403, not merely require "a token." Task 10's suite checks this against at least the create and upload endpoints, not just one.
- **Admin UI silently desyncing from the database** — a stale `AdminBooksPage` list (e.g., after a publish action, the row doesn't visibly flip to "Published" without a manual reload) would mean the admin can't trust what they're looking at. Task 13 reloads the full book list after every mutation specifically to avoid this, and Task 14's end-to-end check proves it by reading the public page's actual rendered output after each admin action, not just the admin page's own state.

---

## File Structure

```
backend/
├── migrations/
│   └── 002_book_management.sql
├── src/
│   ├── Router/Router.php                  # modified: add put(), delete()
│   ├── Upload/
│   │   └── FileValidator.php              # new
│   ├── Controllers/
│   │   ├── BookController.php             # new: public list/show
│   │   └── AdminBookController.php        # new: admin CRUD + uploads
│   └── routes.php                         # modified: new routes
├── public/uploads/books/{id}/             # created at runtime by upload endpoints
├── storage/books/{id}/                    # created at runtime, private
├── tests/
│   ├── upload_validator_test.php          # new
│   └── book_test.sh                       # new
└── README.md                              # modified: new endpoints documented

src/
├── api/
│   ├── client.js                          # modified: add apiRequestFile
│   ├── auth.js                            # modified: add loginAdmin
│   ├── books.js                           # new
│   └── books.test.js                      # new
└── pages/
    ├── BooksPage.jsx                      # modified: real API instead of mock
    ├── BooksPage.test.jsx                 # modified
    └── admin/
        ├── AdminLoginPage.jsx             # modified: real API instead of mock
        ├── AdminLayout.test.jsx           # modified
        ├── AdminBooksPage.jsx             # modified: real API instead of mock
        └── AdminBooksPage.test.jsx        # modified
```

---

### Task 1: Schema migration — book management columns and preview-pages table

**Files:**
- Create: `backend/migrations/002_book_management.sql`

**Interfaces:**
- Produces: `books.index_file_path` (nullable varchar), `books.archived` (tinyint, default 0), and a new `book_preview_pages` table (`id`, `book_id` FK, `page_number`, `file_path`, unique on `(book_id, page_number)`) — every later task's SQL assumes these exist.

- [ ] **Step 1: Write the migration**

```sql
ALTER TABLE books
  ADD COLUMN index_file_path VARCHAR(500) NULL
    COMMENT 'publicly viewable index/table-of-contents document',
  ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE book_preview_pages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  book_id INT NOT NULL,
  page_number INT NOT NULL,
  file_path VARCHAR(500) NOT NULL COMMENT 'publicly viewable preview image',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_book_page (book_id, page_number),
  FOREIGN KEY (book_id) REFERENCES books(id)
);
```

- [ ] **Step 2: Bring up the Docker stack if it isn't already running**

```bash
docker compose up -d
```

Expected: all 3 services running/healthy (`docker compose ps`).

- [ ] **Step 3: Apply the migration against the running stack**

```bash
docker compose exec -T mysql mysql -u vtee -pchangeme vtee < backend/migrations/002_book_management.sql
```

- [ ] **Step 4: Verify**

```bash
docker compose exec -T mysql mysql -u vtee -pchangeme vtee -e "DESCRIBE books;"
docker compose exec -T mysql mysql -u vtee -pchangeme vtee -e "SHOW TABLES LIKE 'book_preview_pages';"
```

Expected: `books` shows `index_file_path` and `archived` columns; `book_preview_pages` is listed.

- [ ] **Step 5: Commit**

```bash
git add backend/migrations/002_book_management.sql
git commit -m "feat: add book_preview_pages table and books.index_file_path/archived columns"
```

---

### Task 2: Router PUT/DELETE support + FileValidator

**Files:**
- Modify: `backend/src/Router/Router.php`
- Create: `backend/src/Upload/FileValidator.php`, `backend/tests/upload_validator_test.php`

**Interfaces:**
- Consumes: `App\Http\ApiException` (sub-project 2).
- Produces: `Router::put(string $path, callable $handler): void`, `Router::delete(...)`. `App\Upload\FileValidator::validateImage(array $file): string` (returns the extension: `jpg`/`png`/`webp`), `::validateImageOrPdf(array $file): string` (adds `pdf`), `::validatePdf(array $file): string` — all throw `ApiException(..., 400, 'invalid_file')` on any failure. Every later upload endpoint calls one of these before touching the filesystem.

- [ ] **Step 1: Add `put`/`delete` to `backend/src/Router/Router.php`**

Add alongside the existing `get`/`post` methods:

```php
public function put(string $path, callable $handler): void
{
    $this->add('PUT', $path, $handler);
}

public function delete(string $path, callable $handler): void
{
    $this->add('DELETE', $path, $handler);
}
```

- [ ] **Step 2: Write `backend/src/Upload/FileValidator.php`**

```php
<?php

namespace App\Upload;

use App\Http\ApiException;

class FileValidator
{
    private const IMAGE_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const IMAGE_MAX_BYTES = 5 * 1024 * 1024;
    private const PDF_MAX_BYTES = 50 * 1024 * 1024;

    public static function validateImage(array $file): string
    {
        self::assertUploadOk($file);
        if ($file['size'] > self::IMAGE_MAX_BYTES) {
            throw new ApiException('File too large (max 5MB)', 400, 'invalid_file');
        }
        $mime = self::detectMime($file['tmp_name']);
        if (!isset(self::IMAGE_MIMES[$mime])) {
            throw new ApiException('File must be JPEG, PNG, or WEBP', 400, 'invalid_file');
        }
        return self::IMAGE_MIMES[$mime];
    }

    public static function validateImageOrPdf(array $file): string
    {
        self::assertUploadOk($file);
        $mime = self::detectMime($file['tmp_name']);
        if ($mime === 'application/pdf') {
            if ($file['size'] > self::PDF_MAX_BYTES) {
                throw new ApiException('File too large (max 50MB)', 400, 'invalid_file');
            }
            return 'pdf';
        }
        if (isset(self::IMAGE_MIMES[$mime])) {
            if ($file['size'] > self::IMAGE_MAX_BYTES) {
                throw new ApiException('File too large (max 5MB)', 400, 'invalid_file');
            }
            return self::IMAGE_MIMES[$mime];
        }
        throw new ApiException('File must be JPEG, PNG, WEBP, or PDF', 400, 'invalid_file');
    }

    public static function validatePdf(array $file): string
    {
        self::assertUploadOk($file);
        if ($file['size'] > self::PDF_MAX_BYTES) {
            throw new ApiException('File too large (max 50MB)', 400, 'invalid_file');
        }
        $mime = self::detectMime($file['tmp_name']);
        if ($mime !== 'application/pdf') {
            throw new ApiException('File must be a PDF', 400, 'invalid_file');
        }
        return 'pdf';
    }

    private static function assertUploadOk(array $file): void
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new ApiException('No file uploaded', 400, 'invalid_file');
        }
    }

    private static function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);
        return $mime ?: 'application/octet-stream';
    }
}
```

- [ ] **Step 3: Write `backend/tests/upload_validator_test.php`**

`is_uploaded_file()` only returns true for files that arrived via a real HTTP upload, so this test fakes that check by calling the MIME/size logic directly rather than through `assertUploadOk` — it creates real temp files with real (and disguised) content and verifies the detection logic, which is what Review Focus is actually concerned with. `is_uploaded_file`'s own gate is exercised for real in Task 10's HTTP suite (a non-multipart POST naturally fails it).

```php
<?php

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

// Real-content MIME detection (bypassing the is_uploaded_file() gate, which
// only real HTTP uploads satisfy — tested over real HTTP in Task 10).
function detectMimeDirect(string $path): string
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $path);
    finfo_close($finfo);
    return $mime ?: 'application/octet-stream';
}

$realJpeg = __DIR__ . '/fixtures/test.jpg';
$realPdf = __DIR__ . '/fixtures/test.pdf';
$disguisedPdf = __DIR__ . '/fixtures/disguised.jpg'; // PDF bytes, .jpg name

assertTrue(detectMimeDirect($realJpeg) === 'image/jpeg', 'real JPEG sniffs as image/jpeg');
assertTrue(detectMimeDirect($realPdf) === 'application/pdf', 'real PDF sniffs as application/pdf');
assertTrue(detectMimeDirect($disguisedPdf) === 'application/pdf', 'PDF content renamed to .jpg still sniffs as application/pdf, not trusted by extension');

if ($failures > 0) {
    echo "\n{$failures} test(s) FAILED.\n";
    exit(1);
}
echo "\nAll upload validator tests passed.\n";
```

- [ ] **Step 4: Create the test fixture files**

```bash
mkdir -p backend/tests/fixtures
```

Create a minimal valid JPEG (`backend/tests/fixtures/test.jpg`) and a minimal valid PDF (`backend/tests/fixtures/test.pdf`) — e.g. via PHP's GD for the JPEG and a hand-written minimal PDF for the PDF:

```bash
docker compose exec -T php php -r "
imagejpeg(imagecreatetruecolor(10, 10), '/var/www/html/tests/fixtures/test.jpg');
file_put_contents('/var/www/html/tests/fixtures/test.pdf', \"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\");
copy('/var/www/html/tests/fixtures/test.pdf', '/var/www/html/tests/fixtures/disguised.jpg');
"
```

- [ ] **Step 5: Run the test**

```bash
docker compose exec -T php php tests/upload_validator_test.php
```

Expected: all 3 `PASS` lines, `All upload validator tests passed.`

- [ ] **Step 6: Commit**

```bash
git add backend/src/Router/Router.php backend/src/Upload/FileValidator.php backend/tests/upload_validator_test.php backend/tests/fixtures
git commit -m "feat: Router PUT/DELETE support, FileValidator (MIME-sniffed upload validation)"
```

---

### Task 3: Public book endpoints

**Files:**
- Create: `backend/src/Controllers/BookController.php`
- Modify: `backend/src/routes.php`, `backend/README.md`

**Interfaces:**
- Consumes: `App\Database\Db` (sub-project 2).
- Produces: `GET /api/books`, `GET /api/books/:id`.

- [ ] **Step 1: Write `backend/src/Controllers/BookController.php`**

```php
<?php

namespace App\Controllers;

use App\Database\Db;
use App\Http\ApiException;

class BookController
{
    public function list(): void
    {
        $db = Db::connection();
        $stmt = $db->query(
            'SELECT id, title, description, category, cover_url, online_price, hardcopy_price
             FROM books WHERE published = 1 AND archived = 0 ORDER BY created_at DESC'
        );
        echo json_encode(array_map([self::class, 'formatSummary'], $stmt->fetchAll()));
    }

    public function show(string $id): void
    {
        $db = Db::connection();
        $stmt = $db->prepare(
            'SELECT id, title, description, category, cover_url, online_price, hardcopy_price, index_file_path
             FROM books WHERE id = :id AND published = 1 AND archived = 0'
        );
        $stmt->execute(['id' => $id]);
        $book = $stmt->fetch();
        if (!$book) {
            throw new ApiException('Book not found', 404, 'not_found');
        }

        $previewStmt = $db->prepare(
            'SELECT page_number, file_path FROM book_preview_pages WHERE book_id = :id ORDER BY page_number'
        );
        $previewStmt->execute(['id' => $id]);
        $previewPages = array_map(
            fn($row) => ['pageNumber' => (int)$row['page_number'], 'url' => $row['file_path']],
            $previewStmt->fetchAll()
        );

        echo json_encode([
            'id' => (int)$book['id'],
            'title' => $book['title'],
            'description' => $book['description'],
            'category' => $book['category'],
            'coverUrl' => $book['cover_url'],
            'onlinePrice' => (float)$book['online_price'],
            'hardcopyPrice' => (float)$book['hardcopy_price'],
            'indexUrl' => $book['index_file_path'],
            'previewPages' => $previewPages,
        ]);
    }

    private static function formatSummary(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'category' => $row['category'],
            'coverUrl' => $row['cover_url'],
            'onlinePrice' => (float)$row['online_price'],
            'hardcopyPrice' => (float)$row['hardcopy_price'],
        ];
    }
}
```

- [ ] **Step 2: Add routes to `backend/src/routes.php`**

```php
use App\Controllers\BookController;

$router->get('/api/books', [new BookController(), 'list']);
$router->get('/api/books/{id}', [new BookController(), 'show']);
```

- [ ] **Step 3: Verify against the running stack**

```bash
curl -s http://localhost:8080/api/books
```

Expected: `[]` (no books exist yet).

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/api/books/999
```

Expected: `404`.

- [ ] **Step 4: Verify the response never contains the private PDF path, by construction**

Confirm by reading the code above: neither `formatSummary` nor `show`'s `SELECT` includes `digital_file_path`. A real "does a published book with a PDF leak it" check happens in Task 10 once books with uploaded PDFs exist.

- [ ] **Step 5: Document in `backend/README.md`**

```markdown
| GET | /api/books | none | Published, non-archived books. `[{id,title,description,category,coverUrl,onlinePrice,hardcopyPrice}]` |
| GET | /api/books/:id | none | One book + `indexUrl`, `previewPages`. 404 if missing/unpublished/archived |
```

- [ ] **Step 6: Commit**

```bash
git add backend/src/Controllers/BookController.php backend/src/routes.php backend/README.md
git commit -m "feat: public GET /api/books and GET /api/books/:id"
```

---

### Task 4: Admin book CRUD (create, list, show, update)

**Files:**
- Create: `backend/src/Controllers/AdminBookController.php`
- Modify: `backend/src/routes.php`, `backend/README.md`

**Interfaces:**
- Consumes: `AuthMiddleware::requireRole` (sub-project 2), `Validator::required` (sub-project 2).
- Produces: `GET /api/admin/books`, `GET /api/admin/books/:id`, `POST /api/admin/books`, `PUT /api/admin/books/:id`. `AdminBookController::findOrFail(string $id): array` and `::formatAdmin(array $row): array` are used by every later task's methods added to this same class.

- [ ] **Step 1: Write `backend/src/Controllers/AdminBookController.php`**

```php
<?php

namespace App\Controllers;

use App\Database\Db;
use App\Http\ApiException;
use App\Middleware\AuthMiddleware;
use App\Validation\Validator;

class AdminBookController
{
    public function list(): void
    {
        AuthMiddleware::requireRole(['admin']);
        $db = Db::connection();
        $stmt = $db->query(
            'SELECT b.*, (SELECT COUNT(*) FROM book_preview_pages p WHERE p.book_id = b.id) AS preview_page_count
             FROM books b ORDER BY b.created_at DESC'
        );
        echo json_encode(array_map([self::class, 'formatAdmin'], $stmt->fetchAll()));
    }

    public function show(string $id): void
    {
        AuthMiddleware::requireRole(['admin']);
        echo json_encode(self::formatAdmin($this->findOrFail($id)));
    }

    public function create(): void
    {
        AuthMiddleware::requireRole(['admin']);
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        Validator::required($data, ['title', 'onlinePrice', 'hardcopyPrice']);

        $db = Db::connection();
        $stmt = $db->prepare(
            'INSERT INTO books (title, description, category, online_price, hardcopy_price, published, archived)
             VALUES (:title, :description, :category, :online_price, :hardcopy_price, 0, 0)'
        );
        $stmt->execute([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'online_price' => $data['onlinePrice'],
            'hardcopy_price' => $data['hardcopyPrice'],
        ]);

        $id = (string)$db->lastInsertId();
        http_response_code(201);
        echo json_encode(self::formatAdmin($this->findOrFail($id)));
    }

    public function update(string $id): void
    {
        AuthMiddleware::requireRole(['admin']);
        $this->findOrFail($id);
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $fields = [];
        $params = ['id' => $id];
        foreach ([
            'title' => 'title', 'description' => 'description', 'category' => 'category',
            'onlinePrice' => 'online_price', 'hardcopyPrice' => 'hardcopy_price',
        ] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$column} = :{$column}";
                $params[$column] = $data[$key];
            }
        }
        if ($fields) {
            Db::connection()->prepare('UPDATE books SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
        }

        echo json_encode(self::formatAdmin($this->findOrFail($id)));
    }

    protected function findOrFail(string $id): array
    {
        $stmt = Db::connection()->prepare('SELECT * FROM books WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $book = $stmt->fetch();
        if (!$book) {
            throw new ApiException('Book not found', 404, 'not_found');
        }
        return $book;
    }

    protected static function formatAdmin(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'category' => $row['category'],
            'onlinePrice' => (float)$row['online_price'],
            'hardcopyPrice' => (float)$row['hardcopy_price'],
            'published' => (bool)$row['published'],
            'archived' => (bool)$row['archived'],
            'hasCover' => $row['cover_url'] !== null,
            'hasIndex' => $row['index_file_path'] !== null,
            'hasPdf' => $row['digital_file_path'] !== null,
            'previewPageCount' => isset($row['preview_page_count']) ? (int)$row['preview_page_count'] : null,
        ];
    }
}
```

- [ ] **Step 2: Add routes to `backend/src/routes.php`**

```php
use App\Controllers\AdminBookController;

$adminBooks = new AdminBookController();
$router->get('/api/admin/books', [$adminBooks, 'list']);
$router->get('/api/admin/books/{id}', [$adminBooks, 'show']);
$router->post('/api/admin/books', [$adminBooks, 'create']);
$router->put('/api/admin/books/{id}', [$adminBooks, 'update']);
```

- [ ] **Step 3: Seed an admin token and verify**

```bash
ADMIN_TOKEN=$(curl -s -X POST http://localhost:8080/api/admin/login -H "Content-Type: application/json" -d '{"username":"admin","password":"adminpass123"}' | grep -o '"token":"[^"]*"' | cut -d'"' -f4)

curl -s -X POST http://localhost:8080/api/admin/books -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"title":"Test Book","onlinePrice":199,"hardcopyPrice":349}'
```

Expected: HTTP 201 (check with `-w "%{http_code}"` if not visually obvious), JSON with `published: false, archived: false, hasCover: false, hasPdf: false`.

```bash
BOOK_ID=1  # use the id from the response above
curl -s -X PUT "http://localhost:8080/api/admin/books/$BOOK_ID" -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"title":"Updated Title"}'
```

Expected: `title: "Updated Title"` in the response.

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/api/admin/books
```

Expected: `401` (no token).

- [ ] **Step 4: Document in `backend/README.md`**

```markdown
| GET | /api/admin/books | admin | All books, any status, with `hasCover/hasIndex/hasPdf/previewPageCount` |
| GET | /api/admin/books/:id | admin | One book, any status |
| POST | /api/admin/books | admin | `{title, description?, category?, onlinePrice, hardcopyPrice}` → 201 |
| PUT | /api/admin/books/:id | admin | Partial update of any metadata field |
```

- [ ] **Step 5: Commit**

```bash
git add backend/src/Controllers/AdminBookController.php backend/src/routes.php backend/README.md
git commit -m "feat: admin book CRUD (create/list/show/update)"
```

---

### Task 5: Publish / unpublish / archive

**Files:**
- Modify: `backend/src/Controllers/AdminBookController.php`, `backend/src/routes.php`, `backend/README.md`

**Interfaces:**
- Consumes: `findOrFail`/`formatAdmin` (Task 4).
- Produces: `POST /api/admin/books/:id/publish`, `.../unpublish`, `.../archive`.

- [ ] **Step 1: Add methods to `AdminBookController`**

```php
    public function publish(string $id): void
    {
        $this->setStatus($id, ['published' => 1]);
    }

    public function unpublish(string $id): void
    {
        $this->setStatus($id, ['published' => 0]);
    }

    public function archive(string $id): void
    {
        $this->setStatus($id, ['archived' => 1, 'published' => 0]);
    }

    private function setStatus(string $id, array $fields): void
    {
        AuthMiddleware::requireRole(['admin']);
        $this->findOrFail($id);
        $set = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($fields)));
        Db::connection()->prepare("UPDATE books SET {$set} WHERE id = :id")->execute($fields + ['id' => $id]);
        echo json_encode(self::formatAdmin($this->findOrFail($id)));
    }
```

- [ ] **Step 2: Add routes**

```php
$router->post('/api/admin/books/{id}/publish', [$adminBooks, 'publish']);
$router->post('/api/admin/books/{id}/unpublish', [$adminBooks, 'unpublish']);
$router->post('/api/admin/books/{id}/archive', [$adminBooks, 'archive']);
```

- [ ] **Step 3: Verify against the running stack**

```bash
curl -s -X POST "http://localhost:8080/api/admin/books/$BOOK_ID/publish" -H "Authorization: Bearer $ADMIN_TOKEN"
curl -s http://localhost:8080/api/books
```

Expected: the publish response shows `published: true`; the public list now includes the book.

```bash
curl -s -X POST "http://localhost:8080/api/admin/books/$BOOK_ID/archive" -H "Authorization: Bearer $ADMIN_TOKEN"
curl -s http://localhost:8080/api/books
```

Expected: archive response shows `archived: true, published: false`; the public list is empty again (archived hides regardless of the prior published state).

- [ ] **Step 4: Document and commit**

```markdown
| POST | /api/admin/books/:id/publish | admin | sets published=1 |
| POST | /api/admin/books/:id/unpublish | admin | sets published=0 |
| POST | /api/admin/books/:id/archive | admin | sets archived=1, published=0 |
```

```bash
git add backend/src/Controllers/AdminBookController.php backend/src/routes.php backend/README.md
git commit -m "feat: admin publish/unpublish/archive endpoints"
```

---

### Task 6: Cover upload

**Files:**
- Modify: `backend/src/Controllers/AdminBookController.php`, `backend/src/routes.php`, `backend/README.md`

**Interfaces:**
- Consumes: `FileValidator::validateImage` (Task 2).
- Produces: `POST /api/admin/books/:id/cover` → `{coverUrl}`, also updates `books.cover_url`.

- [ ] **Step 1: Add constants and the method to `AdminBookController`**

Add near the top of the class:

```php
    private const UPLOAD_PUBLIC_DIR = __DIR__ . '/../../public/uploads/books';
    private const UPLOAD_PRIVATE_DIR = __DIR__ . '/../../storage/books';
```

Add `use App\Upload\FileValidator;` to the top `use` block. Add the method:

```php
    public function uploadCover(string $id): void
    {
        AuthMiddleware::requireRole(['admin']);
        $this->findOrFail($id);
        $ext = FileValidator::validateImage($_FILES['file'] ?? []);
        $dir = self::UPLOAD_PUBLIC_DIR . "/{$id}";
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $destination = "{$dir}/cover.{$ext}";
        move_uploaded_file($_FILES['file']['tmp_name'], $destination);
        $url = "/uploads/books/{$id}/cover.{$ext}";
        Db::connection()->prepare('UPDATE books SET cover_url = :url WHERE id = :id')->execute(['url' => $url, 'id' => $id]);
        echo json_encode(['coverUrl' => $url]);
    }
```

- [ ] **Step 2: Add route**

```php
$router->post('/api/admin/books/{id}/cover', [$adminBooks, 'uploadCover']);
```

- [ ] **Step 3: Verify against the running stack with a real file**

```bash
docker compose exec -T php php -r "imagejpeg(imagecreatetruecolor(100, 150), '/tmp/cover-test.jpg');"
docker compose cp "$(docker compose ps -q php):/tmp/cover-test.jpg" /tmp/cover-test.jpg 2>/dev/null || docker compose exec -T php cat /tmp/cover-test.jpg > /tmp/cover-test.jpg

curl -s -X POST "http://localhost:8080/api/admin/books/$BOOK_ID/cover" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/cover-test.jpg"
```

Expected: `{"coverUrl":"/uploads/books/<id>/cover.jpg"}`.

```bash
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8080/uploads/books/$BOOK_ID/cover.jpg"
```

Expected: `200` — proves nginx's existing `try_files` serves the uploaded file directly as a static asset, no code change needed on the nginx side.

- [ ] **Step 4: Document and commit**

```markdown
| POST | /api/admin/books/:id/cover | admin | multipart `file` (image, max 5MB) → `{coverUrl}` |
```

```bash
git add backend/src/Controllers/AdminBookController.php backend/src/routes.php backend/README.md
git commit -m "feat: admin cover-image upload endpoint"
```

---

### Task 7: Index document upload

**Files:**
- Modify: `backend/src/Controllers/AdminBookController.php`, `backend/src/routes.php`, `backend/README.md`

**Interfaces:**
- Consumes: `FileValidator::validateImageOrPdf` (Task 2).
- Produces: `POST /api/admin/books/:id/index` → `{indexUrl}`, updates `books.index_file_path`.

- [ ] **Step 1: Add the method**

```php
    public function uploadIndex(string $id): void
    {
        AuthMiddleware::requireRole(['admin']);
        $this->findOrFail($id);
        $ext = FileValidator::validateImageOrPdf($_FILES['file'] ?? []);
        $dir = self::UPLOAD_PUBLIC_DIR . "/{$id}";
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $destination = "{$dir}/index.{$ext}";
        move_uploaded_file($_FILES['file']['tmp_name'], $destination);
        $url = "/uploads/books/{$id}/index.{$ext}";
        Db::connection()->prepare('UPDATE books SET index_file_path = :url WHERE id = :id')->execute(['url' => $url, 'id' => $id]);
        echo json_encode(['indexUrl' => $url]);
    }
```

- [ ] **Step 2: Add route**

```php
$router->post('/api/admin/books/{id}/index', [$adminBooks, 'uploadIndex']);
```

- [ ] **Step 3: Verify**

```bash
curl -s -X POST "http://localhost:8080/api/admin/books/$BOOK_ID/index" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/cover-test.jpg"
curl -s "http://localhost:8080/api/books/$BOOK_ID" | grep -o '"indexUrl":"[^"]*"'
```

Expected: upload returns `{"indexUrl":"..."}`; the public detail endpoint (book must be published — republish if the archive test in Task 5 left it archived) includes the same `indexUrl`.

- [ ] **Step 4: Document and commit**

```markdown
| POST | /api/admin/books/:id/index | admin | multipart `file` (image or PDF, max 5MB) → `{indexUrl}` |
```

```bash
git add backend/src/Controllers/AdminBookController.php backend/src/routes.php backend/README.md
git commit -m "feat: admin index-document upload endpoint"
```

---

### Task 8: Preview-page upload and delete

**Files:**
- Modify: `backend/src/Controllers/AdminBookController.php`, `backend/src/routes.php`, `backend/README.md`

**Interfaces:**
- Produces: `POST /api/admin/books/:id/preview-pages/:pageNumber` → `{pageNumber, url}` (upserts), `DELETE /api/admin/books/:id/preview-pages/:pageNumber` → `{deleted: true}`.

- [ ] **Step 1: Add the methods**

```php
    public function uploadPreviewPage(string $id, string $pageNumber): void
    {
        AuthMiddleware::requireRole(['admin']);
        $this->findOrFail($id);
        $ext = FileValidator::validateImage($_FILES['file'] ?? []);
        $dir = self::UPLOAD_PUBLIC_DIR . "/{$id}";
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $destination = "{$dir}/preview-{$pageNumber}.{$ext}";
        move_uploaded_file($_FILES['file']['tmp_name'], $destination);
        $url = "/uploads/books/{$id}/preview-{$pageNumber}.{$ext}";

        Db::connection()->prepare(
            'INSERT INTO book_preview_pages (book_id, page_number, file_path) VALUES (:book_id, :page_number, :url)
             ON DUPLICATE KEY UPDATE file_path = :url2'
        )->execute(['book_id' => $id, 'page_number' => $pageNumber, 'url' => $url, 'url2' => $url]);

        echo json_encode(['pageNumber' => (int)$pageNumber, 'url' => $url]);
    }

    public function deletePreviewPage(string $id, string $pageNumber): void
    {
        AuthMiddleware::requireRole(['admin']);
        $this->findOrFail($id);
        $db = Db::connection();
        $stmt = $db->prepare('SELECT file_path FROM book_preview_pages WHERE book_id = :book_id AND page_number = :page_number');
        $stmt->execute(['book_id' => $id, 'page_number' => $pageNumber]);
        $row = $stmt->fetch();
        if ($row) {
            $path = self::UPLOAD_PUBLIC_DIR . "/{$id}/" . basename($row['file_path']);
            if (file_exists($path)) {
                unlink($path);
            }
            $db->prepare('DELETE FROM book_preview_pages WHERE book_id = :book_id AND page_number = :page_number')
                ->execute(['book_id' => $id, 'page_number' => $pageNumber]);
        }
        echo json_encode(['deleted' => true]);
    }
```

- [ ] **Step 2: Add routes**

```php
$router->post('/api/admin/books/{id}/preview-pages/{pageNumber}', [$adminBooks, 'uploadPreviewPage']);
$router->delete('/api/admin/books/{id}/preview-pages/{pageNumber}', [$adminBooks, 'deletePreviewPage']);
```

- [ ] **Step 3: Verify**

```bash
curl -s -X POST "http://localhost:8080/api/admin/books/$BOOK_ID/preview-pages/1" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/cover-test.jpg"
curl -s -X POST "http://localhost:8080/api/admin/books/$BOOK_ID/preview-pages/2" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/cover-test.jpg"
curl -s "http://localhost:8080/api/admin/books/$BOOK_ID" | grep -o '"previewPageCount":[0-9]*'
```

Expected: `"previewPageCount":2`.

```bash
curl -s -X DELETE "http://localhost:8080/api/admin/books/$BOOK_ID/preview-pages/2" -H "Authorization: Bearer $ADMIN_TOKEN"
curl -s "http://localhost:8080/api/admin/books/$BOOK_ID" | grep -o '"previewPageCount":[0-9]*'
```

Expected: `"previewPageCount":1`.

- [ ] **Step 4: Document and commit**

```markdown
| POST | /api/admin/books/:id/preview-pages/:pageNumber | admin | multipart `file` (image, max 5MB) → upserts that page |
| DELETE | /api/admin/books/:id/preview-pages/:pageNumber | admin | removes that page |
```

```bash
git add backend/src/Controllers/AdminBookController.php backend/src/routes.php backend/README.md
git commit -m "feat: admin preview-page upload/delete endpoints"
```

---

### Task 9: Complete PDF upload (private storage)

**Files:**
- Modify: `backend/src/Controllers/AdminBookController.php`, `backend/src/routes.php`, `backend/README.md`

**Interfaces:**
- Consumes: `FileValidator::validatePdf` (Task 2).
- Produces: `POST /api/admin/books/:id/pdf` → `{pdfUploaded: true}`, updates `books.digital_file_path` to a path under `backend/storage/` (never under `public/`).

- [ ] **Step 1: Add the method**

```php
    public function uploadPdf(string $id): void
    {
        AuthMiddleware::requireRole(['admin']);
        $this->findOrFail($id);
        FileValidator::validatePdf($_FILES['file'] ?? []);
        $dir = self::UPLOAD_PRIVATE_DIR . "/{$id}";
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $destination = "{$dir}/book.pdf";
        move_uploaded_file($_FILES['file']['tmp_name'], $destination);
        Db::connection()->prepare('UPDATE books SET digital_file_path = :path WHERE id = :id')
            ->execute(['path' => $destination, 'id' => $id]);
        echo json_encode(['pdfUploaded' => true]);
    }
```

- [ ] **Step 2: Add route**

```php
$router->post('/api/admin/books/{id}/pdf', [$adminBooks, 'uploadPdf']);
```

- [ ] **Step 3: Verify — including that it's genuinely unreachable publicly**

```bash
docker compose exec -T php php -r "file_put_contents('/tmp/test.pdf', \"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\");"
```

Copy a real PDF file to `/tmp/test.pdf` on the host (same approach as Step 3 in Task 6, or write one directly with a text editor — any minimal valid PDF works), then:

```bash
curl -s -X POST "http://localhost:8080/api/admin/books/$BOOK_ID/pdf" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/test.pdf"
curl -s "http://localhost:8080/api/admin/books/$BOOK_ID" | grep -o '"hasPdf":[a-z]*'
```

Expected: `{"pdfUploaded":true}`; `"hasPdf":true`.

```bash
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8080/storage/books/$BOOK_ID/book.pdf"
curl -s http://localhost:8080/api/books | grep -c "digital_file_path"
curl -s "http://localhost:8080/api/books/$BOOK_ID" | grep -c "digital_file_path"
```

Expected: first command `404` (nginx's docroot is `public/`, `storage/` isn't under it — unreachable); both `grep -c` commands `0` (the field never appears in public responses).

- [ ] **Step 4: Document and commit**

```markdown
| POST | /api/admin/books/:id/pdf | admin | multipart `file` (PDF, max 50MB) → stores privately under `backend/storage/`, never publicly servable |
```

```bash
git add backend/src/Controllers/AdminBookController.php backend/src/routes.php backend/README.md
git commit -m "feat: admin complete-PDF upload endpoint (private storage)"
```

---

### Task 10: Full book-management HTTP integration suite

**Files:**
- Create: `backend/tests/book_test.sh`
- Modify: `backend/TEST_RESULTS.md`, `backend/README.md`

**Interfaces:**
- Consumes: every endpoint from Tasks 3–9, plus the existing `/api/admin/login` (sub-project 2).
- Produces: `backend/tests/book_test.sh`, re-runnable any time to re-verify this sub-project's backend.

- [ ] **Step 1: Write `backend/tests/book_test.sh`**

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

echo "=== Setup: admin token, test files ==="
ADMIN_TOKEN=$(curl -s -X POST "$BASE_URL/api/admin/login" -H "Content-Type: application/json" -d '{"username":"admin","password":"adminpass123"}' | grep -o '"token":"[^"]*"' | cut -d'"' -f4)
check "admin token obtained" "$([ -n "$ADMIN_TOKEN" ] && echo true || echo false)"

CUSTOMER_EMAIL="booktest-$(date +%s)@example.com"
CUSTOMER_TOKEN=$(curl -s -X POST "$BASE_URL/api/auth/register" -H "Content-Type: application/json" -d "{\"name\":\"T\",\"email\":\"$CUSTOMER_EMAIL\",\"password\":\"password123\"}" | grep -o '"token":"[^"]*"' | cut -d'"' -f4)
check "customer token obtained" "$([ -n "$CUSTOMER_TOKEN" ] && echo true || echo false)"

docker compose exec -T php php -r "imagejpeg(imagecreatetruecolor(100, 150), '/tmp/book_test_cover.jpg');" >/dev/null 2>&1
docker compose exec -T php php -r "file_put_contents('/tmp/book_test.pdf', \"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\");" >/dev/null 2>&1
docker compose cp "$(docker compose ps -q php):/tmp/book_test_cover.jpg" /tmp/book_test_cover.jpg
docker compose cp "$(docker compose ps -q php):/tmp/book_test.pdf" /tmp/book_test.pdf

echo "=== 1. Create a book ==="
CREATE=$(curl -s -o /tmp/book_create.json -w "%{http_code}" -X POST "$BASE_URL/api/admin/books" -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"title":"Integration Test Book","onlinePrice":199,"hardcopyPrice":349}')
check "create returns 201" "$([ "$CREATE" = "201" ] && echo true || echo false)"
BOOK_ID=$(grep -o '"id":[0-9]*' /tmp/book_create.json | head -1 | grep -o '[0-9]*')
check "create returns an id" "$([ -n "$BOOK_ID" ] && echo true || echo false)"

echo "=== 2. New book is not public yet ==="
PUBLIC_LIST=$(curl -s "$BASE_URL/api/books")
check "unpublished book absent from public list" "$(echo "$PUBLIC_LIST" | grep -q "\"id\":$BOOK_ID" && echo false || echo true)"

echo "=== 3. Publish, then it's public ==="
curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/publish" -H "Authorization: Bearer $ADMIN_TOKEN" >/dev/null
PUBLIC_LIST=$(curl -s "$BASE_URL/api/books")
check "published book appears in public list" "$(echo "$PUBLIC_LIST" | grep -q "\"id\":$BOOK_ID" && echo true || echo false)"

echo "=== 4. Cover, index, preview-page, PDF uploads ==="
COVER=$(curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/cover" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/book_test_cover.jpg")
check "cover upload returns a coverUrl" "$(echo "$COVER" | grep -q '"coverUrl"' && echo true || echo false)"

INDEX=$(curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/index" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/book_test_cover.jpg")
check "index upload returns an indexUrl" "$(echo "$INDEX" | grep -q '"indexUrl"' && echo true || echo false)"

curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/preview-pages/1" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/book_test_cover.jpg" >/dev/null
PREVIEW2=$(curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/preview-pages/2" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/book_test_cover.jpg")
check "second preview page upload succeeds" "$(echo "$PREVIEW2" | grep -q '"pageNumber":2' && echo true || echo false)"

PDF=$(curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/pdf" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/book_test.pdf")
check "PDF upload returns pdfUploaded true" "$(echo "$PDF" | grep -q '"pdfUploaded":true' && echo true || echo false)"

echo "=== 5. Public detail shows assets, never the private PDF path ==="
DETAIL=$(curl -s "$BASE_URL/api/books/$BOOK_ID")
check "public detail includes coverUrl" "$(echo "$DETAIL" | grep -q '"coverUrl"' && echo true || echo false)"
check "public detail includes indexUrl" "$(echo "$DETAIL" | grep -q '"indexUrl"' && echo true || echo false)"
check "public detail includes 2 previewPages" "$(echo "$DETAIL" | grep -o '"pageNumber"' | wc -l | grep -q '^2$' && echo true || echo false)"
check "public detail never mentions digital_file_path or storage path" "$(echo "$DETAIL" | grep -q 'digital_file_path\|/storage/' && echo false || echo true)"
PUBLIC_LIST_BODY=$(curl -s "$BASE_URL/api/books")
check "public list never mentions digital_file_path" "$(echo "$PUBLIC_LIST_BODY" | grep -q 'digital_file_path' && echo false || echo true)"

echo "=== 6. Private PDF genuinely unreachable ==="
PDF_DIRECT=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/storage/books/$BOOK_ID/book.pdf")
check "direct PDF URL guess returns 404 (storage/ not in docroot)" "$([ "$PDF_DIRECT" = "404" ] && echo true || echo false)"

echo "=== 7. Edit propagates ==="
curl -s -X PUT "$BASE_URL/api/admin/books/$BOOK_ID" -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" -d '{"title":"Edited Title"}' >/dev/null
EDITED_LIST=$(curl -s "$BASE_URL/api/books")
check "edited title appears in public list" "$(echo "$EDITED_LIST" | grep -q '"title":"Edited Title"' && echo true || echo false)"

echo "=== 8. Unpublish removes from public list ==="
curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/unpublish" -H "Authorization: Bearer $ADMIN_TOKEN" >/dev/null
AFTER_UNPUBLISH=$(curl -s "$BASE_URL/api/books")
check "unpublished book absent from public list" "$(echo "$AFTER_UNPUBLISH" | grep -q "\"id\":$BOOK_ID" && echo false || echo true)"

echo "=== 9. Archive hides even if republished ==="
curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/publish" -H "Authorization: Bearer $ADMIN_TOKEN" >/dev/null
curl -s -X POST "$BASE_URL/api/admin/books/$BOOK_ID/archive" -H "Authorization: Bearer $ADMIN_TOKEN" >/dev/null
AFTER_ARCHIVE=$(curl -s "$BASE_URL/api/books")
check "archived book absent from public list even though published=1 was set first" "$(echo "$AFTER_ARCHIVE" | grep -q "\"id\":$BOOK_ID" && echo false || echo true)"

echo "=== 10. Role separation on admin endpoints ==="
NO_TOKEN_CREATE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/admin/books" -H "Content-Type: application/json" -d '{"title":"x","onlinePrice":1,"hardcopyPrice":1}')
check "create with no token returns 401" "$([ "$NO_TOKEN_CREATE" = "401" ] && echo true || echo false)"

CUSTOMER_CREATE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/admin/books" -H "Authorization: Bearer $CUSTOMER_TOKEN" -H "Content-Type: application/json" -d '{"title":"x","onlinePrice":1,"hardcopyPrice":1}')
check "create with customer-role token returns 403" "$([ "$CUSTOMER_CREATE" = "403" ] && echo true || echo false)"

CUSTOMER_UPLOAD=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/admin/books/$BOOK_ID/cover" -H "Authorization: Bearer $CUSTOMER_TOKEN" -F "file=@/tmp/book_test_cover.jpg")
check "cover upload with customer-role token returns 403" "$([ "$CUSTOMER_UPLOAD" = "403" ] && echo true || echo false)"

echo "=== 11. Invalid file rejected ==="
echo "not an image" > /tmp/book_test_bad.txt
BAD_FILE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/admin/books/$BOOK_ID/cover" -H "Authorization: Bearer $ADMIN_TOKEN" -F "file=@/tmp/book_test_bad.txt")
check "non-image file upload to cover endpoint returns 400" "$([ "$BAD_FILE" = "400" ] && echo true || echo false)"

echo ""
echo "Results: $PASS passed, $FAIL failed"
if [ "$FAIL" -gt 0 ]; then
  exit 1
fi
```

- [ ] **Step 2: Make it executable and run it**

```bash
chmod +x backend/tests/book_test.sh
./backend/tests/book_test.sh
```

Expected: every line `PASS:`, final `Results: N passed, 0 failed`. If anything fails, fix the underlying code and re-run until clean — this test is the primary proof this task's work is correct.

- [ ] **Step 3: Append the captured output to `backend/TEST_RESULTS.md`**

```bash
echo "" >> backend/TEST_RESULTS.md
echo "## Sub-project 4: Book Management" >> backend/TEST_RESULTS.md
echo "" >> backend/TEST_RESULTS.md
echo "Run date: $(date -u +%Y-%m-%dT%H:%M:%SZ)" >> backend/TEST_RESULTS.md
echo "" >> backend/TEST_RESULTS.md
echo '```' >> backend/TEST_RESULTS.md
docker compose exec -T php php tests/upload_validator_test.php >> backend/TEST_RESULTS.md
echo '' >> backend/TEST_RESULTS.md
./backend/tests/book_test.sh >> backend/TEST_RESULTS.md 2>&1
echo '```' >> backend/TEST_RESULTS.md
```

- [ ] **Step 4: Commit**

```bash
git add backend/tests/book_test.sh backend/TEST_RESULTS.md backend/README.md
git commit -m "test: full book-management HTTP integration suite, capture results"
```

---

### Task 11: `src/api/books.js` + `BooksPage.jsx` wired to the real public API

**Files:**
- Create: `src/api/books.js`, `src/api/books.test.js`
- Modify: `src/api/client.js` (add `apiRequestFile`), `src/pages/BooksPage.jsx`, `src/pages/BooksPage.test.jsx`

**Interfaces:**
- Consumes: `apiRequest` (sub-project 2).
- Produces: `apiRequestFile(path, token, file): Promise<object>`. `fetchBooks(): Promise<Book[]>`, `fetchBook(id): Promise<Book>`, `fetchAdminBooks(token)`, `createBook(token, data)`, `updateBook(token, id, changes)`, `publishBook(token, id)`, `unpublishBook(token, id)`, `archiveBook(token, id)`, `uploadBookFile(token, id, kind, file)`, `uploadPreviewPage(token, id, pageNumber, file)` — Tasks 13 consumes the admin-side functions.

- [ ] **Step 1: Add `apiRequestFile` to `src/api/client.js`**

```js
export async function apiRequestFile(path, token, file) {
  const formData = new FormData()
  formData.append('file', file)
  const headers = {}
  if (token) headers.Authorization = `Bearer ${token}`

  const res = await fetch(`${API_BASE_URL}${path}`, { method: 'POST', headers, body: formData })
  const data = await res.json().catch(() => ({}))

  if (!res.ok) {
    throw new Error(data?.error?.message || 'Upload failed')
  }

  return data
}
```

(Deliberately not setting `Content-Type` — the browser sets the correct `multipart/form-data; boundary=...` automatically when the body is a `FormData` instance; setting it manually breaks the boundary.)

- [ ] **Step 2: Write `src/api/books.test.js`**

```js
import { fetchBooks, fetchBook, createBook, publishBook } from './books'

function mockFetch(responseBody, ok = true, status = 200) {
  global.fetch = vi.fn(() => Promise.resolve({ ok, status, json: () => Promise.resolve(responseBody) }))
}

afterEach(() => {
  vi.restoreAllMocks()
})

test('fetchBooks calls GET /api/books with no auth header', async () => {
  mockFetch([{ id: 1, title: 'A Book' }])
  const result = await fetchBooks()
  expect(result).toEqual([{ id: 1, title: 'A Book' }])
  const [url, options] = global.fetch.mock.calls[0]
  expect(url).toContain('/api/books')
  expect(options.headers.Authorization).toBeUndefined()
})

test('fetchBook calls GET /api/books/:id', async () => {
  mockFetch({ id: 5, title: 'Specific Book' })
  await fetchBook(5)
  const [url] = global.fetch.mock.calls[0]
  expect(url).toContain('/api/books/5')
})

test('createBook sends admin token and POST body', async () => {
  mockFetch({ id: 9 }, true, 201)
  await createBook('admin-token', { title: 'New', onlinePrice: 100, hardcopyPrice: 200 })
  const [url, options] = global.fetch.mock.calls[0]
  expect(url).toContain('/api/admin/books')
  expect(options.method).toBe('POST')
  expect(options.headers.Authorization).toBe('Bearer admin-token')
  expect(JSON.parse(options.body)).toEqual({ title: 'New', onlinePrice: 100, hardcopyPrice: 200 })
})

test('publishBook posts to the publish endpoint', async () => {
  mockFetch({ published: true })
  await publishBook('admin-token', 3)
  const [url, options] = global.fetch.mock.calls[0]
  expect(url).toContain('/api/admin/books/3/publish')
  expect(options.method).toBe('POST')
})
```

- [ ] **Step 3: Run test, verify it fails**

Run: `npx vitest run src/api/books.test.js --exclude ".worktrees/**"`
Expected: FAIL (`src/api/books.js` doesn't exist yet).

- [ ] **Step 4: Write `src/api/books.js`**

```js
import { apiRequest, apiRequestFile } from './client'

export function fetchBooks() {
  return apiRequest('/api/books')
}

export function fetchBook(id) {
  return apiRequest(`/api/books/${id}`)
}

export function fetchAdminBooks(token) {
  return apiRequest('/api/admin/books', { token })
}

export function createBook(token, { title, description, category, onlinePrice, hardcopyPrice }) {
  return apiRequest('/api/admin/books', {
    method: 'POST',
    token,
    body: { title, description, category, onlinePrice, hardcopyPrice },
  })
}

export function updateBook(token, id, changes) {
  return apiRequest(`/api/admin/books/${id}`, { method: 'PUT', token, body: changes })
}

export function publishBook(token, id) {
  return apiRequest(`/api/admin/books/${id}/publish`, { method: 'POST', token })
}

export function unpublishBook(token, id) {
  return apiRequest(`/api/admin/books/${id}/unpublish`, { method: 'POST', token })
}

export function archiveBook(token, id) {
  return apiRequest(`/api/admin/books/${id}/archive`, { method: 'POST', token })
}

export function uploadBookFile(token, id, kind, file) {
  return apiRequestFile(`/api/admin/books/${id}/${kind}`, token, file)
}

export function uploadPreviewPage(token, id, pageNumber, file) {
  return apiRequestFile(`/api/admin/books/${id}/preview-pages/${pageNumber}`, token, file)
}
```

- [ ] **Step 5: Run test, verify it passes**

Run: `npx vitest run src/api/books.test.js --exclude ".worktrees/**"`
Expected: PASS (4/4).

- [ ] **Step 6: Read the current `src/pages/BooksPage.jsx` and `src/pages/BooksPage.test.jsx`**

Read both in full before editing — confirm the exact current markup/classes (the sub-project 3 compact-card redesign) and the current test's assertions, so the replacement preserves the design exactly and the test update targets what's really there.

- [ ] **Step 7: Replace `BooksPage.jsx`'s data source** (markup/CSS classes unchanged — only where `books` comes from)

```jsx
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { fetchBooks } from '../api/books'
import Header from '../components/home/Header'
import Footer from '../components/home/Footer'

function BooksPage() {
  const [books, setBooks] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    fetchBooks()
      .then(setBooks)
      .catch(err => setError(err.message))
      .finally(() => setLoading(false))
  }, [])

  return (
    <>
      <Header />
      <section className="container" style={{ padding: '2rem 0' }}>
        <h2>Books</h2>
        {loading && <p>Loading books…</p>}
        {error && <p className="form-error">{error}</p>}
        <div className="books-grid">
          {books.map(book => (
            <div key={book.id} className="book-card">
              <img src={book.coverUrl} alt={book.title} className="book-card-cover" />
              <div className="book-card-body">
                <h3 className="book-card-title">{book.title}</h3>
                <span className="book-card-type">{book.category}</span>
                <ul className="book-card-prices">
                  <li><span>Online Price</span><span className="price-value">₹{book.onlinePrice}</span></li>
                  <li><span>Hard Copy Price</span><span className="price-value">₹{book.hardcopyPrice}</span></li>
                </ul>
                <Link to={`/books/${book.id}`} className="btn btn-primary">View &amp; Purchase</Link>
              </div>
            </div>
          ))}
        </div>
      </section>
      <Footer />
    </>
  )
}

export default BooksPage
```

(If Step 6's reading of the real current file shows the sub-project 3 markup differs even slightly from this reference, keep the real markup/classes exactly as they are — only the `books`-source wiring above is what must change.)

- [ ] **Step 8: Update `BooksPage.test.jsx`**

Read the existing test (it currently seeds `SessionContext`'s mock `state.books`). Replace with an `api/books.js` mock, same pattern as `AccountPage.test.jsx` mocking `api/auth.js`:

```jsx
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { vi } from 'vitest'
import BooksPage from './BooksPage'
import * as booksApi from '../api/books'

test('lists published books from the real API', async () => {
  vi.spyOn(booksApi, 'fetchBooks').mockResolvedValue([
    { id: 1, title: 'Success Mantra', category: 'Exam Prep', coverUrl: '/x.jpg', onlinePrice: 199, hardcopyPrice: 349 },
  ])

  render(<MemoryRouter><BooksPage /></MemoryRouter>)

  await waitFor(() => {
    expect(screen.getByText(/success mantra/i)).toBeInTheDocument()
  })
})

test('shows an error message if the API call fails', async () => {
  vi.spyOn(booksApi, 'fetchBooks').mockRejectedValue(new Error('Network error'))

  render(<MemoryRouter><BooksPage /></MemoryRouter>)

  await waitFor(() => {
    expect(screen.getByText(/network error/i)).toBeInTheDocument()
  })
})
```

(`BooksPage` no longer needs `SessionProvider` as a wrapper since it reads no session state — drop that wrapper if the old test had one.)

- [ ] **Step 9: Run the updated test, verify it passes**

Run: `npx vitest run src/pages/BooksPage.test.jsx --exclude ".worktrees/**"`
Expected: PASS (2/2).

- [ ] **Step 10: Commit**

```bash
git add src/api/books.js src/api/books.test.js src/api/client.js src/pages/BooksPage.jsx src/pages/BooksPage.test.jsx
git commit -m "feat: wire public BooksPage to the real book-listing API"
```

---

### Task 12: `AdminLoginPage.jsx` wired to real admin login

**Files:**
- Modify: `src/api/auth.js` (add `loginAdmin`), `src/pages/admin/AdminLoginPage.jsx`, `src/pages/admin/AdminLayout.test.jsx`

**Interfaces:**
- Consumes: `apiRequest` (sub-project 2).
- Produces: `loginAdmin({username, password}): Promise<{token, admin}>`.

- [ ] **Step 1: Add `loginAdmin` to `src/api/auth.js`**

```js
export function loginAdmin({ username, password }) {
  return apiRequest('/api/admin/login', { method: 'POST', body: { username, password } })
}
```

- [ ] **Step 2: Replace `AdminLoginPage.jsx`'s hardcoded check with the real call**

```jsx
import { useState } from 'react'
import { useSession } from '../../context/SessionContext'
import { loginAdmin } from '../../api/auth'

function AdminLoginPage() {
  const { dispatch } = useSession()
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const handleSubmit = async (e) => {
    e.preventDefault()
    setError('')
    setSubmitting(true)
    try {
      const result = await loginAdmin({ username, password })
      localStorage.setItem('vtee-admin-token', result.token)
      dispatch({ type: 'LOGIN_ADMIN', payload: { username: result.admin.username } })
    } catch (err) {
      setError(err.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="container">
      <h2>Admin Login</h2>
      {error && <p className="form-error">{error}</p>}
      <label htmlFor="admin-username">Username</label>
      <input id="admin-username" className="form-control" value={username} onChange={e => setUsername(e.target.value)} />
      <label htmlFor="admin-password">Password</label>
      <input id="admin-password" type="password" className="form-control" value={password} onChange={e => setPassword(e.target.value)} />
      <button type="submit" className="btn btn-primary" disabled={submitting}>Log In</button>
    </form>
  )
}

export default AdminLoginPage
```

(`vtee-admin-token` is a separate `localStorage` key from the customer `vtee-auth-token` sub-project 2 set — different role, must not collide with a logged-in customer's token in the same browser.)

- [ ] **Step 3: Read the current `AdminLayout.test.jsx`, update its login test**

Read the file first — it currently drives the old hardcoded `admin`/`admin123` check. Replace with a mock of `loginAdmin`:

```jsx
import { vi } from 'vitest'
import * as authApi from '../../api/auth'
// ... existing imports

test('shows login form, then nav after real admin login succeeds', async () => {
  vi.spyOn(authApi, 'loginAdmin').mockResolvedValue({ token: 'admin-token', admin: { id: 1, username: 'admin' } })

  // ... render as before ...
  expect(screen.getByLabelText(/username/i)).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText(/username/i), { target: { value: 'admin' } })
  fireEvent.change(screen.getByLabelText(/password/i), { target: { value: 'adminpass123' } })
  fireEvent.click(screen.getByRole('button', { name: /log in/i }))

  await waitFor(() => {
    expect(screen.getByText(/dashboard content/i)).toBeInTheDocument()
  })
})
```

(Keep the existing test's render/wrapper setup exactly as it is — only the login-mechanism part changes from hardcoded-credential-check to a mocked API call, same adaptation pattern as sub-project 2's `AccountPage.test.jsx` update.)

- [ ] **Step 4: Run test, verify it passes**

Run: `npx vitest run src/pages/admin/AdminLayout.test.jsx --exclude ".worktrees/**"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/api/auth.js src/pages/admin/AdminLoginPage.jsx src/pages/admin/AdminLayout.test.jsx
git commit -m "feat: wire AdminLoginPage to the real admin-login API"
```

---

### Task 13: `AdminBooksPage.jsx` wired to the real backend (CRUD + uploads)

**Files:**
- Modify: `src/pages/admin/AdminBooksPage.jsx`, `src/pages/admin/AdminBooksPage.test.jsx`

**Interfaces:**
- Consumes: every function from `src/api/books.js` (Task 11).

- [ ] **Step 1: Read the current `AdminBooksPage.jsx` and `AdminBooksPage.test.jsx`**

Read both in full — confirm the exact current table structure, field ids, and button labels before replacing the mock-dispatch plumbing.

- [ ] **Step 2: Replace `AdminBooksPage.jsx`**

This drops the old mock `DELETE_BOOK` action — the backend has no hard-delete endpoint by design (archive, not delete, per the spec's non-goal on preserving historical purchase/order references). The "Delete" button becomes "Archive," consistent with what's actually buildable against the real API.

```jsx
import { useEffect, useState } from 'react'
import {
  fetchAdminBooks, createBook, updateBook, publishBook, unpublishBook, archiveBook,
  uploadBookFile, uploadPreviewPage,
} from '../../api/books'

function AdminBooksPage() {
  const token = localStorage.getItem('vtee-admin-token')
  const [books, setBooks] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [title, setTitle] = useState('')
  const [onlinePrice, setOnlinePrice] = useState('')
  const [hardCopyPrice, setHardCopyPrice] = useState('')
  const [editingId, setEditingId] = useState(null)
  const [editTitle, setEditTitle] = useState('')

  const load = async () => {
    setLoading(true)
    setError('')
    try {
      setBooks(await fetchAdminBooks(token))
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, [])

  const withBusy = async (fn) => {
    setBusy(true)
    setError('')
    try {
      await fn()
      await load()
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  const handleAdd = (e) => {
    e.preventDefault()
    withBusy(async () => {
      await createBook(token, { title, onlinePrice: Number(onlinePrice), hardcopyPrice: Number(hardCopyPrice) })
      setTitle('')
      setOnlinePrice('')
      setHardCopyPrice('')
    })
  }

  const startEdit = (book) => {
    setEditingId(book.id)
    setEditTitle(book.title)
  }

  const handleSaveEdit = (e) => {
    e.preventDefault()
    withBusy(async () => {
      await updateBook(token, editingId, { title: editTitle })
      setEditingId(null)
    })
  }

  const handleFileChange = (book, kind) => (e) => {
    const file = e.target.files[0]
    if (!file) return
    withBusy(() => uploadBookFile(token, book.id, kind, file))
  }

  const handlePreviewChange = (book, pageNumber) => (e) => {
    const file = e.target.files[0]
    if (!file) return
    withBusy(() => uploadPreviewPage(token, book.id, pageNumber, file))
  }

  if (loading) return <div><h2>Books</h2><p>Loading…</p></div>

  return (
    <div>
      <h2>Books</h2>
      {error && <p className="form-error">{error}</p>}
      <form onSubmit={handleAdd}>
        <label htmlFor="new-title">Title</label>
        <input id="new-title" className="form-control" value={title} onChange={e => setTitle(e.target.value)} required />
        <label htmlFor="new-online-price">Online Price</label>
        <input id="new-online-price" type="number" className="form-control" value={onlinePrice} onChange={e => setOnlinePrice(e.target.value)} required />
        <label htmlFor="new-hardcopy-price">Hard Copy Price</label>
        <input id="new-hardcopy-price" type="number" className="form-control" value={hardCopyPrice} onChange={e => setHardCopyPrice(e.target.value)} required />
        <button type="submit" className="btn btn-primary" disabled={busy}>Add Book</button>
      </form>

      <table>
        <thead>
          <tr><th>Title</th><th>Status</th><th>Files</th><th>Actions</th></tr>
        </thead>
        <tbody>
          {books.map(book => (
            <tr key={book.id}>
              <td>
                {editingId === book.id ? (
                  <form onSubmit={handleSaveEdit}>
                    <label htmlFor={`edit-title-${book.id}`}>Edit Title</label>
                    <input id={`edit-title-${book.id}`} className="form-control" value={editTitle} onChange={e => setEditTitle(e.target.value)} required />
                    <button type="submit" disabled={busy}>Save Changes</button>
                    <button type="button" onClick={() => setEditingId(null)}>Cancel</button>
                  </form>
                ) : (
                  book.title
                )}
              </td>
              <td>{book.archived ? 'Archived' : book.published ? 'Published' : 'Unpublished'}</td>
              <td>
                <label>Cover {book.hasCover ? '✓' : ''}
                  <input type="file" accept="image/*" onChange={handleFileChange(book, 'cover')} disabled={busy} />
                </label>
                <label>Index {book.hasIndex ? '✓' : ''}
                  <input type="file" accept="image/*,application/pdf" onChange={handleFileChange(book, 'index')} disabled={busy} />
                </label>
                <label>PDF {book.hasPdf ? '✓' : ''}
                  <input type="file" accept="application/pdf" onChange={handleFileChange(book, 'pdf')} disabled={busy} />
                </label>
                <label>Preview 1
                  <input type="file" accept="image/*" onChange={handlePreviewChange(book, 1)} disabled={busy} />
                </label>
                <label>Preview 2
                  <input type="file" accept="image/*" onChange={handlePreviewChange(book, 2)} disabled={busy} />
                </label>
              </td>
              <td>
                <button onClick={() => startEdit(book)} disabled={busy}>Edit</button>
                {!book.archived && (
                  <button
                    onClick={() => withBusy(() => (book.published ? unpublishBook(token, book.id) : publishBook(token, book.id)))}
                    disabled={busy}
                  >
                    {book.published ? 'Unpublish' : 'Publish'}
                  </button>
                )}
                {!book.archived && (
                  <button onClick={() => withBusy(() => archiveBook(token, book.id))} disabled={busy}>Archive</button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export default AdminBooksPage
```

- [ ] **Step 3: Replace `AdminBooksPage.test.jsx`**

```jsx
import { render, screen, fireEvent, within, waitFor } from '@testing-library/react'
import { vi } from 'vitest'
import AdminBooksPage from './AdminBooksPage'
import * as booksApi from '../../api/books'

beforeEach(() => {
  localStorage.setItem('vtee-admin-token', 'fake-admin-token')
})

test('loads and displays books from the real API', async () => {
  vi.spyOn(booksApi, 'fetchAdminBooks').mockResolvedValue([
    { id: 1, title: 'Existing Book', published: false, archived: false, hasCover: false, hasIndex: false, hasPdf: false, previewPageCount: 0 },
  ])

  render(<AdminBooksPage />)

  await waitFor(() => {
    expect(screen.getByText('Existing Book')).toBeInTheDocument()
  })
  expect(screen.getByText('Existing Book').closest('tr')).toHaveTextContent('Unpublished')
})

test('adding a book calls createBook and reloads the list', async () => {
  vi.spyOn(booksApi, 'fetchAdminBooks')
    .mockResolvedValueOnce([])
    .mockResolvedValueOnce([{ id: 2, title: 'New Book', published: false, archived: false, hasCover: false, hasIndex: false, hasPdf: false, previewPageCount: 0 }])
  const createSpy = vi.spyOn(booksApi, 'createBook').mockResolvedValue({ id: 2 })

  render(<AdminBooksPage />)
  await waitFor(() => expect(booksApi.fetchAdminBooks).toHaveBeenCalledTimes(1))

  fireEvent.change(screen.getByLabelText(/^title$/i), { target: { value: 'New Book' } })
  fireEvent.change(screen.getByLabelText(/online price/i), { target: { value: '100' } })
  fireEvent.change(screen.getByLabelText(/hard copy price/i), { target: { value: '200' } })
  fireEvent.click(screen.getByRole('button', { name: /add book/i }))

  await waitFor(() => {
    expect(screen.getByText('New Book')).toBeInTheDocument()
  })
  expect(createSpy).toHaveBeenCalledWith('fake-admin-token', { title: 'New Book', onlinePrice: 100, hardcopyPrice: 200 })
})

test('publish button calls publishBook and reloads', async () => {
  vi.spyOn(booksApi, 'fetchAdminBooks')
    .mockResolvedValueOnce([{ id: 3, title: 'Draft Book', published: false, archived: false, hasCover: false, hasIndex: false, hasPdf: false, previewPageCount: 0 }])
    .mockResolvedValueOnce([{ id: 3, title: 'Draft Book', published: true, archived: false, hasCover: false, hasIndex: false, hasPdf: false, previewPageCount: 0 }])
  const publishSpy = vi.spyOn(booksApi, 'publishBook').mockResolvedValue({ published: true })

  render(<AdminBooksPage />)
  await waitFor(() => expect(screen.getByText('Draft Book')).toBeInTheDocument())

  const row = screen.getByText('Draft Book').closest('tr')
  fireEvent.click(within(row).getByRole('button', { name: /^publish$/i }))

  await waitFor(() => {
    expect(row).toHaveTextContent('Published')
  })
  expect(publishSpy).toHaveBeenCalledWith('fake-admin-token', 3)
})
```

- [ ] **Step 4: Run test, verify it passes**

Run: `npx vitest run src/pages/admin/AdminBooksPage.test.jsx --exclude ".worktrees/**"`
Expected: PASS (3/3).

- [ ] **Step 5: Run the full frontend suite**

Run: `npx vitest run --exclude ".worktrees/**" --exclude "node_modules/**"`
Expected: all files pass, no regressions elsewhere.

- [ ] **Step 6: Commit**

```bash
git add src/pages/admin/AdminBooksPage.jsx src/pages/admin/AdminBooksPage.test.jsx
git commit -m "feat: wire AdminBooksPage to the real book-management API (CRUD + uploads)"
```

---

### Task 14: End-to-end verification and final report

**Files:** none (verification only, no code changes unless a bug is found — if so, fix it as part of this task and re-verify)

- [ ] **Step 1: Confirm the full Docker stack is healthy from a cold start**

```bash
docker compose down
docker compose up -d --build
docker compose ps
```

Expected: all 3 services `running`/`healthy`.

- [ ] **Step 2: Re-apply migrations if the volume was wiped; otherwise confirm data survived**

```bash
docker compose exec -T mysql mysql -u vtee -pchangeme vtee -e "SHOW TABLES LIKE 'book_preview_pages'; SELECT COUNT(*) FROM books;"
```

If the table is missing, re-run both `001_init.sql` and `002_book_management.sql` (and re-seed the admin via `scripts/seed_admin.php`).

- [ ] **Step 3: Re-run both backend test suites**

```bash
docker compose exec -T php php tests/jwt_test.php
docker compose exec -T php php tests/race_test.php
docker compose exec -T php php tests/middleware_test.php
docker compose exec -T php php tests/upload_validator_test.php
./backend/tests/http_test.sh
./backend/tests/book_test.sh
```

Expected: every suite clean, `0 failed`.

- [ ] **Step 4: Re-run the full frontend suite and build**

```bash
npm test
npm run build
```

Expected: all tests pass, build succeeds.

- [ ] **Step 5: The explicit end-to-end flow (requirement #9), with `npm run dev` and the Docker stack both running**

1. Open `/admin`, log in with real credentials (`admin` / whatever was seeded).
2. Create a book through the real `AdminBooksPage` form.
3. `docker compose exec -T mysql mysql -u vtee -pchangeme vtee -e "SELECT * FROM books ORDER BY id DESC LIMIT 1;"` — confirm the row exists with the title just entered.
4. Publish it through the admin UI.
5. Open `/books` in the same browser — confirm the new book appears with the compact sub-project-3 card styling intact.
6. Edit its title through the admin UI, reload `/books` — confirm the new title shows.
7. Unpublish it through the admin UI, reload `/books` — confirm it's gone.

If any step doesn't match, that's a real bug — fix it and repeat from step 1, not just the step that failed, since an earlier silent inconsistency could explain a later symptom.

- [ ] **Step 6: Final report**

Summarize for the user, matching the shape of sub-project 2's closing report: what's real and verified (list the endpoints, the admin UI actions, the end-to-end flow just walked through) versus what's still explicitly out of scope (`BookDetailPage` purchase flow, Razorpay, the reader, hard-copy, referrals/commissions — unchanged, still mock, per this plan's own non-goals). Name every modified/created file. Report the real test counts from Steps 3–4, not rounded or assumed. Note the placeholder-asset caveat (requirement #8): index-preview files uploaded during testing are test fixtures, not the client's real index pages — swapping them in later needs no schema or API change, just re-uploading through the same admin endpoints.
