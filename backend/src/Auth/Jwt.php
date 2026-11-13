<?php

namespace App\Auth;

use App\Http\ApiException;

class Jwt
{
    public static function encode(array $payload, int $expiresInSeconds): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $expiresInSeconds;

        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));
        $signature = hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", self::secret(), true);
        $signatureEncoded = self::base64UrlEncode($signature);

        return "{$headerEncoded}.{$payloadEncoded}.{$signatureEncoded}";
    }

    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new ApiException('Malformed token', 401, 'invalid_token');
        }
        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        $expectedSignature = self::base64UrlEncode(
            hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", self::secret(), true)
        );

        if (!hash_equals($expectedSignature, $signatureEncoded)) {
            throw new ApiException('Invalid token signature', 401, 'invalid_token');
        }

        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);
        if (!is_array($payload) || !isset($payload['exp'])) {
            throw new ApiException('Malformed token payload', 401, 'invalid_token');
        }

        if (time() >= $payload['exp']) {
            throw new ApiException('Token expired', 401, 'token_expired');
        }

        return $payload;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        $padded = $remainder ? str_pad($data, strlen($data) + 4 - $remainder, '=') : $data;
        return base64_decode(strtr($padded, '-_', '+/'));
    }

    private static function secret(): string
    {
        $secret = (string) getenv('JWT_SECRET');
        if (\App\Support\Env::weakSecret($secret, 32)) { // in every APP_ENV, dev included
            throw new \RuntimeException('JWT_SECRET is not configured securely — see backend/README.md');
        }
        return $secret;
    }
}
