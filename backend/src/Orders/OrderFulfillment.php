<?php

namespace App\Orders;

use App\Database\Db;
use App\Support\Money;
use App\Support\Settings;

/** The single paid/failed path: verify endpoint, webhook and any reconciliation all route through here. */
class OrderFulfillment
{
    /** @return 'paid'|'already_paid'|'amount_mismatch'|'unknown_order' */
    public static function markPaid(string $razorpayOrderId, string $razorpayPaymentId, int $capturedPaise, array $rawPayment = []): string
    {
        $db = Db::connection();
        $db->beginTransaction();
        try {
            $row = self::lock($razorpayOrderId);
            if (!$row) {
                $db->rollBack();
                return 'unknown_order';
            }
            // refunded counts as settled: never re-fulfil it.
            if (in_array($row['status'], ['paid', 'refunded'], true)) {
                $db->rollBack();
                return 'already_paid';
            }
            if ($capturedPaise !== Money::toPaise($row['pay_amount'])) {
                $db->prepare("UPDATE orders SET admin_flag = 'amount_mismatch' WHERE id = ?")->execute([$row['id']]);
                $db->commit();
                return 'amount_mismatch';
            }

            $db->prepare("UPDATE payments SET status = 'captured', razorpay_payment_id = ?, raw_response = ? WHERE id = ?")
                ->execute([$razorpayPaymentId, json_encode($rawPayment), $row['payment_id']]);

            $type = $row['purchase_type'];
            if ($type === 'hardcopy') {
                // Task 9: generate the tracking code here.
                $db->prepare("UPDATE orders SET status = 'paid', paid_at = NOW(), fulfilment_status = 'confirmed' WHERE id = ?")->execute([$row['id']]);
            } else {
                $db->prepare("UPDATE orders SET status = 'paid', paid_at = NOW() WHERE id = ?")->execute([$row['id']]);
                // Task 9: AccessCodeService::issueForOrder($row['id']) goes here (digital access is granted on code redemption).
            }

            if ($row['referral_partner_id'] !== null) {
                // Flat rupee value snapshotted now; later rate changes never touch existing rows.
                $amount = Money::fromPaise(Money::toPaise(Settings::get("commission_rate_{$type}", '25')));
                $db->prepare("INSERT IGNORE INTO commissions (order_id, referral_partner_id, purchase_type, amount, status) VALUES (?, ?, ?, ?, 'pending')")
                    ->execute([$row['id'], $row['referral_partner_id'], $type, $amount]);
            }

            $db->commit();
            return 'paid';
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** @return 'failed'|'ignored'|'unknown_order' */
    public static function markFailed(string $razorpayOrderId, string $razorpayPaymentId, string $reason): string
    {
        $db = Db::connection();
        $db->beginTransaction();
        try {
            $row = self::lock($razorpayOrderId);
            if (!$row) {
                $db->rollBack();
                return 'unknown_order';
            }
            if (in_array($row['status'], ['paid', 'refunded'], true)) {
                $db->rollBack();
                return 'ignored';
            }
            $db->prepare("UPDATE payments SET status = 'failed', razorpay_payment_id = ?, failure_reason = ? WHERE id = ?")
                ->execute([$razorpayPaymentId, mb_substr($reason, 0, 255), $row['payment_id']]);
            $db->prepare("UPDATE orders SET status = 'failed' WHERE id = ?")->execute([$row['id']]);
            $db->commit();
            return 'failed';
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Locks the order (and its payment) row; callers must be inside a transaction. */
    private static function lock(string $razorpayOrderId): array|false
    {
        $stmt = Db::connection()->prepare(
            'SELECT o.id, o.status, o.purchase_type, o.referral_partner_id, p.id AS payment_id, p.amount AS pay_amount
               FROM payments p JOIN orders o ON o.id = p.order_id
              WHERE p.razorpay_order_id = ? FOR UPDATE'
        );
        $stmt->execute([$razorpayOrderId]);
        return $stmt->fetch();
    }
}
