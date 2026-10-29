<?php

namespace App\Razorpay;

use App\Http\ApiException;

class RazorpayGateway implements Gateway
{
    private const BASE = 'https://api.razorpay.com/v1';

    public function __construct(private string $keyId, private string $keySecret)
    {
    }

    public function createOrder(int $amountPaise, string $receipt, array $notes = []): array
    {
        return $this->request('POST', '/orders', [
            'amount' => $amountPaise,
            'currency' => 'INR',
            'receipt' => $receipt,
            'notes' => (object) $notes,
        ]);
    }

    public function fetchPayment(string $paymentId): array
    {
        return $this->request('GET', '/payments/' . rawurlencode($paymentId));
    }

    public function fetchOrderPayments(string $razorpayOrderId): array
    {
        return $this->request('GET', '/orders/' . rawurlencode($razorpayOrderId) . '/payments')['items'] ?? [];
    }

    public function refund(string $razorpayPaymentId, int $amountPaise, string $receipt): array
    {
        $r = $this->request('POST', '/payments/' . rawurlencode($razorpayPaymentId) . '/refund', [
            'amount' => $amountPaise,
            'receipt' => $receipt,
        ]);
        if (!is_string($r['id'] ?? null) || !is_string($r['status'] ?? null)) {
            error_log("Razorpay refund for {$razorpayPaymentId}: response without id/status");
            throw new ApiException('Payment gateway error', 502, 'gateway_error');
        }
        return ['id' => $r['id'], 'status' => $r['status']];
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => "{$this->keyId}:{$this->keySecret}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $decoded = is_string($response) ? json_decode($response, true) : null;
        if ($response === false || $status < 200 || $status >= 300 || !is_array($decoded)) {
            // Details stay server-side only; the client gets a generic 502.
            error_log("Razorpay {$method} {$path} failed: status={$status} curl=\"{$curlError}\" body=" . (is_string($response) ? substr($response, 0, 500) : ''));
            throw new ApiException('Payment gateway error', 502, 'gateway_error');
        }
        return $decoded;
    }
}
