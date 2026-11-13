<?php

namespace App\Support;

use InvalidArgumentException;

/** Money is integer paise everywhere; these two functions are the only rupee/paise boundary. */
class Money
{
    /** Decimal string/int/float (rupees, or any 2-decimal quantity) to integer paise, half-up. */
    public static function toPaise(string|int|float $amount): int
    {
        // Strings only: floats are formatted first so no float arithmetic touches the value.
        $s = is_float($amount) ? sprintf('%.4F', $amount) : trim((string) $amount);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $s, $m)) {
            throw new InvalidArgumentException("Invalid amount: {$s}");
        }
        $frac = str_pad($m[3] ?? '', 3, '0');
        $paise = (int) $m[2] * 100 + (int) substr($frac, 0, 2) + ((int) $frac[2] >= 5 ? 1 : 0);
        return $m[1] === '-' ? -$paise : $paise;
    }

    /**
     * Admin-entered non-negative amount ("30", "30.5", "30.50", or a JSON int/float) within 0..$maxPaise,
     * normalised to "30.50"; null when signed, exponent, >2 decimals, padded or out of range.
     */
    public static function parseAmount(mixed $value, int $maxPaise): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value; // JSON number: PHP's shortest round-trip form ("30.5"), checked as text below
        }
        if (!is_string($value) || !preg_match('/^\d{1,7}(\.\d{1,2})?\z/', $value)) {
            return null;
        }
        $paise = self::toPaise($value);
        return $paise <= $maxPaise ? self::fromPaise($paise) : null;
    }

    public static function fromPaise(int $paise): string
    {
        $abs = abs($paise);
        return ($paise < 0 ? '-' : '') . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
