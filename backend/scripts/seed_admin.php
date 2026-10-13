<?php

require_once __DIR__ . '/../config/env.php';
loadEnv(__DIR__ . '/../.env');

spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

use App\Auth\Password;
use App\Database\Db;

[, $username, $password] = $argv + [null, null, null];

if (!$username || !$password) {
    fwrite(STDERR, "Usage: php scripts/seed_admin.php <username> <password>\n");
    exit(1);
}

$db = Db::connection();
$stmt = $db->prepare('SELECT id FROM admins WHERE username = :username');
$stmt->execute(['username' => $username]);
if ($stmt->fetch()) {
    fwrite(STDERR, "Admin '{$username}' already exists.\n");
    exit(1);
}

$stmt = $db->prepare('INSERT INTO admins (username, password_hash) VALUES (:username, :password_hash)');
$stmt->execute(['username' => $username, 'password_hash' => Password::hash($password)]);

echo "Admin '{$username}' created.\n";
