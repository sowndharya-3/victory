<?php

namespace App\Controllers;

use App\Codes\AccessCodeService;
use App\Database\Db;
use App\Http\ApiException;
use App\Middleware\AuthMiddleware;
use App\Support\RateLimiter;

class AccessCodeController
{
    public function reveal(string $id): void
    {
        $payload = AuthMiddleware::requireRole(['customer']);
        $code = AccessCodeService::revealForOwner((int) $payload['sub'], (int) $id);
        if ($code === null) {
            throw new ApiException('Access code not found', 404, 'not_found');
        }
        header('Cache-Control: no-store');
        echo json_encode(['code' => $code]);
    }

    public function redeem(): void
    {
        $payload = AuthMiddleware::requireRole(['customer']);
        $userId = (int) $payload['sub'];
        $buckets = ['code-redeem-user:' . $userId => 5, 'code-redeem-ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') => 20];
        // Only failed attempts count: reserve a slot in each bucket, give it back on success.
        $reserved = [];
        try {
            foreach ($buckets as $bucket => $max) {
                RateLimiter::hit($bucket, $max, 900);
                $reserved[] = $bucket;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $input = is_array($data) && is_string($data['code'] ?? null) ? $data['code'] : '';
            $result = AccessCodeService::redeem($userId, $input);
        } catch (ApiException $e) {
            if ($e->errorCode === 'rate_limited') {
                array_map(fn ($b) => RateLimiter::release($b, 900), $reserved); // no attempt was made
            }
            throw $e;
        }
        array_map(fn ($b) => RateLimiter::release($b, 900), $reserved);
        echo json_encode($result);
    }

    public function reissue(string $id): void
    {
        $payload = AuthMiddleware::requireRole(['customer']);
        $userId = (int) $payload['sub'];
        $stmt = Db::connection()->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ? AND status = 'paid' AND purchase_type = 'digital'");
        $stmt->execute([(int) $id, $userId]);
        if (!$stmt->fetch()) {
            throw new ApiException('Order not found', 404, 'not_found');
        }
        RateLimiter::hit('code-reissue:' . $userId, 3, 86400);
        $code = AccessCodeService::reissue((int) $id, null, $userId, 'customer_request');
        header('Cache-Control: no-store');
        echo json_encode(['code' => $code]);
    }

    public function library(): void
    {
        $payload = AuthMiddleware::requireRole(['customer']);
        $userId = (int) $payload['sub'];
        $db = Db::connection();

        $stmt = $db->prepare(
            'SELECT ba.book_id, b.title, b.cover_url, ba.order_id, COALESCE(o.paid_at, ba.granted_at) AS purchased_at, ba.revoked_at
               FROM book_access ba JOIN books b ON b.id = ba.book_id LEFT JOIN orders o ON o.id = ba.order_id
              WHERE ba.user_id = ? ORDER BY ba.id DESC'
        );
        $stmt->execute([$userId]);
        $books = array_map(fn ($r) => [
            'bookId' => (int) $r['book_id'],
            'title' => $r['title'],
            'coverUrl' => $r['cover_url'],
            'orderId' => $r['order_id'] === null ? null : (int) $r['order_id'],
            'purchasedAt' => $r['purchased_at'],
            'accessStatus' => $r['revoked_at'] === null ? 'active' : 'revoked',
        ], $stmt->fetchAll());

        // Paid digital orders whose current code is still waiting to be redeemed.
        $stmt = $db->prepare(
            "SELECT o.id, o.book_id, b.title, b.cover_url, o.paid_at
               FROM orders o JOIN books b ON b.id = o.book_id
              WHERE o.user_id = ? AND o.status = 'paid' AND o.purchase_type = 'digital'
                AND EXISTS (SELECT 1 FROM access_codes ac WHERE ac.order_id = o.id AND ac.status = 'active')
              ORDER BY o.id DESC"
        );
        $stmt->execute([$userId]);
        $pending = array_map(fn ($r) => [
            'orderId' => (int) $r['id'],
            'bookId' => (int) $r['book_id'],
            'title' => $r['title'],
            'coverUrl' => $r['cover_url'],
            'purchasedAt' => $r['paid_at'],
        ], $stmt->fetchAll());

        echo json_encode(['books' => $books, 'pendingRedemptions' => $pending]);
    }
}
