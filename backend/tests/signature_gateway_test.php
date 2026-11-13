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

use App\Http\ApiException;
use App\Razorpay\Config;
use App\Razorpay\FakeGateway;
use App\Razorpay\GatewayFactory;
use App\Razorpay\Signature;

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

function setEnv(array $vars): void
{
    foreach (['APP_ENV', 'RAZORPAY_GATEWAY', 'RAZORPAY_KEY_ID', 'RAZORPAY_KEY_SECRET', 'RAZORPAY_WEBHOOK_SECRET'] as $k) {
        putenv($k); // unset
    }
    foreach ($vars as $k => $v) {
        putenv("{$k}={$v}");
    }
}

function throwsRuntime(callable $fn): bool
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        return true;
    }
    return false;
}

// --- Signature::verifyCheckout
$sig = hash_hmac('sha256', 'order_X|pay_Y', 's');
assertTrue(Signature::verifyCheckout('order_X', 'pay_Y', $sig, 's') === true, 'checkout: valid signature');
$flipped = ($sig[0] === 'a' ? 'b' : 'a') . substr($sig, 1);
assertTrue(Signature::verifyCheckout('order_X', 'pay_Y', $flipped, 's') === false, 'checkout: flipped char rejected');
assertTrue(Signature::verifyCheckout('order_Z', 'pay_Y', $sig, 's') === false, 'checkout: different order id rejected');
assertTrue(Signature::verifyCheckout('order_X', 'pay_Z', $sig, 's') === false, 'checkout: different payment id rejected');
assertTrue(Signature::verifyCheckout('order_X', 'pay_Y', $sig, 'other') === false, 'checkout: wrong secret rejected');
assertTrue(Signature::verifyCheckout('order_X', 'pay_Y', '', 's') === false, 'checkout: empty signature rejected');

// --- Signature::verifyWebhook
$body = '{"event":"payment.captured","payload":{}}';
$wsig = hash_hmac('sha256', $body, 'whsec');
assertTrue(Signature::verifyWebhook($body, $wsig, 'whsec') === true, 'webhook: valid signature');
assertTrue(Signature::verifyWebhook($body . ' ', $wsig, 'whsec') === false, 'webhook: tampered body rejected');
assertTrue(Signature::verifyWebhook($body, $wsig, 'nope') === false, 'webhook: wrong secret rejected');
assertTrue(Signature::verifyWebhook($body, '', 'whsec') === false, 'webhook: empty header rejected');
assertTrue(Signature::verifyCheckout('order_X', 'pay_Y', hash_hmac('sha256', 'order_X|pay_Y', ''), '') === false, 'checkout: empty secret fails closed');
$emptyKeySig = hash_hmac('sha256', $body, '');
assertTrue(Signature::verifyWebhook($body, $emptyKeySig, '') === false, 'webhook: empty secret fails closed (HMAC with empty key never accepted)');

// --- Config::load
setEnv(['RAZORPAY_GATEWAY' => 'live', 'RAZORPAY_KEY_ID' => 'rzp_test_changeme', 'RAZORPAY_KEY_SECRET' => 'x', 'RAZORPAY_WEBHOOK_SECRET' => 'y']);
assertTrue(throwsRuntime(fn() => Config::load()), 'config: live + changeme key id throws');
setEnv(['RAZORPAY_GATEWAY' => 'live', 'RAZORPAY_KEY_ID' => 'k', 'RAZORPAY_KEY_SECRET' => 'x', 'RAZORPAY_WEBHOOK_SECRET' => '']);
assertTrue(throwsRuntime(fn() => Config::load()), 'config: live + empty webhook secret throws');
$strong = ['RAZORPAY_KEY_ID' => 'rzp_live_AbC123xyz', 'RAZORPAY_KEY_SECRET' => 'k8Jd02LmQp3Zr7Tw9Xy4Vb1N', 'RAZORPAY_WEBHOOK_SECRET' => str_repeat('a1B2', 8)];
setEnv(['RAZORPAY_GATEWAY' => 'live', 'APP_ENV' => 'development', 'RAZORPAY_KEY_ID' => 'rzp_test_abc123', 'RAZORPAY_KEY_SECRET' => 'x', 'RAZORPAY_WEBHOOK_SECRET' => 'y']);
$c = Config::load();
assertTrue($c['mode'] === 'live' && $c['keyId'] === 'rzp_test_abc123' && $c['keySecret'] === 'x' && $c['webhookSecret'] === 'y', 'config: dev + live with short non-placeholder values loads');
setEnv($strong);
assertTrue(Config::load()['mode'] === 'live', 'config: unset RAZORPAY_GATEWAY and unset APP_ENV -> live, strong secrets load');

// APP_ENV allow-list (exact, case-sensitive) x gateway x secrets
foreach (['development', 'dev', 'local', 'test', 'testing'] as $env) {
    setEnv(['RAZORPAY_GATEWAY' => 'fake', 'APP_ENV' => $env]);
    assertTrue(!throwsRuntime(fn() => Config::load()), "config: fake allowed with APP_ENV=$env");
}
foreach ([null, '', 'production', 'Production', ' production', 'prod', 'staging', 'DEVELOPMENT', 'development ', 'devel', 'test1'] as $env) {
    setEnv(['RAZORPAY_GATEWAY' => 'fake'] + ($env === null ? [] : ['APP_ENV' => $env]));
    assertTrue(throwsRuntime(fn() => Config::load()), 'config: fake refused with APP_ENV=' . json_encode($env));
}
$prod = ['RAZORPAY_GATEWAY' => 'live', 'APP_ENV' => 'production'];
setEnv($prod + $strong);
assertTrue(Config::load()['keyId'] === 'rzp_live_AbC123xyz', 'config: production + live + strong secrets loads');
$weak = [
    'key id without rzp_ prefix' => ['RAZORPAY_KEY_ID' => 'key_live_AbC123xyz'],
    'key id rzp_other_' => ['RAZORPAY_KEY_ID' => 'rzp_prod_AbC123xyz'],
    'key id placeholder' => ['RAZORPAY_KEY_ID' => 'rzp_test_changeme'],
    'key secret "changeme" padded' => ['RAZORPAY_KEY_SECRET' => 'changeme-changeme-changeme'],
    'key secret 19 chars' => ['RAZORPAY_KEY_SECRET' => 'k8Jd02LmQp3Zr7Tw9Xy'],
    'webhook secret 31 chars' => ['RAZORPAY_WEBHOOK_SECRET' => substr(str_repeat('a1B2', 8), 1)],
    'webhook secret "your-" template' => ['RAZORPAY_WEBHOOK_SECRET' => 'your-webhook-secret-0123456789abcdef'],
    'webhook secret change-me' => ['RAZORPAY_WEBHOOK_SECRET' => 'change-me-0123456789abcdef0123456789'],
    'webhook secret empty' => ['RAZORPAY_WEBHOOK_SECRET' => ''],
];
foreach ($weak as $label => $override) {
    setEnv(array_merge($prod, $strong, $override));
    $msg = '';
    try {
        Config::load();
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
    }
    $value = (string) reset($override);
    assertTrue($msg !== '' && ($value === '' || !str_contains($msg, $value)), "config: production + $label -> refused, value not in message");
}
assertTrue(\App\Support\Env::weakSecret(str_repeat('ab', 16), 32) === false && \App\Support\Env::weakSecret(str_repeat('ab', 15) . 'a', 32) === true, 'Env::weakSecret: 32 ok, 31 weak');
setEnv([]);
assertTrue(throwsRuntime(fn() => Config::load()), 'config: unset gateway + no keys throws (live)');
setEnv(['RAZORPAY_GATEWAY' => 'fake', 'APP_ENV' => 'production']);
assertTrue(throwsRuntime(fn() => Config::load()), 'config: fake + production throws');
setEnv(['RAZORPAY_GATEWAY' => 'fake', 'APP_ENV' => 'development']);
$c = Config::load();
assertTrue($c['mode'] === 'fake' && array_keys($c) === ['mode', 'keyId', 'keySecret', 'webhookSecret'], 'config: fake + development loads without keys');
setEnv(['RAZORPAY_GATEWAY' => 'bogus']);
assertTrue(throwsRuntime(fn() => Config::load()), 'config: unknown mode throws');

// --- GatewayFactory / FakeGateway
setEnv(['RAZORPAY_GATEWAY' => 'fake', 'APP_ENV' => 'development']);
$g = GatewayFactory::make();
assertTrue($g instanceof FakeGateway, 'factory: fake mode returns FakeGateway');
$o = $g->createOrder(19900, 'rcpt_1');
assertTrue(preg_match('/^order_fake_[0-9a-f]{12}$/', $o['id']) === 1, 'fake createOrder id format');
assertTrue($g->fetchOrderPayments($o['id']) === [], 'fake fetchOrderPayments returns []');
try {
    $g->fetchPayment('pay_real_123');
    assertTrue(false, 'fake fetchPayment rejects unknown format');
} catch (ApiException $e) {
    assertTrue($e->statusCode === 502 && $e->errorCode === 'gateway_error', 'fake fetchPayment rejects unknown format with 502 gateway_error');
}
try {
    $g->fetchPayment('pay_fake_order_fake_abc_bogus');
    assertTrue(false, 'fake fetchPayment rejects unknown state');
} catch (ApiException $e) {
    assertTrue($e->statusCode === 502, 'fake fetchPayment rejects unknown state');
}
// ponytail: DB-backed fetchPayment amount lookup (payments.amount x 100) not unit-tested; needs orders+users fixtures, covered by Task 6+ HTTP tests.

setEnv(['RAZORPAY_GATEWAY' => 'live'] + $strong);
assertTrue(GatewayFactory::make() instanceof App\Razorpay\RazorpayGateway, 'factory: live mode returns RazorpayGateway');
setEnv(['RAZORPAY_GATEWAY' => 'fake', 'APP_ENV' => 'production']);
assertTrue(throwsRuntime(fn() => GatewayFactory::make()), 'factory: fake in production throws');

if ($failures > 0) {
    echo "\n{$failures} test(s) FAILED.\n";
    exit(1);
}
echo "\nAll signature/gateway tests passed.\n";
