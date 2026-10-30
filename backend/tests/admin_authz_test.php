<?php

// Every /api/admin/* route (except login): 401 without a token, 403 with a customer token.
// Routes come from routes.php itself, so a new admin route is covered automatically.

require_once __DIR__ . '/../config/env.php';
loadEnv(__DIR__ . '/../.env');

spl_autoload_register(function (string $class) {
    if (str_starts_with($class, 'App\\')) {
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (file_exists($path)) {
            require $path;
        }
    }
});

use App\Auth\Jwt;
use App\Middleware\ErrorHandler;
use App\Router\Router;

$failures = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $failures;
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $name . ($ok || $extra === '' ? '' : " ($extra)") . "\n";
    if (!$ok) {
        $failures++;
    }
}

$router = new Router();
require __DIR__ . '/../src/routes.php';
$routes = (new ReflectionProperty(Router::class, 'routes'))->getValue($router);
$admin = array_values(array_filter($routes, fn ($r) => str_starts_with($r['path'], '/api/admin/') && $r['path'] !== '/api/admin/login'));
$registered = array_map(fn ($r) => $r['method'] . ' ' . $r['path'], $admin);

// Task 19/20 routes must exist (fails before they are implemented).
foreach ([
    'GET /api/admin/dashboard',
    'GET /api/admin/orders',
    'GET /api/admin/orders/{id}',
    'PUT /api/admin/orders/{id}/fulfilment',
    'GET /api/admin/customers',
    'GET /api/admin/customers/{id}',
    'POST /api/admin/codes/verify',
    'POST /api/admin/orders/{id}/code/revoke',
    'POST /api/admin/orders/{id}/code/reissue',
    'GET /api/admin/audit-logs',
    // Task 20
    'GET /api/admin/referrals',
    'POST /api/admin/referrals/{id}/approve',
    'POST /api/admin/referrals/{id}/reject',
    'POST /api/admin/partners/{id}/toggle-active',
    'PUT /api/admin/partners/{id}',
    'GET /api/admin/commissions',
    'POST /api/admin/commissions/{id}/payable',
    'POST /api/admin/commissions/{id}/paid',
    'GET /api/admin/settings/commission',
    'PUT /api/admin/settings/commission',
    'GET /api/admin/settings/pricing',
    'PUT /api/admin/settings/pricing',
] as $route) {
    check("route registered: $route", in_array($route, $registered, true));
}

$customer = Jwt::encode(['sub' => 1, 'role' => 'customer'], 3600);
foreach ($admin as $r) {
    $path = preg_replace('#\{[a-zA-Z_]+\}#', '1', $r['path']);
    foreach (['' => [401, 'unauthorized'], "Bearer $customer" => [403, 'forbidden']] as $auth => [$status, $code]) {
        if ($auth === '') {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        } else {
            $_SERVER['HTTP_AUTHORIZATION'] = $auth;
        }
        http_response_code(200);
        ob_start();
        ErrorHandler::wrap(fn () => $router->dispatch($r['method'], $path));
        $body = ob_get_clean();
        $got = http_response_code();
        check("{$r['method']} {$r['path']} " . ($auth === '' ? 'no token' : 'customer token') . " -> $status",
            $got === $status && str_contains($body, "\"code\":\"$code\""), "got $got $body");
    }
}
check('at least 25 admin routes checked', count($admin) >= 25, (string) count($admin));

if ($failures > 0) {
    echo "\n{$failures} test(s) FAILED.\n";
    exit(1);
}
echo "\nAll admin authz tests passed.\n";
