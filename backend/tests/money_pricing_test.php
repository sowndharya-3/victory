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

use App\Orders\PricingService;
use App\Support\Money;

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

function check(array $got, array $want, string $label): void
{
    assertTrue(
        $got['gst'] === $want['gst'] && $got['total'] === $want['total']
            && $got['shipping'] === ($want['shipping'] ?? 0),
        "{$label} => gst {$got['gst']} total {$got['total']}"
    );
}

check(PricingService::breakdown(19900, 0, '0', 'inclusive'), ['gst' => 0, 'total' => 19900], '199.00 @0% inclusive');
check(PricingService::breakdown(19900, 0, '18', 'exclusive'), ['gst' => 3582, 'total' => 23482], '199.00 @18% exclusive');
check(PricingService::breakdown(19900, 0, '18', 'inclusive'), ['gst' => 3036, 'total' => 19900], '199.00 @18% inclusive');
check(PricingService::breakdown(34950, 0, '5', 'exclusive'), ['gst' => 1748, 'total' => 36698], '349.50 @5% exclusive (half-up)');
check(PricingService::breakdown(34950, 5000, '18', 'exclusive'), ['gst' => 7191, 'total' => 47141, 'shipping' => 5000], '349.50 + 50 shipping @18% exclusive');
assertTrue(PricingService::breakdown(34950, 5000, '2.5', 'exclusive')['gst'] === 999, 'fractional percent 2.5 -> 250 bp');
assertTrue(PricingService::breakdown(19900, 0, '18', 'exclusive')['subtotal'] === 19900, 'subtotal = price');

assertTrue(Money::toPaise('349.50') === 34950, "toPaise('349.50')");
assertTrue(Money::toPaise(199) === 19900, 'toPaise(199)');
assertTrue(Money::toPaise('0.005') === 1, "toPaise('0.005') rounds half-up");
assertTrue(Money::fromPaise(34950) === '349.50', 'fromPaise(34950)');
assertTrue(Money::fromPaise(5) === '0.05', 'fromPaise(5)');

$bad = 0;
foreach (range(1, 2000) as $price) {
    foreach (['0', '5', '12', '18'] as $rate) {
        $b = PricingService::breakdown($price, 0, $rate, 'exclusive');
        if ($b['subtotal'] + $b['shipping'] + $b['gst'] !== $b['total']) {
            $bad++;
        }
        $i = PricingService::breakdown($price, 300, $rate, 'inclusive');
        if ($i['total'] !== $price + 300 || $i['gst'] > $i['total']) {
            $bad++;
        }
    }
}
assertTrue($bad === 0, 'property: exclusive sums and inclusive total==taxable for prices 1..2000 x rates {0,5,12,18}');

try {
    PricingService::forBook(['online_price' => '10.00', 'hardcopy_price' => '20.00'], 'ebook');
    assertTrue(false, 'forBook rejects unknown purchase type');
} catch (App\Http\ApiException $e) {
    assertTrue($e->statusCode === 400 && $e->errorCode === 'validation_error' && isset($e->fields['purchaseType']), 'forBook rejects unknown purchase type');
}

if ($failures > 0) {
    echo "\n{$failures} test(s) FAILED.\n";
    exit(1);
}
echo "\nAll money/pricing tests passed.\n";
