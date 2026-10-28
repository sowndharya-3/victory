<?php

spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

use App\Http\ApiException;
use App\Orders\AddressValidator;

$failures = 0;

function assertTrue(bool $condition, string $message): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . ": {$message}\n";
    if (!$condition) {
        $failures++;
    }
}

$base = [
    'fullName' => 'தமிழ் குமார்',
    'phone' => '+91 98765-43210',
    'addressLine' => '12, காந்தி தெரு',
    'city' => 'சென்னை',
    'district' => 'Chennai',
    'state' => 'Tamil Nadu',
    'pincode' => '600001',
];

function errorFields(array $input): ?array
{
    try {
        AddressValidator::validate($input);
        return null;
    } catch (ApiException $e) {
        return $e->statusCode === 400 && $e->errorCode === 'validation_error' ? array_keys($e->fields) : ['__wrong_exception'];
    }
}

$ok = AddressValidator::validate($base);
assertTrue($ok['phone'] === '9876543210', 'phone +91 98765-43210 normalises to 10 digits');
assertTrue($ok['fullName'] === 'தமிழ் குமார்' && $ok['city'] === 'சென்னை' && $ok['addressLine'] === '12, காந்தி தெரு', 'Tamil text round-trips unchanged');
assertTrue($ok['pincode'] === '600001' && $ok['state'] === 'Tamil Nadu', 'other fields pass through');
foreach (['09876543210', '919876543210', '9876543210'] as $p) {
    assertTrue(AddressValidator::validate(['phone' => $p] + $base)['phone'] === '9876543210', "phone {$p} accepted");
}
assertTrue(errorFields(['pincode' => '60001'] + $base) === ['pincode'], 'pincode 60001 rejected');
assertTrue(errorFields(['pincode' => '060001'] + $base) === ['pincode'], 'pincode 060001 rejected');
assertTrue(errorFields(['phone' => '12345'] + $base) === ['phone'], 'phone 12345 rejected');
assertTrue(errorFields(['phone' => '5876543210'] + $base) === ['phone'], 'phone starting 5 rejected');
$noDistrict = $base;
unset($noDistrict['district']);
assertTrue(errorFields($noDistrict) === ['district'], 'missing district rejected');
assertTrue(errorFields(['addressLine' => str_repeat('a', 10000)] + $base) === ['addressLine'], '10000-char addressLine rejected');
assertTrue(errorFields(['city' => str_repeat('ச', 101)] + $base) === ['city'], '101 Tamil chars city rejected (mb_strlen)');
assertTrue(errorFields(['city' => str_repeat('ச', 100)] + $base) === null, '100 Tamil chars city accepted');

exit($failures > 0 ? 1 : 0);
