<?php

namespace App\Controllers;

use App\Database\Db;
use App\Http\ApiException;
use App\Support\RateLimiter;

/** Public hard-copy status lookup. Never returns address, email or any digital code. */
class TrackController
{
    private const LABELS = [
        'confirmed' => 'Order Confirmed',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'out_for_delivery' => 'Out for Delivery',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];

    public function show(string $code): void
    {
        RateLimiter::hit('track:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10, 60);
        $notFound = new ApiException('Tracking code not found', 404, 'not_found');
        $code = strtoupper(trim($code));
        if (!preg_match('/^VTT-[0-9A-HJKMNP-TV-Z]{8}$/', $code)) {
            throw $notFound;
        }
        $stmt = Db::connection()->prepare(
            "SELECT o.order_number, b.title, o.fulfilment_status, o.updated_at, o.courier, o.tracking_number
               FROM orders o JOIN books b ON b.id = o.book_id
              WHERE o.tracking_code = ? AND o.purchase_type = 'hardcopy'"
        );
        $stmt->execute([$code]);
        $o = $stmt->fetch();
        if (!$o) {
            throw $notFound;
        }
        $out = [
            'orderNumber' => $o['order_number'],
            'bookTitle' => $o['title'],
            'status' => $o['fulfilment_status'],
            'label' => self::LABELS[$o['fulfilment_status']] ?? $o['fulfilment_status'],
            'updatedAt' => $o['updated_at'],
        ];
        if ($o['courier'] !== null) {
            $out['courier'] = $o['courier'];
        }
        if ($o['tracking_number'] !== null) {
            $out['trackingNumber'] = $o['tracking_number'];
        }
        echo json_encode($out);
    }
}
