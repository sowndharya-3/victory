<?php

namespace App\Controllers;

use App\Database\Db;
use App\Http\ApiException;
use App\Middleware\AuthMiddleware;
use App\Reader\PdfPageRenderer;
use App\Support\Money;
use App\Upload\FileValidator;
use App\Validation\Validator;

class AdminBookController
{
    private const UPLOAD_PUBLIC_DIR = __DIR__ . '/../../public/uploads/books';
    private const UPLOAD_PRIVATE_DIR = __DIR__ . '/../../storage/books';

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
        $data = json_decode(file_get_contents('php://input'), true);
        $data = is_array($data) ? $data : [];
        Validator::required($data, ['title', 'onlinePrice', 'hardcopyPrice']);
        $row = self::validateFields($data) + ['description' => null, 'category' => null];

        $db = Db::connection();
        $stmt = $db->prepare(
            'INSERT INTO books (title, description, category, online_price, hardcopy_price, published, archived)
             VALUES (:title, :description, :category, :online_price, :hardcopy_price, 0, 0)'
        );
        $stmt->execute($row);

        $id = (string)$db->lastInsertId();
        http_response_code(201);
        echo json_encode(self::formatAdmin($this->findOrFail($id)));
    }

    public function update(string $id): void
    {
        AuthMiddleware::requireRole(['admin']);
        $book = $this->findOrFail($id);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data) || ($data && array_is_list($data))) {
            throw new ApiException('Validation failed', 400, 'validation_error', ['body' => 'Must be a JSON object']);
        }
        $row = self::validateFields($data);
        self::requireSellable($row + $book); // a published book must stay sellable (same rule as publish)
        if ($row) {
            $set = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys($row)));
            Db::connection()->prepare("UPDATE books SET {$set} WHERE id = :id")->execute($row + ['id' => $id]);
        }

        echo json_encode(self::formatAdmin($this->findOrFail($id)));
    }

    public function publish(string $id): void
    {
        AuthMiddleware::requireRole(['admin']);
        self::requireSellable(['published' => 1] + $this->findOrFail($id));
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

    public function uploadCover(string $id): void
    {
        AuthMiddleware::requireRole(['admin']);
        $book = $this->findOrFail($id);
        $bookId = (int)$book['id'];
        $ext = FileValidator::validateImage($_FILES['file'] ?? []);
        $dir = self::UPLOAD_PUBLIC_DIR . "/{$bookId}";
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new ApiException('Failed to create upload directory', 500, 'upload_failed');
        }
        $destination = "{$dir}/cover.{$ext}";
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
            throw new ApiException('Failed to save uploaded file', 500, 'upload_failed');
        }
        $url = "/uploads/books/{$bookId}/cover.{$ext}";
        Db::connection()->prepare('UPDATE books SET cover_url = :url WHERE id = :id')->execute(['url' => $url, 'id' => $id]);
        echo json_encode(['coverUrl' => $url]);
    }

    public function uploadIndex(string $id): void
    {
        AuthMiddleware::requireRole(['admin']);
        $book = $this->findOrFail($id);
        $bookId = (int)$book['id'];
        $ext = FileValidator::validateImageOrPdf($_FILES['file'] ?? []);
        $dir = self::UPLOAD_PUBLIC_DIR . "/{$bookId}";
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new ApiException('Failed to create upload directory', 500, 'upload_failed');
        }
        $destination = "{$dir}/index.{$ext}";
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
            throw new ApiException('Failed to save uploaded file', 500, 'upload_failed');
        }
        $url = "/uploads/books/{$bookId}/index.{$ext}";
        Db::connection()->prepare('UPDATE books SET index_file_path = :url WHERE id = :id')->execute(['url' => $url, 'id' => $id]);
        echo json_encode(['indexUrl' => $url]);
    }

    public function uploadPreviewPage(string $id, string $pageNumber): void
    {
        AuthMiddleware::requireRole(['admin']);
        $book = $this->findOrFail($id);
        $bookId = (int)$book['id'];
        $page = $this->validatePageNumber($pageNumber);
        $ext = FileValidator::validateImage($_FILES['file'] ?? []);
        $dir = self::UPLOAD_PUBLIC_DIR . "/{$bookId}";
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new ApiException('Failed to create upload directory', 500, 'upload_failed');
        }
        $destination = "{$dir}/preview-{$page}.{$ext}";
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
            throw new ApiException('Failed to save uploaded file', 500, 'upload_failed');
        }
        $url = "/uploads/books/{$bookId}/preview-{$page}.{$ext}";

        Db::connection()->prepare(
            'INSERT INTO book_preview_pages (book_id, page_number, file_path) VALUES (:book_id, :page_number, :url)
             ON DUPLICATE KEY UPDATE file_path = :url2'
        )->execute(['book_id' => $id, 'page_number' => $page, 'url' => $url, 'url2' => $url]);

        echo json_encode(['pageNumber' => $page, 'url' => $url]);
    }

    public function uploadPdf(string $id): void
    {
        AuthMiddleware::requireRole(['admin']);
        $book = $this->findOrFail($id);
        $bookId = (int)$book['id'];
        FileValidator::validatePdf($_FILES['file'] ?? []);
        $dir = self::UPLOAD_PRIVATE_DIR . "/{$bookId}";
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new ApiException('Failed to create upload directory', 500, 'upload_failed');
        }
        $destination = "{$dir}/book.pdf";
        // Same-directory temp + rename: readers see the old PDF or the new one, never a half-written file.
        $tmp = "{$dir}/.tmp-upload-" . bin2hex(random_bytes(6)) . '.pdf';
        try {
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $tmp) || !rename($tmp, $destination)) {
                throw new ApiException('Failed to save uploaded file', 500, 'upload_failed');
            }
        } finally {
            @unlink($tmp);
        }
        Db::connection()->prepare('UPDATE books SET digital_file_path = :path WHERE id = :id')
            ->execute(['path' => $destination, 'id' => $id]);
        PdfPageRenderer::invalidate($bookId); // stale page count / pages belong to the old PDF
        echo json_encode(['pdfUploaded' => true]);
    }

    public function deletePreviewPage(string $id, string $pageNumber): void
    {
        AuthMiddleware::requireRole(['admin']);
        $book = $this->findOrFail($id);
        $bookId = (int)$book['id'];
        $page = $this->validatePageNumber($pageNumber);
        $db = Db::connection();
        $stmt = $db->prepare('SELECT file_path FROM book_preview_pages WHERE book_id = :book_id AND page_number = :page_number');
        $stmt->execute(['book_id' => $id, 'page_number' => $page]);
        $row = $stmt->fetch();
        if ($row) {
            $path = self::UPLOAD_PUBLIC_DIR . "/{$bookId}/" . basename($row['file_path']);
            if (file_exists($path)) {
                unlink($path);
            }
            $db->prepare('DELETE FROM book_preview_pages WHERE book_id = :book_id AND page_number = :page_number')
                ->execute(['book_id' => $id, 'page_number' => $page]);
        }
        echo json_encode(['deleted' => true]);
    }

    private const MAX_PRICE_PAISE = 10_000_000; // Rs 1,00,000 per book: far above any real price, catches typos

    /**
     * Validates the editable fields present in $data (create has already required title and both prices) and
     * returns column => normalised value. Title/category: Unicode-trimmed, no control characters, title 1-255
     * chars, category <= 100 (empty = null). Description: up to 65535 bytes of UTF-8, line breaks and tabs
     * allowed, no other control characters (blank = null). Prices: 0..100000 with <= 2 decimals (199, 199.5, "199.50").
     */
    private static function validateFields(array $data): array
    {
        $row = [];
        $errors = [];
        if (array_key_exists('title', $data)) {
            $v = is_string($data['title']) ? AdminOrderController::clean($data['title']) : null;
            if ($v === null || $v === '' || mb_strlen($v) > 255) {
                $errors['title'] = 'Required, 1-255 characters, no control characters';
            } else {
                $row['title'] = $v;
            }
        }
        if (array_key_exists('category', $data)) {
            $v = $data['category'] === null ? '' : (is_string($data['category']) ? AdminOrderController::clean($data['category']) : null);
            if ($v === null || mb_strlen($v) > 100) {
                $errors['category'] = 'At most 100 characters, no control characters';
            } else {
                $row['category'] = $v === '' ? null : $v;
            }
        }
        if (array_key_exists('description', $data)) {
            $v = $data['description'];
            if ($v !== null && (!is_string($v) || strlen($v) > 65535 || !mb_check_encoding($v, 'UTF-8')
                || preg_match('/[^\P{Cc}\t\n\r]/u', $v))) {
                $errors['description'] = 'Text up to 65535 bytes, no control characters other than line breaks and tabs';
            } else {
                $row['description'] = $v === null || trim($v) === '' ? null : $v;
            }
        }
        foreach (['onlinePrice' => 'online_price', 'hardcopyPrice' => 'hardcopy_price'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $v = Money::parseAmount($data[$key], self::MAX_PRICE_PAISE);
                if ($v === null) {
                    $errors[$key] = 'A price from 0 to 100000 with at most 2 decimals';
                } else {
                    $row[$column] = $v;
                }
            }
        }
        if ($errors) {
            throw new ApiException('Validation failed', 400, 'validation_error', $errors);
        }
        return $row;
    }

    /** A published book needs both prices >= 1: checkout cannot charge 0 (Razorpay's minimum is Rs 1). */
    private static function requireSellable(array $book): void
    {
        if (!(int) $book['published']) {
            return;
        }
        $errors = [];
        foreach (['online_price' => 'onlinePrice', 'hardcopy_price' => 'hardcopyPrice'] as $column => $key) {
            if (Money::toPaise((string) $book[$column]) < 100) {
                $errors[$key] = 'A published book needs a price of at least 1';
            }
        }
        if ($errors) {
            throw new ApiException('Validation failed', 400, 'validation_error', $errors);
        }
    }

    private function validatePageNumber(string $pageNumber): int
    {
        if (!ctype_digit($pageNumber) || (int)$pageNumber < 1 || (int)$pageNumber > 20) {
            throw new ApiException('Invalid page number', 400, 'invalid_page_number');
        }
        return (int)$pageNumber;
    }

    private function setStatus(string $id, array $fields): void
    {
        AuthMiddleware::requireRole(['admin']);
        $this->findOrFail($id);
        $set = implode(', ', array_map(fn($k) => "{$k} = :{$k}", array_keys($fields)));
        Db::connection()->prepare("UPDATE books SET {$set} WHERE id = :id")->execute($fields + ['id' => $id]);
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
