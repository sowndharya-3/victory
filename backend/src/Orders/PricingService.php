<?php

namespace App\Orders;

use App\Http\ApiException;
use App\Support\Money;
use App\Support\Settings;

class PricingService
{
    /** Integer half-up division for non-negative operands. */
    private static function divRound(int $n, int $d): int
    {
        return intdiv(2 * $n + $d, 2 * $d);
    }

    /** @return array{subtotal:int,shipping:int,gst:int,total:int} all paise */
    public static function breakdown(int $pricePaise, int $shippingPaise, string $gstPercent, string $gstMode): array
    {
        $bp = Money::toPaise($gstPercent); // percent x 100 == basis points ("18" -> 1800, "2.5" -> 250)
        $taxable = $pricePaise + $shippingPaise;
        if ($gstMode === 'inclusive') {
            $gst = self::divRound($taxable * $bp, 10000 + $bp);
            $total = $taxable;
        } else {
            $gst = self::divRound($taxable * $bp, 10000);
            $total = $taxable + $gst;
        }
        return ['subtotal' => $pricePaise, 'shipping' => $shippingPaise, 'gst' => $gst, 'total' => $total];
    }

    /** @return array{subtotal:int,shipping:int,gst:int,total:int,gstPercent:string,gstMode:string} */
    public static function forBook(array $bookRow, string $purchaseType): array
    {
        if (!in_array($purchaseType, ['digital', 'hardcopy'], true)) {
            throw new ApiException('Invalid purchase type', 400, 'validation_error', ['purchaseType' => 'Must be digital or hardcopy']);
        }
        $price = Money::toPaise($bookRow[$purchaseType === 'digital' ? 'online_price' : 'hardcopy_price']);
        $shipping = Money::toPaise(Settings::get("shipping_charge_{$purchaseType}", '0'));
        $gstPercent = Settings::get("gst_percent_{$purchaseType}", '0');
        $gstMode = Settings::get('gst_mode', 'inclusive');
        return self::breakdown($price, $shipping, $gstPercent, $gstMode) + ['gstPercent' => $gstPercent, 'gstMode' => $gstMode];
    }
}
