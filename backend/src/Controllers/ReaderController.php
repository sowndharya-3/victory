<?php

namespace App\Controllers;

use App\Database\Db;
use App\Http\ApiException;
use App\Middleware\AuthMiddleware;
use App\Reader\PdfPageRenderer;
use App\Support\RateLimiter;

/**
 * Secure reader: authorised page images only; the original PDF has no route.
 * Access = a non-revoked book_access row for (caller, book). Customers only (admins get no pass).
 * A book later unpublished or archived stays readable by its owners: they paid for it.
 */
class ReaderController
{
    public function access(string $id): void
    {
        $userId = (int) AuthMiddleware::requireRole(['customer'])['sub'];
        $bookId = self::positiveInt($id);
        $status = self::accessStatus($userId, $bookId);
        $body = ['owned' => $status === 'active', 'status' => $status];
        if ($status === 'active') {
            $body['pageCount'] = PdfPageRenderer::pageCount($bookId);
        }
        header('Cache-Control: private, no-store');
        echo json_encode($body);
    }

    public function page(string $id, string $n): void
    {
        $userId = (int) AuthMiddleware::requireRole(['customer'])['sub'];
        // Per user (not IP); counts every call, so probing other books is throttled too.
        RateLimiter::hit('reader-page:' . $userId, 240, 300);
        $bookId = self::positiveInt($id);
        if (self::accessStatus($userId, $bookId) !== 'active') {
            throw new ApiException('You do not have access to this book', 403, 'forbidden');
        }
        $path = PdfPageRenderer::pagePath($bookId, self::positiveInt($n));

        header('Content-Type: image/jpeg');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }

    /** Route segments are strings: only 1-9 plain digits are ids, anything else is 404. */
    public static function positiveInt(string $raw): int
    {
        if (!preg_match('/^\d{1,9}$/', $raw)) {
            throw new ApiException('Not found', 404, 'not_found');
        }
        return (int) $raw;
    }

    /** @return 'active'|'revoked'|'none' */
    private static function accessStatus(int $userId, int $bookId): string
    {
        $stmt = Db::connection()->prepare('SELECT revoked_at FROM book_access WHERE user_id = ? AND book_id = ?');
        $stmt->execute([$userId, $bookId]);
        $row = $stmt->fetch();
        return $row === false ? 'none' : ($row['revoked_at'] === null ? 'active' : 'revoked');
    }
}
