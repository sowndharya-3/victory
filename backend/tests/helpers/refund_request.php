<?php
// Child process for the concurrent refund-request test: php refund_request.php <userId> <orderId>
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
try {
    $r = (new \App\Refunds\RefundService(new \App\Razorpay\FakeGateway()))->request((int) $argv[1], (int) $argv[2], 'race test');
    echo ($r['created'] ? 'created:' : 'existing:') . $r['refund']['id'];
} catch (\App\Http\ApiException $e) {
    echo $e->errorCode;
} catch (\Throwable $e) {
    echo 'error:' . get_class($e) . ':' . $e->getCode();
}
