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
