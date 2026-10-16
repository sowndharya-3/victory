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

use App\Database\Db;

// Verifies the exact failure mode AuthController::register's catch block is
// built to handle: two INSERTs with the same email, the second colliding on
// the UNIQUE constraint — confirms it really throws PDOException with
// SQLSTATE 23000 (what the controller's catch condition checks), not some
// other code that would fall through to the generic 500 handler.
$db = Db::connection();
$email = 'race-test-' . time() . '@example.com';

$db->prepare('INSERT INTO users (name, email, phone, password_hash) VALUES (:name, :email, NULL, :hash)')
    ->execute(['name' => 'Race Test', 'email' => $email, 'hash' => 'x']);

$failures = 0;
try {
    $db->prepare('INSERT INTO users (name, email, phone, password_hash) VALUES (:name, :email, NULL, :hash)')
        ->execute(['name' => 'Race Test 2', 'email' => $email, 'hash' => 'x']);
    echo "FAIL: second insert with duplicate email should have thrown\n";
    $failures++;
} catch (\PDOException $e) {
    if ((string)$e->getCode() === '23000') {
        echo "PASS: duplicate-email insert throws PDOException with SQLSTATE 23000\n";
    } else {
        echo "FAIL: duplicate-email insert threw code {$e->getCode()}, expected 23000\n";
        $failures++;
    }
}

$db->prepare('DELETE FROM users WHERE email = :email')->execute(['email' => $email]);

if ($failures > 0) {
    echo "\n{$failures} test(s) FAILED.\n";
    exit(1);
}
echo "\nAll race-condition guard tests passed.\n";
