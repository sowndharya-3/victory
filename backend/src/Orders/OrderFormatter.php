<?php

namespace App\Orders;

class OrderFormatter
{
    /** Order + book title + the order's current (latest) access code status/last4; append WHERE/ORDER. */
    public const SELECT = 'SELECT o.*, b.title AS book_title, ac.status AS code_status, ac.code_last4
          FROM orders o JOIN books b ON b.id = o.book_id
          LEFT JOIN access_codes ac ON ac.id = (SELECT MAX(id) FROM access_codes WHERE order_id = o.id)';

    /**
     * Owner views only (trackingCode is the owner's to share).
     * @param array $o row from self::SELECT
     * @param array|null $a hardcopy_addresses row
     */
    public static function format(array $o, ?array $a = null): array
    {
        $out = [
            'id' => (int) $o['id'],
            'orderNumber' => $o['order_number'],
            'bookId' => (int) $o['book_id'],
            'bookTitle' => $o['book_title'],
            'purchaseType' => $o['purchase_type'],
            'status' => $o['status'],
            'fulfilmentStatus' => $o['fulfilment_status'],
            'subtotal' => (float) $o['subtotal_amount'],
            'shipping' => (float) $o['shipping_amount'],
            'gst' => (float) $o['gst_amount'],
            'gstPercent' => (float) $o['gst_percent'],
            'gstMode' => $o['gst_mode'],
            'total' => (float) $o['amount'],
            'currency' => 'INR',
            'paidAt' => $o['paid_at'],
            'createdAt' => $o['created_at'],
            'trackingCode' => $o['tracking_code'],
            // Never the plaintext: the owner fetches that from /access-code.
            'digitalCode' => ($o['code_status'] ?? null) === null ? null : ['status' => $o['code_status'], 'last4' => $o['code_last4']],
        ];
        if ($a !== null) {
            $out['address'] = [
                'fullName' => $a['full_name'],
                'phone' => $a['phone'],
                'addressLine' => $a['address_line'],
                'city' => $a['city'],
                'district' => $a['district'],
                'state' => $a['state'],
                'pincode' => $a['pincode'],
            ];
        }
        return $out;
    }
}
