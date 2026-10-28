<?php
// Child process for the concurrency test: php mark_paid.php <rzpOrderId> <rzpPaymentId> <paise>
require_once __DIR__ . '/../../config/env.php';
loadEnv(__DIR__ . '/../../.env');
spl_autoload_register(function (string $c) {
    if (str_starts_with($c, 'App\\')) {
        $p = __DIR__ . '/../../src/' . str_replace('\\', '/', substr($c, 4)) . '.php';
        if (file_exists($p)) {
            require $p;
        }
    }
});
echo \App\Orders\OrderFulfillment::markPaid($argv[1], $argv[2], (int) $argv[3]);
