<?php

putenv('JWT_SECRET=test-secret-for-unit-tests-32-bytes-minimum-ok');

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
use App\Middleware\AuthMiddleware;
use App\Middleware\ErrorHandler;

// Final review finding: the plan's own Review Focus asks for "role confusion
// → 403, not silently succeed" — correct in the code, but no test (unit or
// HTTP) ever exercised the 403 branch, since no admin-only HTTP route exists
// yet to trigger it through curl. Testing AuthMiddleware::requireRole
// directly closes that specific gap.

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

$customerToken = Jwt::encode(['sub' => 1, 'role' => 'customer'], 3600);
$_SERVER['HTTP_AUTHORIZATION'] = "Bearer {$customerToken}";

try {
    AuthMiddleware::requireRole(['admin']);
    assertTrue(false, 'customer token on an admin-only route is rejected with 403');
} catch (ApiException $e) {
    assertTrue($e->statusCode === 403 && $e->errorCode === 'forbidden', 'customer token on an admin-only route is rejected with 403');
}

$adminToken = Jwt::encode(['sub' => 1, 'role' => 'admin'], 3600);
$_SERVER['HTTP_AUTHORIZATION'] = "Bearer {$adminToken}";
$payload = AuthMiddleware::requireRole(['admin']);
assertTrue($payload['role'] === 'admin', 'admin token on an admin-only route is accepted');

// --- Authorization header sources (nginx/FPM, Apache rewrite, mod_php / getallheaders).
$h = fn(array $server, array $headers = []) => AuthMiddleware::authorizationHeader($server, $headers);
assertTrue($h(['HTTP_AUTHORIZATION' => 'Bearer a']) === 'Bearer a', 'auth header: HTTP_AUTHORIZATION');
assertTrue($h(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer b']) === 'Bearer b', 'auth header: REDIRECT_HTTP_AUTHORIZATION (Apache rewrite)');
assertTrue($h(['HTTP_AUTHORIZATION' => 'Bearer a', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer b']) === 'Bearer a', 'auth header: HTTP_AUTHORIZATION wins');
assertTrue($h(['HTTP_AUTHORIZATION' => ''], ['authorization' => 'Bearer c']) === 'Bearer c', 'auth header: empty server var falls back to request headers, case-insensitive');
assertTrue($h([], ['AUTHORIZATION' => 'Bearer d', 'Host' => 'x']) === 'Bearer d', 'auth header: apache_request_headers upper-case name');
assertTrue($h([], ['Host' => 'x']) === '', 'auth header: absent -> empty');
assertTrue($h(['HTTP_AUTHORIZATION' => ['x']], ['Authorization' => ['y']]) === '', 'auth header: non-string values ignored');
unset($_SERVER['HTTP_AUTHORIZATION']);
$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = "Bearer {$adminToken}";
assertTrue(AuthMiddleware::requireRole(['admin'])['role'] === 'admin', 'requireRole accepts a token from REDIRECT_HTTP_AUTHORIZATION');
$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = "Basic {$adminToken}";
try {
    AuthMiddleware::requireRole(['admin']);
    assertTrue(false, 'requireRole: non-Bearer scheme rejected with 401');
} catch (ApiException $e) {
    assertTrue($e->statusCode === 401, 'requireRole: non-Bearer scheme rejected with 401');
}
unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

// --- ErrorHandler: unexpected exceptions are logged (class + message only), ApiException is not.
$logFile = tempnam(sys_get_temp_dir(), 'eh');
ini_set('error_log', $logFile);
ob_start();
ErrorHandler::wrap(function () { throw new RuntimeException('boom-secret-free'); });
$out = ob_get_clean();
$log = (string) file_get_contents($logFile);
assertTrue(str_contains($out, 'server_error') && !str_contains($out, 'boom'), 'ErrorHandler: 500 body stays generic');
assertTrue(str_contains($log, 'RuntimeException') && str_contains($log, 'boom-secret-free'), 'ErrorHandler: unexpected exception logged with class and message');
file_put_contents($logFile, '');
ob_start();
ErrorHandler::wrap(function () { throw new ApiException('nope', 400, 'validation_error'); });
ob_end_clean();
assertTrue(file_get_contents($logFile) === '', 'ErrorHandler: ApiException (expected 4xx) is not logged');
unlink($logFile);
http_response_code(200);

if ($failures > 0) {
    echo "\n{$failures} test(s) FAILED.\n";
    exit(1);
}
echo "\nAll middleware tests passed.\n";
