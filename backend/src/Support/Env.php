<?php

namespace App\Support;

/** Environment policy shared by the gateway config, secrets and scripts/check_config.php. Fails closed. */
class Env
{
    /** Exact, case-sensitive allow-list. Anything else (unset, "staging", "Production", " production") is NOT dev. */
    public const DEV_VALUES = ['development', 'dev', 'local', 'test', 'testing'];
    /** Substrings that mark a template value; plus whole values that are never real secrets. */
    private const PLACEHOLDERS = ['changeme', 'change-me', 'change_me', 'placeholder', 'replace-me', 'your-'];
    private const DEFAULTS = ['secret', 'password', 'test', 'default'];

    public static function isDev(): bool
    {
        return in_array(getenv('APP_ENV'), self::DEV_VALUES, true);
    }

    /** Empty, shorter than $min bytes, or containing a known placeholder word. */
    public static function weakSecret(string $value, int $min): bool
    {
        if (strlen($value) < $min || in_array(strtolower($value), self::DEFAULTS, true)) {
            return true;
        }
        foreach (self::PLACEHOLDERS as $p) {
            if (stripos($value, $p) !== false) {
                return true;
            }
        }
        return false;
    }
}
