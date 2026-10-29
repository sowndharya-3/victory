<?php

namespace App\Controllers;

use App\Database\Db;
use App\Http\ApiException;
use App\Orders\OrderFulfillment;
use App\Razorpay\Config;
use App\Razorpay\Signature;
use PDOException;

class WebhookController
{
    public function razorpay(): void
    {
        // Signature is over the raw bytes: read once, verify, only then decode.
        $raw = (string) file_get_contents('php://input');
        if (!Signature::verifyWebhook($raw, $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '', Config::load()['webhookSecret'])) {
            throw new ApiException('Invalid signature', 400, 'invalid_signature');
        }
        $eventId = trim((string) ($_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? ''));
        if ($eventId === '' || strlen($eventId) > 100) {
            throw new ApiException('X-Razorpay-Event-Id header is required', 400, 'validation_error');
        }
        $event = json_decode($raw, true);
        if (!is_array($event) || !is_string($event['event'] ?? null)) {
            throw new ApiException('Invalid JSON body', 400, 'validation_error');
        }
        $type = $event['event'];

        $db = Db::connection();
        try {
            $db->prepare('INSERT INTO payment_events (event_id, event_type, payload) VALUES (?, ?, ?)')
                ->execute([$eventId, substr($type, 0, 60), $raw]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $stmt = $db->prepare('SELECT processed_at IS NOT NULL FROM payment_events WHERE event_id = ?');
            $stmt->execute([$eventId]);
            if ((bool) $stmt->fetchColumn()) {
                $this->respond('duplicate');
                return;
            }
            // Unprocessed earlier attempt (e.g. a crash): handle it again.
        }

        $status = $this->handle($type, $event);
        $db->prepare('UPDATE payment_events SET processed_at = NOW() WHERE event_id = ?')->execute([$eventId]);
        $this->respond($status);
    }

    private function handle(string $type, array $event): string
    {
        $entity = $event['payload']['payment']['entity'] ?? null;
        if (!in_array($type, ['payment.captured', 'payment.failed'], true)) {
            return 'ignored';
        }
        $rzpOrderId = is_array($entity) ? ($entity['order_id'] ?? null) : null;
        $paymentId = is_array($entity) ? ($entity['id'] ?? null) : null;
        if (!is_string($rzpOrderId) || !is_string($paymentId) || $rzpOrderId === '' || $paymentId === '') {
            return 'ignored';
        }

        if ($type === 'payment.failed') {
            $reason = is_string($entity['error_description'] ?? null) && $entity['error_description'] !== '' ? $entity['error_description'] : 'payment failed';
            return match (OrderFulfillment::markFailed($rzpOrderId, $paymentId, $reason)) {
                'failed' => 'ok',
                default => 'ignored',
            };
        }

        $amount = $entity['amount'] ?? null;
        if (!is_int($amount)) {
            return 'ignored';
        }
        $result = OrderFulfillment::markPaid($rzpOrderId, $paymentId, $amount, $entity);
        if ($result === 'unknown_order') {
            error_log("razorpay webhook: payment.captured for unknown razorpay order (razorpay_order_id={$rzpOrderId}, payment_id={$paymentId})");
        }
        if ($result === 'already_paid') {
            $this->flagDuplicatePayment($rzpOrderId, $paymentId);
        }
        return match ($result) {
            'paid', 'already_paid' => 'ok',
            'amount_mismatch' => 'flagged',
            default => 'ignored',
        };
    }

    /** A different payment captured against an already-paid order: leave a trail for admin review (never overwrite an existing flag). */
    private function flagDuplicatePayment(string $rzpOrderId, string $paymentId): void
    {
        $db = Db::connection();
        $stmt = $db->prepare('SELECT razorpay_payment_id FROM payments WHERE razorpay_order_id = ?');
        $stmt->execute([$rzpOrderId]);
        $stored = $stmt->fetchColumn();
        if ($stored === false || $stored === $paymentId) {
            return;
        }
        error_log("razorpay webhook: duplicate payment on paid order (razorpay_order_id={$rzpOrderId}, payment_id={$paymentId})");
        $db->prepare("UPDATE orders SET admin_flag = 'duplicate_payment' WHERE admin_flag IS NULL AND id = (SELECT order_id FROM payments WHERE razorpay_order_id = ?)")
            ->execute([$rzpOrderId]);
    }

    private function respond(string $status): void
    {
        echo json_encode(['status' => $status]);
    }
}
