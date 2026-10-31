<?php

namespace App\Database;

use PDO;

class Db
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $host = getenv('DB_HOST');
            $port = getenv('DB_PORT');
            $name = getenv('DB_NAME');
            $user = getenv('DB_USER');
            $pass = getenv('DB_PASS');
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$instance;
    }

    /** True when $e is MySQL 1062 (SQLSTATE 23000) on unique key $key (the key name ends the message). */
    public static function isDuplicateKey(\PDOException $e, string $key): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            && preg_match("/ for key '(?:[^']*\\.)?" . preg_quote($key, '/') . "'\\z/", (string) ($e->errorInfo[2] ?? ''));
    }
}
