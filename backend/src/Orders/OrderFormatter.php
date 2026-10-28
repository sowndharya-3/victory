<?php

namespace App\Orders;

class OrderFormatter
{
    /**
     * @param array $o orders row joined with books.title AS book_title
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
