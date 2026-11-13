<?php

namespace App\Controllers;

use App\Database\Db;
use App\Http\ApiException;
use App\Middleware\AuthMiddleware;
use App\Razorpay\GatewayFactory;
use App\Refunds\RefundService;

class AdminRefundController
{
    private const STATUSES = ['requested', 'approved', 'processing', 'refunded', 'rejected', 'failed'];

    public function list(): void
    {
        AuthMiddleware::requireRole(['admin']);
        $status = $_GET['status'] ?? null;
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw new ApiException('Validation failed', 400, 'validation_error', ['status' => 'Must be one of ' . implode(', ', self::STATUSES)]);
        }
        // ponytail: newest 200, no pagination; add paging when the admin screen needs it.
        $sql = 'SELECT r.*, o.order_number, o.purchase_type, o.user_id FROM refunds r JOIN orders o ON o.id = r.order_id'
            . ($status !== null ? ' WHERE r.status = ?' : '') . ' ORDER BY r.id DESC LIMIT 200';
        $stmt = Db::connection()->prepare($sql);
        $stmt->execute($status !== null ? [$status] : []);
        echo json_encode(array_map([self::class, 'format'], $stmt->fetchAll()));
    }

    public function approve(string $id): void
    {
        $admin = AuthMiddleware::requireRole(['admin']);
        $data = json_decode(file_get_contents('php://input'), true);
        $note = is_array($data) && is_string($data['note'] ?? null) ? $data['note'] : null;
        self::respond((new RefundService(GatewayFactory::make()))->approve((int) $id, (int) $admin['sub'], $note), 200);
    }

    public function reject(string $id): void
    {
        $admin = AuthMiddleware::requireRole(['admin']);
        $data = json_decode(file_get_contents('php://input'), true);
        $note = is_array($data) && is_string($data['note'] ?? null) ? $data['note'] : '';
        self::respond((new RefundService(GatewayFactory::make()))->reject((int) $id, (int) $admin['sub'], $note), 200);
    }

    public function open(string $orderId): void
    {
        $admin = AuthMiddleware::requireRole(['admin']);
        $data = json_decode(file_get_contents('php://input'), true);
        $reason = is_array($data) && is_string($data['reason'] ?? null) ? $data['reason'] : '';
        $res = (new RefundService(GatewayFactory::make()))->adminOpen((int) $orderId, (int) $admin['sub'], $reason);
        self::respond($res['refund'], $res['created'] ? 201 : 200);
    }

    /** A gateway failure is already recorded as `failed` (committed); surface it as 502 so the admin retries. */
    private static function respond(array $refund, int $status): void
    {
        if ($refund['status'] === 'failed') {
            throw new ApiException('Refund failed at the payment gateway; approve again to retry', 502, 'gateway_error');
        }
        http_response_code($status);
        echo json_encode(['refund' => self::format($refund)]);
    }

    public static function format(array $r): array
    {
        if (!isset($r['order_number'])) {
            $stmt = Db::connection()->prepare('SELECT order_number, purchase_type, user_id FROM orders WHERE id = ?');
            $stmt->execute([$r['order_id']]);
            $r += $stmt->fetch();
        }
        return [
            'id' => (int) $r['id'],
            'orderId' => (int) $r['order_id'],
            'orderNumber' => $r['order_number'],
            'purchaseType' => $r['purchase_type'],
            'userId' => (int) $r['user_id'],
            'status' => $r['status'],
            'reason' => $r['reason'],
            'adminNote' => $r['admin_note'],
            'amount' => (float) $r['amount'],
            'razorpayRefundId' => $r['razorpay_refund_id'],
            'requestedByUserId' => $r['requested_by_user_id'] === null ? null : (int) $r['requested_by_user_id'],
            'reviewedByAdminId' => $r['reviewed_by_admin_id'] === null ? null : (int) $r['reviewed_by_admin_id'],
            'createdAt' => $r['created_at'],
            'updatedAt' => $r['updated_at'],
        ];
    }
}
