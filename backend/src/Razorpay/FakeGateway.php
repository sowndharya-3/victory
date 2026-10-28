<?php

namespace App\Razorpay;

use App\Database\Db;
use App\Http\ApiException;
use App\Support\Money;

/** Dev/test only. Payment ids encode the outcome: pay_fake_<razorpayOrderId>_<captured|authorized|failed>. */
class FakeGateway implements Gateway
{
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
}
