<?php

namespace App\Razorpay;

use App\Database\Db;
use App\Http\ApiException;
use App\Support\Money;

/**
 * Dev/test only. Payment ids encode the outcome: pay_fake_<razorpayOrderId>_<captured|authorized|failed>.
 * Refund lookups are scriptable: $refunds maps refund id => entity (or a Throwable to throw), and
 * "payment:<id>" => Throwable makes listRefundsForPayment throw. Unscripted fake refund ids (rfnd_fake_*) read back
 * as processed for the stored refund amount, anything else is a 502.
 */
class FakeGateway implements Gateway
{
    /** @var array<string, array|\Throwable> */
    public array $refunds = [];

    public function createOrder(int $amountPaise, string $receipt, array $notes = []): array
    {
        return ['id' => 'order_fake_' . bin2hex(random_bytes(6))];
    }

    public function fetchPayment(string $paymentId): array
    {
        if (!preg_match('/^pay_fake_(.+)_(captured|authorized|failed)$/', $paymentId, $m)) {
            throw new ApiException('Payment gateway error', 502, 'gateway_error');
        }
        [, $orderId, $state] = $m;

        $stmt = Db::connection()->prepare('SELECT amount FROM payments WHERE razorpay_order_id = ?');
        $stmt->execute([$orderId]);
        $amount = $stmt->fetchColumn();
        if ($amount === false) {
            throw new ApiException('Payment gateway error', 502, 'gateway_error');
        }

        $payment = [
            'id' => $paymentId,
            'order_id' => $orderId,
            'amount' => Money::toPaise((string) $amount),
            'currency' => 'INR',
            'status' => $state,
        ];
        if ($state === 'failed') {
            $payment['error_description'] = 'Fake payment failed';
        }
        return $payment;
    }

    public function fetchOrderPayments(string $razorpayOrderId): array
    {
        return [];
    }

    public function refund(string $razorpayPaymentId, int $amountPaise, string $receipt): array
    {
        return ['id' => 'rfnd_fake_' . bin2hex(random_bytes(6)), 'status' => 'processed'];
    }

    public function fetchRefund(string $refundId): array
    {
        $r = $this->refunds[$refundId] ?? null;
        if ($r instanceof \Throwable) {
            throw $r;
        }
        if ($r !== null) {
            return $r;
        }
        $stmt = Db::connection()->prepare('SELECT r.amount, p.razorpay_payment_id FROM refunds r JOIN payments p ON p.id = r.payment_id WHERE r.razorpay_refund_id = ?');
        $stmt->execute([$refundId]);
        $row = $stmt->fetch();
        if (!str_starts_with($refundId, 'rfnd_fake_') || !$row) {
            throw new ApiException('Payment gateway error', 502, 'gateway_error');
        }
        return ['id' => $refundId, 'payment_id' => $row['razorpay_payment_id'], 'amount' => Money::toPaise((string) $row['amount']), 'status' => 'processed'];
    }

    public function listRefundsForPayment(string $paymentId): array
    {
        if (($t = $this->refunds["payment:$paymentId"] ?? null) instanceof \Throwable) {
            throw $t;
        }
        $out = [];
        foreach ($this->refunds as $r) {
            if (is_array($r) && ($r['payment_id'] ?? null) === $paymentId) {
                $out[] = $r;
            }
        }
        return $out;
    }
}
