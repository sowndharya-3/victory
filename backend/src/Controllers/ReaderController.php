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
            try {
                $body['pageCount'] = PdfPageRenderer::pageCount($bookId);
            } catch (ApiException $e) {
                // Owned but no usable PDF yet: no pageCount; the reader shows "not available to read yet".
                if ($e->errorCode !== 'render_failed') {
                    throw $e;
                }
            }
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
        self::sendJpeg(PdfPageRenderer::pagePath($bookId, self::positiveInt($n)));
    }

    /** Opens the cached page before any image header: a file removed by a re-upload meanwhile is a JSON 503, never an empty 200 JPEG. */
    public static function sendJpeg(string $path): void
    {
        $fh = @fopen($path, 'rb');
        $size = $fh === false ? false : fstat($fh)['size'];
        if (!$size) {
            if ($fh !== false) {
                fclose($fh);
            }
            throw new ApiException('The page is being prepared, please retry shortly', 503, 'render_busy');
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . $size);
        fpassthru($fh);
        fclose($fh);
    }

    /** Route segments are strings: only 1-9 plain digits without a leading zero are ids, anything else is 404. */
    public static function positiveInt(string $raw): int
    {
        if (!preg_match('/^[1-9][0-9]{0,8}\z/', $raw)) {
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
