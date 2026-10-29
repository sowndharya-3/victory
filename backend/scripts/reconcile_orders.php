<?php

// Cron (every 15 min): fulfil captured-but-missed pending/failed/cancelled orders, expire abandoned ones, purge old rate_limits.
// Exit: 0 ok, 2 some orders errored (counts still printed), 1 fatal.

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

use App\Orders\OrderReconciler;
use App\Razorpay\GatewayFactory;

try {
    $r = OrderReconciler::run(GatewayFactory::make());
    $purged = OrderReconciler::purgeRateLimits();
    echo "paid={$r['paid']} cancelled={$r['cancelled']} errors={$r['errors']} purged={$purged}\n";
    exit($r['errors'] > 0 ? 2 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'reconcile failed: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
