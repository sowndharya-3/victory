<?php

namespace App\Orders;

use App\Database\Db;
use App\Http\ApiException;
use App\Razorpay\Config;
use App\Razorpay\Gateway;
use App\Referral\ReferralService;
use App\Support\Money;
use App\Support\RateLimiter;
use PDOException;

class OrderService
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford base32

    public function __construct(private Gateway $gateway)
    {
    }

    /** @return array{order:array,razorpay:array{keyId:string,orderId:string,amount:int,currency:string}} */
    public function create(int $userId, array $input): array
    {
        RateLimiter::hit('order-create:' . $userId, 20, 3600);
        $db = Db::connection();

        $bookId = $input['bookId'] ?? null;
        $type = $input['purchaseType'] ?? null;
        $errors = [];
        if (!(is_int($bookId) && $bookId > 0) && !(is_string($bookId) && preg_match('/^[1-9][0-9]{0,9}$/', $bookId))) {
            $errors['bookId'] = 'Must be a positive integer';
        }
        if (!in_array($type, ['digital', 'hardcopy'], true)) {
            $errors['purchaseType'] = 'Must be digital or hardcopy';
        }
        if ($errors) {
            throw new ApiException('Validation failed', 400, 'validation_error', $errors);
        }

        $stmt = $db->prepare('SELECT id, title, online_price, hardcopy_price FROM books WHERE id = ? AND published = 1 AND archived = 0');
        $stmt->execute([$bookId]);
        $book = $stmt->fetch();
        if (!$book) {
            throw new ApiException('Book not found', 404, 'not_found');
        }

        if ($type === 'digital') {
            // Owned = live access row, or a paid digital order (access is granted on code redemption).
            $stmt = $db->prepare(
                "SELECT (SELECT COUNT(*) FROM book_access WHERE user_id = :u1 AND book_id = :b1 AND revoked_at IS NULL)
                      + (SELECT COUNT(*) FROM orders WHERE user_id = :u2 AND book_id = :b2 AND status = 'paid' AND purchase_type = 'digital')"
            );
            $stmt->execute(['u1' => $userId, 'b1' => $book['id'], 'u2' => $userId, 'b2' => $book['id']]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new ApiException('You already own this book', 409, 'already_owned');
            }
        }

        $address = null;
        if ($type === 'hardcopy') {
            $raw = $input['address'] ?? null;
            if (!is_array($raw)) {
                throw new ApiException('Validation failed', 400, 'validation_error', ['address' => 'Required']);
            }
            try {
                $address = AddressValidator::validate($raw);
            } catch (ApiException $e) {
                $prefixed = [];
                foreach ($e->fields as $f => $msg) {
                    $prefixed["address.$f"] = $msg;
                }
                throw new ApiException($e->getMessage(), $e->statusCode, $e->errorCode, $prefixed);
            }
        }

        $stmt = $db->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $email = (string) $stmt->fetchColumn();
        $code = $input['referralCode'] ?? null;
        $partner = is_string($code) ? ReferralService::resolve($code, $email) : null;

        $p = PricingService::forBook($book, $type);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $orderNumber = self::orderNumber();
            // Razorpay order first: a gateway failure leaves no local rows behind.
            $rzp = $this->gateway->createOrder($p['total'], $orderNumber, ['order_number' => $orderNumber]);

            $db->beginTransaction();
            try {
                try {
                    $db->prepare(
                        "INSERT INTO orders (order_number, user_id, book_id, purchase_type, amount, subtotal_amount, shipping_amount,
                            gst_amount, gst_percent, gst_mode, referral_partner_id, referral_code_snapshot, status, fulfilment_status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'not_applicable')"
                    )->execute([
                        $orderNumber, $userId, $book['id'], $type,
                        Money::fromPaise($p['total']), Money::fromPaise($p['subtotal']), Money::fromPaise($p['shipping']),
                        Money::fromPaise($p['gst']), $p['gstPercent'], $p['gstMode'],
                        $partner['id'] ?? null, $partner['code'] ?? null,
                    ]);
                } catch (PDOException $e) {
                    if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'order_number')) {
                        $db->rollBack();
                        continue; // order-number collision: new number, new Razorpay order
                    }
                    throw $e;
                }
                $orderId = (int) $db->lastInsertId();
                $db->prepare("INSERT INTO payments (order_id, razorpay_order_id, amount, status) VALUES (?, ?, ?, 'created')")
                    ->execute([$orderId, $rzp['id'], Money::fromPaise($p['total'])]);
                if ($address) {
                    $db->prepare('INSERT INTO hardcopy_addresses (order_id, full_name, phone, address_line, city, district, state, pincode) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$orderId, $address['fullName'], $address['phone'], $address['addressLine'], $address['city'], $address['district'], $address['state'], $address['pincode']]);
                }
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            $stmt = $db->prepare(OrderFormatter::SELECT . ' WHERE o.id = ?');
            $stmt->execute([$orderId]);
            $row = $stmt->fetch();
            $addrRow = null;
            if ($address) {
                $a = $db->prepare('SELECT * FROM hardcopy_addresses WHERE order_id = ?');
                $a->execute([$orderId]);
                $addrRow = $a->fetch();
            }
            return [
                'order' => OrderFormatter::format($row, $addrRow),
                'razorpay' => ['keyId' => Config::load()['keyId'], 'orderId' => $rzp['id'], 'amount' => $p['total'], 'currency' => 'INR'],
            ];
        }
        throw new \RuntimeException('Could not allocate a unique order number');
    }

    private static function orderNumber(): string
    {
        $bytes = random_bytes(6);
        $suffix = '';
        for ($i = 0; $i < 6; $i++) {
            $suffix .= self::ALPHABET[ord($bytes[$i]) & 31];
        }
        return 'VT-' . date('Ymd') . '-' . $suffix;
    }
}
