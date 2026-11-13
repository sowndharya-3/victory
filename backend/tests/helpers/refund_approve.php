<?php
// Child process for the concurrent approve test: php refund_approve.php <refundId> <callLogFile>
// Each gateway refund call appends a line to <callLogFile>, so the parent can count calls across processes.
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

class CountingGateway extends \App\Razorpay\FakeGateway
{
    public function __construct(private string $log)
    {
    }

    public function refund(string $razorpayPaymentId, int $amountPaise, string $receipt): array
    {
        file_put_contents($this->log, "$receipt\n", FILE_APPEND | LOCK_EX);
        usleep(200000); // widen the window while holding the refund row lock
        return parent::refund($razorpayPaymentId, $amountPaise, $receipt);
    }
}

try {
    echo (new \App\Refunds\RefundService(new CountingGateway($argv[2])))->approve((int) $argv[1], 1, null)['status'];
} catch (\App\Http\ApiException $e) {
    echo $e->errorCode;
} catch (\Throwable $e) {
    echo 'error:' . get_class($e) . ':' . $e->getCode();
}
