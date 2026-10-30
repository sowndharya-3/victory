<?php

namespace App\Orders;

use App\Http\ApiException;

class AddressValidator
{
    private const MAX = ['fullName' => 150, 'addressLine' => 500, 'city' => 100, 'district' => 100, 'state' => 100];

    /** @return array{fullName:string,phone:string,addressLine:string,city:string,district:string,state:string,pincode:string} */
    public static function validate(array $input): array
    {
        $out = [];
        $errors = [];
        foreach (['fullName', 'phone', 'addressLine', 'city', 'district', 'state', 'pincode'] as $f) {
            $v = $input[$f] ?? null;
            $v = is_string($v) ? trim($v) : '';
            if ($v === '') {
                $errors[$f] = 'Required';
            } elseif (isset(self::MAX[$f]) && mb_strlen($v) > self::MAX[$f]) {
                $errors[$f] = 'Must be at most ' . self::MAX[$f] . ' characters';
            }
            $out[$f] = $v;
        }
        if (!isset($errors['phone'])) {
            $phone = self::mobile($out['phone']);
            if ($phone !== null) {
                $out['phone'] = $phone;
            } else {
                $errors['phone'] = 'Must be a valid 10-digit Indian mobile number';
            }
        }
        if (!isset($errors['pincode']) && !preg_match('/^[1-9][0-9]{5}$/', $out['pincode'])) {
            $errors['pincode'] = 'Must be a valid 6-digit pincode';
        }
        if ($errors) {
            throw new ApiException('Validation failed', 400, 'validation_error', $errors);
        }
        return $out;
    }

    /** "+91 98765-43210" / "098765 43210" / "9876543210" -> "9876543210"; null unless a 10-digit Indian mobile. */
    public static function mobile(string $input): ?string
    {
        $digits = preg_replace('/[\s-]+/', '', $input);
        return preg_match('/^(?:\+?91|0)?([6-9][0-9]{9})\z/', $digits, $m) ? $m[1] : null;
    }
}
