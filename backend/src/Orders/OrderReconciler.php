<?php

namespace App\Orders;

use App\Database\Db;
use App\Razorpay\Gateway;

/**
 * Cron sweep: fulfils pending orders the gateway reports captured, expires abandoned ones, and re-checks
 * recent failed/cancelled orders for a capture whose webhook was lost. Never sets 'paid' itself.
 */
class OrderReconciler
{
    /** @return array{paid:int,cancelled:int,errors:int} */
    public static function run(Gateway $gateway, int $minAgeMinutes = 15, int $expireAfterHours = 24, int $recheckDays = 7): array
    {
        $result = ['paid' => 0, 'cancelled' => 0, 'errors' => 0];

        // Pass 1 (runs first so orders expired below are not re-queried this run): failed/cancelled orders
        // that were captured anyway (e.g. a retry succeeded, webhook lost). markPaid only; nothing else is modified.
        $stmt = Db::connection()->prepare(
            "SELECT o.id, p.razorpay_order_id
               FROM orders o JOIN payments p ON p.order_id = o.id
              WHERE o.status IN ('failed', 'cancelled') AND o.created_at >= NOW() - INTERVAL ? DAY AND p.razorpay_order_id IS NOT NULL
              ORDER BY o.id"
        );
        $stmt->execute([$recheckDays]);
        foreach ($stmt->fetchAll() as $row) {
            $rzp = $row['razorpay_order_id'];
            try {
                foreach ($gateway->fetchOrderPayments($rzp) as $p) {
                    if (($p['status'] ?? '') === 'captured') {
                        if (OrderFulfillment::markPaid($rzp, (string) $p['id'], (int) $p['amount'], $p) === 'paid') {
                            $result['paid']++;
                        }
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $result['errors']++;
                error_log("reconcile: order {$row['id']} failed: " . get_class($e) . ': ' . $e->getMessage());
            }
        }

        // Pass 2: pending orders.
        $stmt = Db::connection()->prepare(
            "SELECT o.id, p.razorpay_order_id, o.created_at <= NOW() - INTERVAL ? HOUR AS expirable
               FROM orders o JOIN payments p ON p.order_id = o.id
              WHERE o.status = 'pending' AND o.created_at <= NOW() - INTERVAL ? MINUTE AND p.razorpay_order_id IS NOT NULL
              ORDER BY o.id"
        );
        $stmt->execute([$expireAfterHours, $minAgeMinutes]);
        foreach ($stmt->fetchAll() as $row) {
            $rzp = $row['razorpay_order_id'];
            try {
                $payments = $gateway->fetchOrderPayments($rzp);
                $captured = null;
                $authorized = false;
                foreach ($payments as $p) {
                    $captured ??= ($p['status'] ?? '') === 'captured' ? $p : null;
                    $authorized = $authorized || ($p['status'] ?? '') === 'authorized';
                }
                if ($captured) {
                    if (OrderFulfillment::markPaid($rzp, (string) $captured['id'], (int) $captured['amount'], $captured) === 'paid') {
                        $result['paid']++;
                    }
                    continue; // money received: never expire, even on amount_mismatch (admin_flag set for review)
                }
                // An authorized payment may still be captured; leave it for a later run.
                if ($row['expirable'] && !$authorized && self::expire($rzp)) {
                    $result['cancelled']++;
                }
            } catch (\Throwable $e) {
                $result['errors']++;
                error_log("reconcile: order {$row['id']} failed: " . get_class($e) . ': ' . $e->getMessage());
            }
        }
        return $result;
    }

    /** Old rate_limits windows (written with PHP time, so compared with PHP time). */
    public static function purgeRateLimits(int $olderThanDays = 2): int
    {
        $stmt = Db::connection()->prepare('DELETE FROM rate_limits WHERE window_start < ?');
        $stmt->execute([date('Y-m-d H:i:s', time() - $olderThanDays * 86400)]);
        return $stmt->rowCount();
    }

    /** Same lock query/order as OrderFulfillment::lock (payments then orders); a concurrent markPaid wins. */
    private static function expire(string $razorpayOrderId): bool
    {
        $db = Db::connection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT o.id, o.status, p.id AS payment_id
                   FROM payments p JOIN orders o ON o.id = p.order_id
                  WHERE p.razorpay_order_id = ? FOR UPDATE'
            );
            $stmt->execute([$razorpayOrderId]);
            $row = $stmt->fetch();
            if (!$row || $row['status'] !== 'pending') {
                $db->rollBack();
                return false;
            }
            $db->prepare("UPDATE payments SET status = 'failed', failure_reason = 'expired' WHERE id = ?")->execute([$row['payment_id']]);
            $db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$row['id']]);
            $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
