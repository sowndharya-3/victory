<?php

namespace App\Controllers;

use App\Codes\CodeCipher;
use App\Codes\CodeGenerator;
use App\Database\Db;
use App\Http\ApiException;
use App\Middleware\AuthMiddleware;
use App\Orders\OrderFormatter;

/** Admin order list/detail/fulfilment + audit log. Never returns code plaintext, code_enc or code_hash. */
class AdminOrderController
{
    private const TYPES = ['digital', 'hardcopy'];
    private const STATUSES = ['pending', 'paid', 'failed', 'cancelled', 'refunded'];
    private const FULFILMENT = ['not_applicable', 'confirmed', 'processing', 'shipped', 'out_for_delivery', 'delivered', 'cancelled'];
    /** Legal forward moves; out_for_delivery may be skipped; cancelled from any non-delivered state. */
    private const NEXT = [
        'confirmed' => ['processing', 'cancelled'],
        'processing' => ['shipped', 'cancelled'],
        'shipped' => ['out_for_delivery', 'delivered', 'cancelled'],
        'out_for_delivery' => ['delivered', 'cancelled'],
    ];

    /** OrderFormatter::SELECT (order + book + latest code status/last4) plus customer and Razorpay payment id. */
    public static function select(): string
    {
        $sql = str_replace(
            'SELECT o.*,',
            "SELECT o.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone,
                (SELECT p.razorpay_payment_id FROM payments p WHERE p.order_id = o.id AND p.razorpay_payment_id IS NOT NULL
                  ORDER BY p.status IN ('captured', 'refunded') DESC, p.id DESC LIMIT 1) AS razorpay_payment_id,",
            OrderFormatter::SELECT,
            $count
        );
        if ($count !== 1) { // OrderFormatter::SELECT changed shape: fail loudly, never query without the customer columns
            throw new \LogicException('OrderFormatter::SELECT no longer starts with "SELECT o.*,"');
        }
        return $sql . ' JOIN users u ON u.id = o.user_id';
    }

    public function list(): void
    {
        AuthMiddleware::requireRole(['admin']);
        [$page, $perPage] = self::paging();
        $where = [];
        $params = [];
        foreach (['type' => ['o.purchase_type', self::TYPES], 'status' => ['o.status', self::STATUSES], 'fulfilment' => ['o.fulfilment_status', self::FULFILMENT]] as $key => [$col, $allowed]) {
            if (isset($_GET[$key]) && $_GET[$key] !== '') {
                if (!in_array($_GET[$key], $allowed, true)) {
                    throw new ApiException('Validation failed', 400, 'validation_error', [$key => 'Must be one of ' . implode(', ', $allowed)]);
                }
                $where[] = "$col = ?";
                $params[] = $_GET[$key];
            }
        }
        $q = self::query();
        if ($q !== '') {
            $like = self::like($q);
            $or = ['o.order_number LIKE ? ESCAPE \'!\'', 'u.name LIKE ? ESCAPE \'!\'', 'u.email LIKE ? ESCAPE \'!\'', 'u.phone LIKE ? ESCAPE \'!\'', 'o.tracking_code = ?'];
            array_push($params, $like, $like, $like, $like, strtoupper($q));
            $code = CodeGenerator::normalise($q);
            if (preg_match('/^[0-9A-HJKMNP-TV-Z]{4}$/', $code)) {
                $or[] = 'EXISTS (SELECT 1 FROM access_codes x WHERE x.order_id = o.id AND x.code_last4 = ?)';
                $params[] = $code;
            }
            if (preg_match(CodeGenerator::DIGITAL_PATTERN, $code)) {
                $or[] = 'o.id = (SELECT order_id FROM access_codes WHERE code_hash = ?)'; // exact, via the hash
                $params[] = CodeCipher::hash($code);
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $db = Db::connection();
        $count = $db->prepare('SELECT COUNT(*) FROM orders o JOIN users u ON u.id = o.user_id' . $sqlWhere);
        $count->execute($params);
        // LIMIT/OFFSET are validated ints (emulated prepares would quote bound values).
        $stmt = $db->prepare(self::select() . $sqlWhere . ' ORDER BY o.created_at DESC, o.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        $stmt->execute($params);
        echo json_encode(['items' => array_map([self::class, 'row'], $stmt->fetchAll()), 'page' => $page, 'perPage' => $perPage, 'total' => (int) $count->fetchColumn()]);
    }

    public function show(string $id): void
    {
        AuthMiddleware::requireRole(['admin']);
        echo json_encode(self::detail(self::id($id)));
    }

    public function fulfilment(string $id): void
    {
        $admin = AuthMiddleware::requireRole(['admin']);
        $orderId = self::id($id);
        $data = self::body();
        $to = $data['status'] ?? null;
        if (!is_string($to) || !in_array($to, self::FULFILMENT, true)) {
            throw new ApiException('Validation failed', 400, 'validation_error', ['status' => 'Must be one of ' . implode(', ', self::FULFILMENT)]);
        }
        $courier = $trackingNumber = null;
        if ($to === 'shipped') {
            $courier = self::text($data, 'courier', 100, true);
            $trackingNumber = self::text($data, 'trackingNumber', 100, false);
        }
        $reason = $to === 'cancelled' ? self::text($data, 'reason', 255, false) : null;

        $db = Db::connection();
        $db->beginTransaction();
        try {
            // Row lock: concurrent updates (or a refund finalising) serialise; the transition is checked on fresh state.
            $stmt = $db->prepare('SELECT purchase_type, status, fulfilment_status FROM orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$orderId]);
            $o = $stmt->fetch();
            if (!$o) {
                throw new ApiException('Order not found', 404, 'not_found');
            }
            if ($o['purchase_type'] !== 'hardcopy' || $o['status'] !== 'paid') {
                throw new ApiException('Only paid hard-copy orders have fulfilment', 400, 'invalid_transition');
            }
            $from = $o['fulfilment_status'];
            // Not yet shipped: the money goes back through the refund flow, which cancels fulfilment itself on completion.
            if ($to === 'cancelled' && in_array($from, ['confirmed', 'processing'], true)) {
                throw new ApiException('Cancel an unshipped paid order by refunding it: POST /api/admin/orders/{id}/refund', 409, 'refund_required');
            }
            if (!in_array($to, self::NEXT[$from] ?? [], true)) {
                throw new ApiException("Cannot move fulfilment from $from to $to", 400, 'invalid_transition');
            }
            if ($to === 'cancelled' && $reason === null) {
                throw new ApiException('Validation failed', 400, 'validation_error', ['reason' => 'Required, 1-255 characters']);
            }
            if ($to === 'shipped') {
                $db->prepare('UPDATE orders SET fulfilment_status = ?, courier = ?, tracking_number = ?, shipped_at = NOW() WHERE id = ?')
                    ->execute([$to, $courier, $trackingNumber, $orderId]);
            } else {
                $db->prepare('UPDATE orders SET fulfilment_status = ? WHERE id = ?')->execute([$to, $orderId]);
            }
            $db->prepare("INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, detail) VALUES (?, 'order.fulfilment', 'order', ?, ?)")
                ->execute([(int) $admin['sub'], $orderId, json_encode(['from' => $from, 'to' => $to, 'courier' => $courier, 'trackingNumber' => $trackingNumber] + ($reason !== null ? ['reason' => $reason] : []))]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        echo json_encode(self::detail($orderId));
    }

    public function auditLogs(): void
    {
        AuthMiddleware::requireRole(['admin']);
        [$page, $perPage] = self::paging();
        $where = [];
        $params = [];
        $type = $_GET['entityType'] ?? '';
        if ($type !== '') {
            if (!is_string($type) || !preg_match('/^[a-z_]{1,40}$/', $type)) {
                throw new ApiException('Validation failed', 400, 'validation_error', ['entityType' => 'Invalid entity type']);
            }
            $where[] = 'entity_type = ?';
            $params[] = $type;
        }
        $entityId = $_GET['entityId'] ?? '';
        if ($entityId !== '') {
            if (!is_string($entityId) || !ctype_digit($entityId) || strlen($entityId) > 10) {
                throw new ApiException('Validation failed', 400, 'validation_error', ['entityId' => 'Must be a positive integer']);
            }
            $where[] = 'entity_id = ?';
            $params[] = (int) $entityId;
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $db = Db::connection();
        $count = $db->prepare('SELECT COUNT(*) FROM audit_logs' . $sqlWhere);
        $count->execute($params);
        $stmt = $db->prepare('SELECT * FROM audit_logs' . $sqlWhere . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        $stmt->execute($params);
        echo json_encode(['items' => array_map([self::class, 'audit'], $stmt->fetchAll()), 'page' => $page, 'perPage' => $perPage, 'total' => (int) $count->fetchColumn()]);
    }

    /** List row; $o from self::select(). */
    public static function row(array $o): array
    {
        $f = OrderFormatter::format($o);
        return [
            'id' => $f['id'],
            'orderNumber' => $f['orderNumber'],
            'customer' => ['id' => (int) $o['user_id'], 'name' => $o['user_name'], 'email' => $o['user_email']],
            'book' => ['id' => $f['bookId'], 'title' => $f['bookTitle']],
            'purchaseType' => $f['purchaseType'],
            'status' => $f['status'],
            'amount' => $f['total'],
            'paidAt' => $f['paidAt'],
            'createdAt' => $f['createdAt'],
            'razorpayPaymentId' => $o['razorpay_payment_id'],
            'digitalCode' => $f['digitalCode'],
            'trackingCode' => $f['trackingCode'],
            'fulfilmentStatus' => $f['fulfilmentStatus'],
            'adminFlag' => $o['admin_flag'],
        ];
    }

    public static function detail(int $id): array
    {
        $db = Db::connection();
        $stmt = $db->prepare(self::select() . ' WHERE o.id = ?');
        $stmt->execute([$id]);
        $o = $stmt->fetch();
        if (!$o) {
            throw new ApiException('Order not found', 404, 'not_found');
        }
        $a = $db->prepare('SELECT * FROM hardcopy_addresses WHERE order_id = ?');
        $a->execute([$id]);
        $f = OrderFormatter::format($o, $a->fetch() ?: null);

        $out = self::row($o);
        $out['customer']['phone'] = $o['user_phone'];
        $out['breakdown'] = [
            'subtotal' => $f['subtotal'], 'shipping' => $f['shipping'], 'gst' => $f['gst'], 'gstPercent' => $f['gstPercent'],
            'gstMode' => $f['gstMode'], 'total' => $f['total'], 'currency' => $f['currency'],
        ];
        $out['address'] = $f['address'] ?? null;
        $out['courier'] = $o['courier'];
        $out['trackingNumber'] = $o['tracking_number'];
        $out['shippedAt'] = $o['shipped_at'];
        $out['referralCode'] = $o['referral_code_snapshot'];

        $r = $db->prepare('SELECT r.*, o.order_number, o.purchase_type, o.user_id FROM refunds r JOIN orders o ON o.id = r.order_id WHERE r.order_id = ? ORDER BY r.id');
        $r->execute([$id]);
        $out['refunds'] = array_map([AdminRefundController::class, 'format'], $r->fetchAll());

        $c = $db->prepare('SELECT c.*, p.name AS partner_name, p.referral_code FROM commissions c JOIN referral_partners p ON p.id = c.referral_partner_id WHERE c.order_id = ?');
        $c->execute([$id]);
        $cm = $c->fetch();
        $out['commission'] = $cm ? [
            'id' => (int) $cm['id'],
            'partner' => ['id' => (int) $cm['referral_partner_id'], 'name' => $cm['partner_name'], 'referralCode' => $cm['referral_code']],
            'amount' => (float) $cm['amount'],
            'status' => $cm['status'],
            'payoutReference' => $cm['payout_reference'],
            'paidAt' => $cm['paid_at'],
            'clawbackDue' => (bool) $cm['clawback_due'],
            'reversedAt' => $cm['reversed_at'],
            'createdAt' => $cm['created_at'],
        ] : null;

        $l = $db->prepare(
            "SELECT * FROM audit_logs WHERE (entity_type = 'order' AND entity_id = ?)
                OR (entity_type = 'access_code' AND entity_id IN (SELECT id FROM access_codes WHERE order_id = ?))
                OR (entity_type = 'refund' AND entity_id IN (SELECT id FROM refunds WHERE order_id = ?))
              ORDER BY id"
        );
        $l->execute([$id, $id, $id]);
        $out['auditLog'] = array_map([self::class, 'audit'], $l->fetchAll());
        return $out;
    }

    public static function audit(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'adminId' => $r['admin_id'] === null ? null : (int) $r['admin_id'],
            'userId' => $r['user_id'] === null ? null : (int) $r['user_id'],
            'action' => $r['action'],
            'entityType' => $r['entity_type'],
            'entityId' => $r['entity_id'] === null ? null : (int) $r['entity_id'],
            'detail' => $r['detail'] === null ? null : json_decode($r['detail'], true),
            'createdAt' => $r['created_at'],
        ];
    }

    /** @return array{int,int} page (>=1), perPage (1..100, default 25) */
    public static function paging(): array
    {
        $out = [];
        foreach (['page' => '1', 'perPage' => '25'] as $key => $default) {
            $v = $_GET[$key] ?? $default;
            if (!is_string($v) || !preg_match('/^[1-9][0-9]{0,5}$/', $v)) {
                throw new ApiException('Validation failed', 400, 'validation_error', [$key => 'Must be a positive integer']);
            }
            $out[] = (int) $v;
        }
        return [$out[0], min(100, $out[1])];
    }

    /** Trimmed ?q=, at most 100 chars. */
    public static function query(): string
    {
        $q = $_GET['q'] ?? '';
        if (!is_string($q) || mb_strlen($q) > 100) {
            throw new ApiException('Validation failed', 400, 'validation_error', ['q' => 'At most 100 characters']);
        }
        return trim($q);
    }

    /** Substring LIKE pattern with % _ ! escaped (use with ESCAPE '!'; independent of sql_mode). */
    public static function like(string $q): string
    {
        return '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    public static function id(string $id): int
    {
        if (!ctype_digit($id) || strlen($id) > 10) {
            throw new ApiException('Not found', 404, 'not_found');
        }
        return (int) $id;
    }

    public static function body(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }

    /** Trimmed single-line string field, 1..$max chars when present; null when optional and absent/empty. */
    public static function text(array $data, string $key, int $max, bool $required): ?string
    {
        $v = $data[$key] ?? null;
        $v = is_string($v) ? trim($v) : $v;
        if (($v === null || $v === '') && !$required) {
            return null;
        }
        // \p{Cc}: no control characters (newlines, NUL, ...); an invalid UTF-8 string also fails here.
        if (!is_string($v) || $v === '' || mb_strlen($v) > $max || preg_match('/\p{Cc}/u', $v) !== 0) {
            throw new ApiException('Validation failed', 400, 'validation_error', [$key => "Required, 1-$max characters"]);
        }
        return $v;
    }
}
