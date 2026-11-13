<?php

namespace App\Razorpay;

class Signature
{
    public static function verifyCheckout(string $rzpOrderId, string $paymentId, string $signature, string $secret): bool
    {
        return $secret !== '' && self::matches(hash_hmac('sha256', "{$rzpOrderId}|{$paymentId}", $secret), $signature);
    }

    public static function verifyWebhook(string $rawBody, string $signatureHeader, string $secret): bool
    {
        // Fail closed: an HMAC keyed with '' is forgeable by anyone.
        return $secret !== '' && self::matches(hash_hmac('sha256', $rawBody, $secret), $signatureHeader);
    }

    private static function matches(string $expected, string $given): bool
    {
        return $given !== '' && hash_equals($expected, $given);
    }
}
