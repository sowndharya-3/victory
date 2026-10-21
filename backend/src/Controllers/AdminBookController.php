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
