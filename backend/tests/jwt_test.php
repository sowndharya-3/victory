<?php

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

// Secret-rejection check runs as a sub-invocation: `JWT_SECRET=<value> php
// jwt_test.php --secret-rejection-check`, so it sees exactly the env var
// passed to it, not the putenv() override below. Must come before that
// override and exit before the main test body runs.
if (($argv[1] ?? '') === '--secret-rejection-check') {
    try {
        Jwt::encode(['sub' => 1, 'role' => 'customer'], 3600);
        echo 'UNEXPECTED_SUCCESS';
    } catch (\RuntimeException $e) {
        $secret = getenv('JWT_SECRET');
        echo $secret === 'changeme-generate-a-real-random-secret' ? 'REJECTED_PLACEHOLDER' : 'REJECTED_SHORT';
    }
    exit(0);
}

putenv('JWT_SECRET=test-secret-for-unit-tests-32-bytes-minimum-ok');

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

// Final review finding: the documented setup (cp .env.example to .env) ships
// the literal placeholder secret unchanged, which would let anyone forge
// tokens. Jwt::secret() must refuse to sign/verify with that placeholder or
// with any secret under 32 bytes.
$check = shell_exec('JWT_SECRET=changeme-generate-a-real-random-secret php ' . escapeshellarg(__FILE__) . ' --secret-rejection-check 2>&1');
assertTrue(str_contains((string)$check, 'REJECTED_PLACEHOLDER'), 'Jwt::encode refuses the documented placeholder secret');

$check2 = shell_exec('JWT_SECRET=short php ' . escapeshellarg(__FILE__) . ' --secret-rejection-check 2>&1');
assertTrue(str_contains((string)$check2, 'REJECTED_SHORT'), 'Jwt::encode refuses a secret under 32 bytes');

if ($failures > 0) {
    echo "\n{$failures} test(s) FAILED.\n";
    exit(1);
}
echo "\nAll JWT tests passed.\n";
