<?php

namespace App\Controllers;

use App\Database\Db;
use App\Http\ApiException;
use App\Middleware\AuthMiddleware;
use App\Orders\OrderFormatter;
use App\Orders\OrderFulfillment;
use App\Orders\OrderService;
use App\Razorpay\Config;
use App\Razorpay\GatewayFactory;
use App\Razorpay\Signature;

class OrderController
{
    public function create(): void
    {
        $payload = AuthMiddleware::requireRole(['customer']);
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            throw new ApiException('Invalid JSON body', 400, 'validation_error');
        }
        $result = (new OrderService(GatewayFactory::make()))->create((int) $payload['sub'], $data);
        http_response_code(201);
        echo json_encode($result);
    }

    public function verify(string $id): void
    {
        $payload = AuthMiddleware::requireRole(['customer']);
        $userId = (int) $payload['sub'];
        $db = Db::connection();

        $stmt = $db->prepare('SELECT o.id, p.razorpay_order_id FROM orders o JOIN payments p ON p.order_id = o.id WHERE o.id = ? AND o.user_id = ?');
        $stmt->execute([(int) $id, $userId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new ApiException('Order not found', 404, 'not_found');
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $rzpOrderId = is_array($data) ? ($data['razorpay_order_id'] ?? null) : null;
        $paymentId = is_array($data) ? ($data['razorpay_payment_id'] ?? null) : null;
        $signature = is_array($data) ? ($data['razorpay_signature'] ?? null) : null;
        if (!is_string($rzpOrderId) || !is_string($paymentId) || !is_string($signature) || $rzpOrderId === '' || $paymentId === '' || $signature === '') {
            throw new ApiException('razorpay_order_id, razorpay_payment_id and razorpay_signature are required', 400, 'validation_error');
        }

        $fail = fn () => new ApiException('Payment verification failed', 400, 'payment_verification_failed');
        if (!hash_equals($order['razorpay_order_id'], $rzpOrderId)
            || !Signature::verifyCheckout($rzpOrderId, $paymentId, $signature, Config::load()['keySecret'])) {
            throw $fail();
        }

        // Never trust the client: status and amount come from the gateway.
        $payment = GatewayFactory::make()->fetchPayment($paymentId);
        if (($payment['order_id'] ?? null) !== $rzpOrderId) {
            throw $fail();
        }

        $code = 200;
        switch ($payment['status'] ?? null) {
            case 'captured':
                $result = OrderFulfillment::markPaid($rzpOrderId, $paymentId, (int) $payment['amount'], $payment);
                if ($result === 'amount_mismatch') {
                    throw new ApiException('Payment could not be confirmed; support has been notified', 409, 'payment_amount_mismatch');
                }
                break;
            case 'failed':
                OrderFulfillment::markFailed($rzpOrderId, $paymentId, (string) ($payment['error_description'] ?? 'Payment failed'));
                break;
            case 'authorized':
                $code = 202;
                break;
            default:
                throw new ApiException('Payment gateway error', 502, 'gateway_error');
        }

        http_response_code($code);
        echo json_encode(['order' => $this->load((int) $order['id'], $userId)]);
    }

    public function mine(): void
    {
        $payload = AuthMiddleware::requireRole(['customer']);
        $stmt = Db::connection()->prepare('SELECT o.*, b.title AS book_title FROM orders o JOIN books b ON b.id = o.book_id WHERE o.user_id = ? ORDER BY o.id DESC');
        $stmt->execute([(int) $payload['sub']]);
        echo json_encode(array_map(fn ($o) => OrderFormatter::format($o), $stmt->fetchAll()));
    }

    public function showMine(string $id): void
    {
        $payload = AuthMiddleware::requireRole(['customer']);
        echo json_encode($this->load((int) $id, (int) $payload['sub']));
    }

    /** Fresh formatted order (with address for hard copy), only if it belongs to $userId. */
    private function load(int $id, int $userId): array
    {
        $db = Db::connection();
        $stmt = $db->prepare('SELECT o.*, b.title AS book_title FROM orders o JOIN books b ON b.id = o.book_id WHERE o.id = ? AND o.user_id = ?');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new ApiException('Order not found', 404, 'not_found');
        }
        $addr = null;
        if ($row['purchase_type'] === 'hardcopy') {
            $a = $db->prepare('SELECT * FROM hardcopy_addresses WHERE order_id = ?');
            $a->execute([$id]);
            $addr = $a->fetch() ?: null;
        }
        return OrderFormatter::format($row, $addr);
    }
}
