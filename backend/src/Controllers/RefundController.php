<?php

namespace App\Controllers;

use App\Database\Db;
use App\Middleware\AuthMiddleware;
use App\Razorpay\GatewayFactory;
use App\Refunds\RefundService;

class RefundController
{
    public function request(string $id): void
    {
        $payload = AuthMiddleware::requireRole(['customer']);
        $data = json_decode(file_get_contents('php://input'), true);
        $reason = is_array($data) && is_string($data['reason'] ?? null) ? $data['reason'] : '';
        $res = (new RefundService(GatewayFactory::make()))->request((int) $payload['sub'], (int) $id, $reason);
        http_response_code($res['created'] ? 201 : 200);
        echo json_encode(['refund' => self::format($res['refund'])]);
    }

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
