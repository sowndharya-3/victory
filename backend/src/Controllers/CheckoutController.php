<?php

namespace App\Controllers;

use App\Database\Db;
use App\Http\ApiException;
use App\Orders\PricingService;
use App\Support\Money;

class CheckoutController
{
    public function quote(): void
    {
        $bookId = $_GET['bookId'] ?? '';
        $type = $_GET['type'] ?? '';
        $errors = [];
        if (!is_string($bookId) || !preg_match('/^[1-9][0-9]{0,9}$/', $bookId)) {
            $errors['bookId'] = 'Must be a positive integer';
        }
        if (!in_array($type, ['digital', 'hardcopy'], true)) {
            $errors['type'] = 'Must be digital or hardcopy';
        }
        if ($errors) {
            throw new ApiException('Validation failed', 400, 'validation_error', $errors);
        }

        $stmt = Db::connection()->prepare('SELECT id, online_price, hardcopy_price FROM books WHERE id = ? AND published = 1 AND archived = 0');
        $stmt->execute([$bookId]);
        $book = $stmt->fetch();
        if (!$book) {
            throw new ApiException('Book not found', 404, 'not_found');
        }

        $p = PricingService::forBook($book, $type);
        $rupees = fn(int $paise) => (float) Money::fromPaise($paise);
        echo json_encode([
            'bookId' => (int) $book['id'],
            'purchaseType' => $type,
            'currency' => 'INR',
            'subtotal' => $rupees($p['subtotal']),
            'shipping' => $rupees($p['shipping']),
            'gst' => $rupees($p['gst']),
            'gstPercent' => (float) $p['gstPercent'],
            'gstMode' => $p['gstMode'],
            'total' => $rupees($p['total']),
        ]);
    }
}
