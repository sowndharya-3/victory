<?php

namespace App\Controllers;

use App\Database\Db;
use App\Middleware\AuthMiddleware;

class RefundController
{
    public function mine(): void
    {
        $payload = AuthMiddleware::requireRole(['customer']);
        // Ownership via the order, so admin-opened refunds on the caller's orders are listed too.
        $stmt = Db::connection()->prepare('SELECT r.* FROM refunds r JOIN orders o ON o.id = r.order_id WHERE o.user_id = ? ORDER BY r.id DESC');
        $stmt->execute([(int) $payload['sub']]);
        echo json_encode(array_map([self::class, 'format'], $stmt->fetchAll()));
    }

    /** Customer view: the admin note is shown only to explain a rejection. */
    public static function format(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'orderId' => (int) $r['order_id'],
            'status' => $r['status'],
            'reason' => $r['reason'],
            'amount' => (float) $r['amount'],
            'createdAt' => $r['created_at'],
            'adminNote' => $r['status'] === 'rejected' ? $r['admin_note'] : null,
        ];
    }
}
