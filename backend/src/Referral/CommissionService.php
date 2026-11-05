<?php

namespace App\Referral;

use App\Database\Db;
use App\Http\ApiException;
use App\Support\Money;
use App\Support\Settings;

/**
 * Flat-rupee commission rates and the admin payout lifecycle pending -> payable -> paid.
 * Rows are created only by OrderFulfillment::markPaid (amount snapshotted there) and reversed only by
 * RefundService; nothing here recomputes an amount. Locks a single commissions row, so it cannot
 * deadlock with RefundService (which takes the commission lock last).
 */
class CommissionService
{
    public const MAX_RATE_PAISE = 1000000; // Rs 10000
    private const REFERENCE = '/^[A-Za-z0-9][A-Za-z0-9 .\/#:_-]{0,99}\z/';

    /** Joined row for format(): commission + order number + partner. */
    public const SELECT = 'SELECT c.*, o.order_number, p.name AS partner_name, p.referral_code, p.active AS partner_active
        FROM commissions c JOIN orders o ON o.id = c.order_id JOIN referral_partners p ON p.id = c.referral_partner_id';

    /** @return array{digital:string,hardcopy:string} */
    public static function ratesRupees(): array
    {
        return [
            'digital' => Money::fromPaise(Money::toPaise(Settings::get('commission_rate_digital', '25'))),
            'hardcopy' => Money::fromPaise(Money::toPaise(Settings::get('commission_rate_hardcopy', '25'))),
        ];
    }

    /** Affects only orders paid afterwards: existing rows keep their snapshotted amount. */
    public static function setRates(string $digital, string $hardcopy, int $adminId): void
    {
        $to = [];
        $errors = [];
        foreach (['digital' => $digital, 'hardcopy' => $hardcopy] as $type => $raw) {
            $to[$type] = Money::parseAmount($raw, self::MAX_RATE_PAISE);
            if ($to[$type] === null) {
                $errors[$type] = 'Must be a rupee amount from 0 to 10000 with at most 2 decimals';
            }
        }
        if ($errors) {
            throw new ApiException('Validation failed', 400, 'validation_error', $errors);
        }
        $db = Db::connection();
        $db->beginTransaction();
        try {
            $db->query("SELECT `key` FROM settings WHERE `key` IN ('commission_rate_digital', 'commission_rate_hardcopy') FOR UPDATE")->fetchAll();
            $from = self::ratesRupees();
            foreach ($to as $type => $value) {
                Settings::set("commission_rate_$type", $value);
            }
            self::audit($adminId, 'settings.commission', 'settings', null, ['from' => $from, 'to' => $to]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function markPayable(int $commissionId, int $adminId): array
    {
        return self::transition($commissionId, 'pending', 'payable', $adminId, null);
    }

    public static function markPaid(int $commissionId, int $adminId, string $payoutReference): array
    {
        $ref = trim($payoutReference);
        if (!preg_match(self::REFERENCE, $ref)) {
            throw new ApiException('Validation failed', 400, 'validation_error', [
                'payoutReference' => 'Required: 1-100 characters, letters, digits, space and . / # : _ -',
            ]);
        }
        return self::transition($commissionId, 'payable', 'paid', $adminId, $ref);
    }

    public static function format(array $c): array
    {
        return [
            'id' => (int) $c['id'],
            'orderId' => (int) $c['order_id'],
            'orderNumber' => $c['order_number'],
            'partner' => [
                'id' => (int) $c['referral_partner_id'],
                'name' => $c['partner_name'],
                'referralCode' => $c['referral_code'],
                'active' => (bool) $c['partner_active'],
            ],
            'purchaseType' => $c['purchase_type'],
            'amount' => Money::fromPaise(Money::toPaise((string) $c['amount'])),
            'status' => $c['status'],
            'payoutReference' => $c['payout_reference'],
            'payableAt' => $c['payable_at'],
            'paidAt' => $c['paid_at'],
            'clawbackDue' => (bool) $c['clawback_due'],
            'reversedAt' => $c['reversed_at'],
            'createdAt' => $c['created_at'],
        ];
    }

    public static function find(int $id): array
    {
        $stmt = Db::connection()->prepare(self::SELECT . ' WHERE c.id = ?');
        $stmt->execute([$id]);
        $c = $stmt->fetch();
        if (!$c) {
            throw new ApiException('Commission not found', 404, 'not_found');
        }
        return self::format($c);
    }

    private static function transition(int $id, string $from, string $to, int $adminId, ?string $reference): array
    {
        $db = Db::connection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT status FROM commissions WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $status = $stmt->fetchColumn();
            if ($status === false) {
                throw new ApiException('Commission not found', 404, 'not_found');
            }
            if ($status !== $from) {
                // 400 like the other admin transitions (Task 19); refunds use 409 for their own state machine.
                throw new ApiException("Cannot move commission from $status to $to", 400, 'invalid_transition');
            }
            if ($to === 'payable') {
                $db->prepare("UPDATE commissions SET status = 'payable', payable_at = NOW() WHERE id = ?")->execute([$id]);
            } else {
                $db->prepare("UPDATE commissions SET status = 'paid', paid_at = NOW(), payout_reference = ? WHERE id = ?")->execute([$reference, $id]);
            }
            self::audit($adminId, "commission.$to", 'commission', $id, ['from' => $from, 'to' => $to] + ($reference === null ? [] : ['payoutReference' => $reference]));
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return self::find($id);
    }

    public static function audit(int $adminId, string $action, string $entityType, ?int $entityId, array $detail): void
    {
        Db::connection()->prepare('INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, detail) VALUES (?, ?, ?, ?, ?)')
            ->execute([$adminId, $action, $entityType, $entityId, json_encode($detail)]);
    }
}
