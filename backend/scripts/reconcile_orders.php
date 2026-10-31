<?php

// Cron (every 15 min): fulfil captured-but-missed pending/failed/cancelled orders, expire abandoned ones, settle refunds
// whose webhook was lost, purge old rate_limits.
// Exit: 0 ok, 2 some orders/refunds errored (counts still printed), 3 no errors but a refund was newly flagged for an
// admin (refund_external_unmatched), 1 fatal. Alert on any non-zero exit.

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
    $gateway = GatewayFactory::make();
    $r = OrderReconciler::run($gateway);
    $f = OrderReconciler::reconcileRefunds($gateway);
    $purged = OrderReconciler::purgeRateLimits();
    $errors = $r['errors'] + $f['errors'];
    echo "paid={$r['paid']} cancelled={$r['cancelled']} errors={$errors} purged={$purged}"
        . " refunds_finalised={$f['finalised']} refunds_failed={$f['failed']} refunds_flagged={$f['flagged']}\n";
    exit($errors > 0 ? 2 : ($f['flagged'] > 0 ? 3 : 0));
} catch (\Throwable $e) {
    fwrite(STDERR, 'reconcile failed: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
