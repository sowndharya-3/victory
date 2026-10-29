<?php

namespace App\Codes;

use RuntimeException;

/**
 * Keys derive from CODE_SECRET, read and validated lazily (at use, never at boot):
 * pepper = HMAC-SHA256("hash", secret), enc key = HMAC-SHA256("enc", secret), both raw 32 bytes.
 * Blob layout: 12-byte IV | 16-byte GCM tag | ciphertext.
 * $aad is the owning order id: a blob copied onto another order's row fails to decrypt.
 */
class CodeCipher
{
    public static function hash(string $normalisedCode): string
    {
        return hash_hmac('sha256', $normalisedCode, self::key('hash'));
    }

    public static function encrypt(string $plain, string $aad): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key('enc'), OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($ct === false) {
            throw new RuntimeException('Code encryption failed');
        }
        return $iv . $tag . $ct;
    }

    /** null when the blob is malformed or has been tampered with. */
    public static function decrypt(string $blob, string $aad): ?string
    {
        if (strlen($blob) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($blob, 28), 'aes-256-gcm', self::key('enc'), OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, 12, 16), $aad);
        return $plain === false ? null : $plain;
    }

    private static function key(string $purpose): string
    {
        $secret = (string) getenv('CODE_SECRET');
        if (strlen($secret) < 32 || stripos($secret, 'changeme') !== false) {
            throw new RuntimeException('CODE_SECRET must be set to a real value of at least 32 bytes');
        }
        return hash_hmac('sha256', $purpose, $secret, true);
    }
}
