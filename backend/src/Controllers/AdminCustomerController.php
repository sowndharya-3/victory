<?php

namespace App\Controllers;

use App\Database\Db;
use App\Http\ApiException;
use App\Middleware\AuthMiddleware;

class AdminCustomerController
{
    private const SELECT = "SELECT u.id, u.name, u.email, u.phone, u.created_at,
            (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS order_count,
            (SELECT COALESCE(SUM(o.amount), 0) FROM orders o WHERE o.user_id = u.id AND o.status = 'paid') AS paid_total
          FROM users u";

    public function list(): void
    {
        AuthMiddleware::requireRole(['admin']);
        [$page, $perPage] = AdminOrderController::paging();
        $q = AdminOrderController::query();
        $where = '';
        $params = [];
        if ($q !== '') {
            $where = " WHERE u.name LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!' OR u.phone LIKE ? ESCAPE '!'";
            $params = array_fill(0, 3, AdminOrderController::like($q));
        }
        $db = Db::connection();
        $count = $db->prepare('SELECT COUNT(*) FROM users u' . $where);
        $count->execute($params);
        $stmt = $db->prepare(self::SELECT . $where . ' ORDER BY u.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        $stmt->execute($params);
        echo json_encode(['items' => array_map([self::class, 'format'], $stmt->fetchAll()), 'page' => $page, 'perPage' => $perPage, 'total' => (int) $count->fetchColumn()]);
    }

    public function show(string $id): void
    {
        AuthMiddleware::requireRole(['admin']);
        $userId = AdminOrderController::id($id);
        $db = Db::connection();
        $stmt = $db->prepare(self::SELECT . ' WHERE u.id = ?');
        $stmt->execute([$userId]);
        $u = $stmt->fetch();
        if (!$u) {
            throw new ApiException('Customer not found', 404, 'not_found');
        }
        // ponytail: newest 200 orders; page them if one customer ever has more.
        $o = $db->prepare(AdminOrderController::select() . ' WHERE o.user_id = ? ORDER BY o.created_at DESC, o.id DESC LIMIT 200');
        $o->execute([$userId]);
        echo json_encode(self::format($u) + ['orders' => array_map([AdminOrderController::class, 'row'], $o->fetchAll())]);
    }

    private static function format(array $u): array
    {
        return [
            'id' => (int) $u['id'],
            'name' => $u['name'],
            'email' => $u['email'],
            'phone' => $u['phone'],
            'createdAt' => $u['created_at'],
            'orderCount' => (int) $u['order_count'],
            'paidTotal' => (float) $u['paid_total'],
        ];
    }
}
