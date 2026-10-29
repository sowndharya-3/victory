<?php

namespace App\Codes;

/** Crockford base32 codes from random_bytes (32 symbols, so `& 31` is unbiased). */
class CodeGenerator
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    public const DIGITAL_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{16}$/';

    /** 16 chars = 80 bits, no dashes. */
    public static function digital(): string
    {
        return self::random(16);
    }

    public static function tracking(): string
    {
        return 'VTT-' . self::random(8);
    }

    /** Uppercase, drop whitespace/dashes, fold look-alikes (O->0, I/L->1). */
    public static function normalise(string $input): string
    {
        return strtr(preg_replace('/[\s\-]+/', '', strtoupper($input)), ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    public static function format(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    private static function random(int $len): string
    {
        $bytes = random_bytes($len);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= self::ALPHABET[ord($bytes[$i]) & 31];
        }
        return $out;
    }
}
