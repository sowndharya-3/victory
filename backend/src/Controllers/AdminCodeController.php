<?php

namespace App\Controllers;

use App\Codes\AccessCodeService;
use App\Codes\CodeCipher;
use App\Codes\CodeGenerator;
use App\Database\Db;
use App\Http\ApiException;
use App\Middleware\AuthMiddleware;
use App\Support\RateLimiter;

/** Admin code tools. Only reissue ever returns a plaintext code (once, to hand to the customer). */
class AdminCodeController
{
    public function verify(): void
    {
        $admin = AuthMiddleware::requireRole(['admin']);
        RateLimiter::hit('admin-code-verify:' . (int) $admin['sub'], 30, 600);
        $data = AdminOrderController::body();
        if (!is_string($data['code'] ?? null)) {
            throw new ApiException('Validation failed', 400, 'validation_error', ['code' => 'Required']);
        }
        $code = strlen($data['code']) <= 64 ? CodeGenerator::normalise($data['code']) : '';
        $notFound = new ApiException('Code not found', 404, 'not_found');
        if (!preg_match(CodeGenerator::DIGITAL_PATTERN, $code)) {
            throw $notFound;
        }
        $stmt = Db::connection()->prepare(
            'SELECT ac.status, ac.redeemed_at, b.title, o.order_number, o.status AS order_status
               FROM access_codes ac JOIN orders o ON o.id = ac.order_id JOIN books b ON b.id = ac.book_id
              WHERE ac.code_hash = ?'
        );
        $stmt->execute([CodeCipher::hash($code)]);
        $c = $stmt->fetch();
        if (!$c) {
            throw $notFound;
        }
        echo json_encode([
            'status' => $c['status'],
            'bookTitle' => $c['title'],
            'orderNumber' => $c['order_number'],
            'orderStatus' => $c['order_status'],
            'redeemed' => $c['redeemed_at'] !== null,
        ]);
    }

    public function revoke(string $id): void
    {
        $admin = AuthMiddleware::requireRole(['admin']);
        $orderId = AdminOrderController::id($id);
        $reason = AdminOrderController::text(AdminOrderController::body(), 'reason', 255, true);
        $stmt = Db::connection()->prepare('SELECT id FROM access_codes WHERE order_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$orderId]);
        $codeId = $stmt->fetchColumn();
        if ($codeId === false) {
            throw new ApiException('Access code not found', 404, 'not_found');
        }
        // ponytail: a reissue racing between this read and the lock leaves the new code active (revoke of the old one is a no-op); the admin sees it in the returned detail.
        AccessCodeService::revoke((int) $codeId, (int) $admin['sub'], $reason);
        echo json_encode(AdminOrderController::detail($orderId));
    }

    public function reissue(string $id): void
    {
        $admin = AuthMiddleware::requireRole(['admin']);
        $orderId = AdminOrderController::id($id);
        RateLimiter::hit('admin-code-reissue:' . (int) $admin['sub'], 30, 600);
        $reason = AdminOrderController::text(AdminOrderController::body(), 'reason', 255, true);
        $code = AccessCodeService::reissue($orderId, (int) $admin['sub'], null, $reason);
        header('Cache-Control: no-store');
        echo json_encode(['code' => $code]);
    }
}
