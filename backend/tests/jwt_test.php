<?php

putenv('JWT_SECRET=test-secret-for-unit-tests');

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

use App\Auth\Jwt;
use App\Http\ApiException;

$failures = 0;

function assertTrue(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        echo "PASS: {$message}\n";
    } else {
        echo "FAIL: {$message}\n";
        $failures++;
    }
}

$token = Jwt::encode(['sub' => 42, 'role' => 'customer'], 3600);
$payload = Jwt::decode($token);
assertTrue($payload['sub'] === 42, 'decode returns correct sub claim');
assertTrue($payload['role'] === 'customer', 'decode returns correct role claim');

$parts = explode('.', $token);
$tamperedPayload = base64_encode(json_encode(['sub' => 999, 'role' => 'admin', 'iat' => time(), 'exp' => time() + 3600]));
$parts[1] = rtrim(strtr($tamperedPayload, '+/', '-_'), '=');
$tampered = implode('.', $parts);
try {
    Jwt::decode($tampered);
    assertTrue(false, 'tampered token is rejected');
} catch (ApiException $e) {
    assertTrue($e->errorCode === 'invalid_token', 'tampered token is rejected');
}

$expired = Jwt::encode(['sub' => 1, 'role' => 'customer'], -10);
try {
    Jwt::decode($expired);
    assertTrue(false, 'expired token is rejected');
} catch (ApiException $e) {
    assertTrue($e->errorCode === 'token_expired', 'expired token is rejected');
}

if ($failures > 0) {
    echo "\n{$failures} test(s) FAILED.\n";
    exit(1);
}
echo "\nAll JWT tests passed.\n";
