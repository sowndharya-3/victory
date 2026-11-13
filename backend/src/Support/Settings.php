<?php

namespace App\Support;

use App\Database\Db;

class Settings
{
    public static function get(string $key, string $default = ''): string
    {
        $stmt = Db::connection()->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $default : (string) $value;
    }

    /** Upsert; callers validate and audit (inside their own transaction). */
    public static function set(string $key, string $value): void
    {
        Db::connection()->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
            ->execute([$key, $value]);
    }
}
