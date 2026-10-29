<?php

namespace App\Refunds;

use App\Codes\AccessCodeService;
use App\Database\Db;
use App\Http\ApiException;
use App\Razorpay\Gateway;
use App\Support\Money;
use PDOException;

/**
 * Full refunds only (spec §4.5). States: requested -> approved -> processing -> refunded, or rejected / failed
 * (failed retries via approve). The amount is always the captured payments.amount.
 * Lock order: refunds row, then payments+orders, then access_codes / book_access (AccessCodeService), then commissions.
 * Only finalise() moves money-side state (order/payment refunded, access revoked, commission reversed).
 */
class RefundService
{
    private const SHIPPED = ['shipped', 'out_for_delivery', 'delivered'];

    public function __construct(private Gateway $gateway)
    {
    }

    /** Customer request on their own paid order; returns the existing open refund instead of a duplicate. @return array{refund:array,created:bool} */
    public function request(int $userId, int $orderId, string $reason): array
    {
        $reason = self::text($reason, 'reason');
        $stmt = Db::connection()->prepare('SELECT id, status FROM orders WHERE id = ? AND user_id = ?');
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new ApiException('Order not found', 404, 'not_found');
        }
        return $this->open($order, $reason, $userId, null);
    }

    /** Admin opens a refund (starts `approved`) and processes it; an open customer request is approved instead. @return array{refund:array,created:bool} */
    public function adminOpen(int $orderId, int $adminId, string $reason): array
    {
        $reason = self::text($reason, 'reason');
        $stmt = Db::connection()->prepare('SELECT id, status FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new ApiException('Order not found', 404, 'not_found');
        }
        $res = $this->open($order, $reason, null, $adminId);
        if ($res['created']) {
            self::audit($adminId, 'refund.admin_open', (int) $res['refund']['id'], ['orderId' => $orderId]);
        }
        return ['refund' => $this->approve((int) $res['refund']['id'], $adminId, null), 'created' => $res['created']];
    }

    /**
     * Calls the gateway under the refund row lock, so two approvals can't both refund. Idempotent once
     * processing/refunded. Gateway error => `failed` (approve again retries). @return array refund row
     */
    public function approve(int $refundId, int $adminId, ?string $note): array
    {
        $note = $note === null || trim($note) === '' ? null : self::text($note, 'note');
        $db = Db::connection();
        $db->beginTransaction();
        try {
            $r = self::lockRefund($refundId);
            if (in_array($r['status'], ['processing', 'refunded'], true)) {
                $db->rollBack();
                return $r;
            }
            if ($r['status'] === 'rejected') {
                throw new ApiException('A rejected refund cannot be approved', 409, 'invalid_transition');
            }
            $stmt = $db->prepare('SELECT o.status, p.razorpay_payment_id, p.amount FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.id = ? FOR UPDATE');
            $stmt->execute([$r['payment_id']]);
            $pay = $stmt->fetch();
            if ($pay['status'] !== 'paid' || !$pay['razorpay_payment_id']) {
                throw new ApiException('Only paid orders can be refunded', 409, 'not_refundable');
            }
            try {
                // failed -> approved re-opens the refund; another open refund for the order blocks it.
                $db->prepare("UPDATE refunds SET status = 'approved', reviewed_by_admin_id = ?, admin_note = COALESCE(?, admin_note) WHERE id = ?")
                    ->execute([$adminId, $note, $refundId]);
            } catch (PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
                throw new ApiException('Another refund is already open for this order', 409, 'refund_conflict');
            }
            self::audit($adminId, 'refund.approve', $refundId, ['orderId' => (int) $r['order_id']]);

            try {
                // Full refund of the stored captured amount; Razorpay refuses to refund a fully refunded payment again.
                $g = $this->gateway->refund($pay['razorpay_payment_id'], Money::toPaise($pay['amount']), "refund_{$refundId}");
            } catch (\Throwable $e) {
                $g = null;
                error_log("refund {$refundId}: gateway refund failed (" . get_class($e) . ')');
            }
            $gStatus = is_array($g) ? ($g['status'] ?? null) : null;
            $gId = is_array($g) && is_string($g['id'] ?? null) && $g['id'] !== '' ? $g['id'] : null;
            if ($gId === null || !in_array($gStatus, ['processed', 'pending'], true)) {
                $db->prepare("UPDATE refunds SET status = 'failed', admin_note = ? WHERE id = ?")
                    ->execute(['Gateway refund failed' . (is_string($gStatus) ? " (status {$gStatus})" : '') . '; approve again to retry', $refundId]);
                self::audit($adminId, 'refund.failed', $refundId, ['orderId' => (int) $r['order_id'], 'gatewayStatus' => is_string($gStatus) ? $gStatus : null]);
            } else {
                $db->prepare("UPDATE refunds SET status = 'processing', razorpay_refund_id = ? WHERE id = ?")->execute([$gId, $refundId]);
            }
            // Commit before finalising: once money has left, the refund id must survive a later DB error
            // (a stuck `processing` refund is then finalised by the webhook or another finalise call).
            $db->commit();
            if ($gStatus === 'processed' && $gId !== null) {
                $this->finalise($refundId);
            }
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return self::find($refundId);
    }

    /** @return array refund row */
    public function reject(int $refundId, int $adminId, string $note): array
    {
        $note = self::text($note, 'note');
        $db = Db::connection();
        $db->beginTransaction();
        try {
            $r = self::lockRefund($refundId);
            if (!in_array($r['status'], ['requested', 'approved', 'failed'], true)) {
                throw new ApiException("A {$r['status']} refund cannot be rejected", 409, 'invalid_transition');
            }
            $db->prepare("UPDATE refunds SET status = 'rejected', admin_note = ?, reviewed_by_admin_id = ? WHERE id = ?")->execute([$note, $adminId, $refundId]);
            self::audit($adminId, 'refund.reject', $refundId, ['orderId' => (int) $r['order_id']]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return self::find($refundId);
    }

    /**
     * Money confirmed back (gateway `processed` or signed webhook). Idempotent: an already refunded refund is a no-op.
     * @return bool false when the refund is in a state that never reached the gateway (requested/rejected)
     */
    public function finalise(int $refundId, ?string $razorpayRefundId = null): bool
    {
        $db = Db::connection();
        $db->beginTransaction();
        try {
            $r = self::lockRefund($refundId);
            if ($razorpayRefundId !== null && $r['razorpay_refund_id'] === null && $r['status'] !== 'refunded') {
                $db->prepare('UPDATE refunds SET razorpay_refund_id = ? WHERE id = ?')->execute([$razorpayRefundId, $refundId]);
            }
            $ok = $this->finaliseLocked($r);
            $db->commit();
            return $ok;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function find(int $refundId): array
    {
        $stmt = Db::connection()->prepare('SELECT * FROM refunds WHERE id = ?');
        $stmt->execute([$refundId]);
        $r = $stmt->fetch();
        if (!$r) {
            throw new ApiException('Refund not found', 404, 'not_found');
        }
        return $r;
    }

    /** Caller holds the refund row lock inside a transaction. */
    private function finaliseLocked(array $r): bool
    {
        if ($r['status'] === 'refunded') {
            return true;
        }
        if (!in_array($r['status'], ['approved', 'processing', 'failed'], true)) {
            error_log("refund {$r['id']}: finalise refused in status {$r['status']}");
            return false;
        }
        $db = Db::connection();
        $stmt = $db->prepare('SELECT o.id, o.purchase_type, o.fulfilment_status FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.id = ? FOR UPDATE');
        $stmt->execute([$r['payment_id']]);
        $order = $stmt->fetch();
        $orderId = (int) $order['id'];

        $db->prepare("UPDATE refunds SET status = 'refunded' WHERE id = ?")->execute([$r['id']]);
        $db->prepare("UPDATE payments SET status = 'refunded' WHERE id = ?")->execute([$r['payment_id']]);
        $db->prepare("UPDATE orders SET status = 'refunded' WHERE id = ?")->execute([$orderId]);
        $adminId = $r['reviewed_by_admin_id'] === null ? null : (int) $r['reviewed_by_admin_id'];

        $detail = ['orderId' => $orderId, 'amount' => $r['amount']];
        if ($order['purchase_type'] === 'digital') {
            AccessCodeService::revokeForRefund($orderId, $adminId);
        } elseif (in_array($order['fulfilment_status'], self::SHIPPED, true)) {
            self::audit($adminId, 'refund.already_shipped', (int) $r['id'], ['orderId' => $orderId, 'fulfilmentStatus' => $order['fulfilment_status']]);
        } else {
            $db->prepare("UPDATE orders SET fulfilment_status = 'cancelled' WHERE id = ?")->execute([$orderId]);
            $detail['fulfilment'] = 'cancelled';
        }

        $stmt = $db->prepare('SELECT id, status FROM commissions WHERE order_id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        if ($c = $stmt->fetch()) {
            if ($c['status'] === 'paid') {
                $db->prepare('UPDATE commissions SET clawback_due = 1 WHERE id = ?')->execute([$c['id']]);
                $detail['commission'] = 'clawback_due';
            } elseif (in_array($c['status'], ['pending', 'payable'], true)) {
                $db->prepare("UPDATE commissions SET status = 'reversed', reversed_at = NOW() WHERE id = ?")->execute([$c['id']]);
                $detail['commission'] = 'reversed';
            }
        }
        self::audit($adminId, 'refund.refunded', (int) $r['id'], $detail);
        return true;
    }

    /** @return array{refund:array,created:bool} */
    private function open(array $order, string $reason, ?int $userId, ?int $adminId, bool $retry = true): array
    {
        $existing = self::openFor((int) $order['id']);
        if ($existing) {
            return ['refund' => $existing, 'created' => false];
        }
        if ($order['status'] !== 'paid') {
            throw new ApiException('Only paid orders can be refunded', 409, 'not_refundable');
        }
        $db = Db::connection();
        $stmt = $db->prepare("SELECT id, amount FROM payments WHERE order_id = ? AND status = 'captured' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$order['id']]);
        $pay = $stmt->fetch();
        if (!$pay) {
            throw new ApiException('Only paid orders can be refunded', 409, 'not_refundable');
        }
        try {
            $db->prepare('INSERT INTO refunds (order_id, payment_id, requested_by_user_id, reviewed_by_admin_id, status, reason, amount) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$order['id'], $pay['id'], $userId, $adminId, $adminId === null ? 'requested' : 'approved', $reason, $pay['amount']]);
        } catch (PDOException $e) {
            // InnoDB may pick a racing INSERT on uq_open_refund as deadlock victim (40001) instead of reporting
            // a duplicate; the statement was rolled back, so retry once (it then sees the row or gets 23000).
            if ((string) $e->getCode() === '40001' && $retry) {
                return $this->open($order, $reason, $userId, $adminId, false);
            }
            // uq_open_refund: a concurrent request won the race; hand back that one.
            if ((string) $e->getCode() !== '23000' || !($existing = self::openFor((int) $order['id']))) {
                throw $e;
            }
            return ['refund' => $existing, 'created' => false];
        }
        return ['refund' => self::find((int) $db->lastInsertId()), 'created' => true];
    }

    private static function openFor(int $orderId): array|false
    {
        $stmt = Db::connection()->prepare('SELECT * FROM refunds WHERE order_id = ? AND is_open = 1');
        $stmt->execute([$orderId]);
        return $stmt->fetch();
    }

    private static function lockRefund(int $refundId): array
    {
        $stmt = Db::connection()->prepare('SELECT * FROM refunds WHERE id = ? FOR UPDATE');
        $stmt->execute([$refundId]);
        $r = $stmt->fetch();
        if (!$r) {
            throw new ApiException('Refund not found', 404, 'not_found');
        }
        return $r;
    }

    /** Trimmed, 1..500 characters, else 400 with fields[$field]. */
    private static function text(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 500) {
            throw new ApiException('Validation failed', 400, 'validation_error', [$field => 'Required, at most 500 characters']);
        }
        return $value;
    }

    private static function audit(?int $adminId, string $action, int $refundId, array $detail): void
    {
        Db::connection()->prepare("INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, detail) VALUES (?, ?, 'refund', ?, ?)")
            ->execute([$adminId, $action, $refundId, json_encode($detail)]);
    }
}
