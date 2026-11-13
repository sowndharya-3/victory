<?php

namespace App\Controllers;

use App\Database\Db;
use App\Http\ApiException;
use App\Middleware\AuthMiddleware;
use App\Referral\CommissionService;
use App\Support\Money;
use App\Support\Settings;

/** Commission rows + payout transitions, commission rates, and the shipping/GST settings (U1/U2). Money out as "25.00" strings. */
class AdminCommissionController
{
    private const STATUSES = ['pending', 'payable', 'paid', 'reversed'];
    /** JSON key => [settings key, max in paise/basis points]; shipping up to Rs 10000, GST 0..100 %. */
    private const PRICING = [
        'shippingChargeDigital' => ['shipping_charge_digital', 1000000],
        'shippingChargeHardcopy' => ['shipping_charge_hardcopy', 1000000],
        'gstPercentDigital' => ['gst_percent_digital', 10000],
        'gstPercentHardcopy' => ['gst_percent_hardcopy', 10000],
    ];
    private const GST_MODES = ['inclusive', 'exclusive'];

    public function list(): void
    {
        AuthMiddleware::requireRole(['admin']);
        [$page, $perPage] = AdminOrderController::paging();
        $partnerWhere = '';
        $partnerParams = [];
        $partnerId = $_GET['partnerId'] ?? '';
        if ($partnerId !== '') {
            if (!is_string($partnerId) || !ctype_digit($partnerId) || strlen($partnerId) > 10) {
                throw new ApiException('Validation failed', 400, 'validation_error', ['partnerId' => 'Must be a positive integer']);
            }
            $partnerWhere = ' WHERE c.referral_partner_id = ?';
            $partnerParams[] = (int) $partnerId;
        }
        $where = $partnerWhere;
        $params = $partnerParams;
        $status = $_GET['status'] ?? '';
        if ($status !== '') {
            if (!in_array($status, self::STATUSES, true)) {
                throw new ApiException('Validation failed', 400, 'validation_error', ['status' => 'Must be one of ' . implode(', ', self::STATUSES)]);
            }
            $where .= ($where === '' ? ' WHERE' : ' AND') . ' c.status = ?';
            $params[] = $status;
        }
        $db = Db::connection();
        $count = $db->prepare('SELECT COUNT(*) FROM commissions c' . $where);
        $count->execute($params);
        $stmt = $db->prepare(CommissionService::SELECT . $where . ' ORDER BY c.created_at DESC, c.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        $stmt->execute($params);

        // Totals per status over the partner filter only (the status filter would make them trivial). SUM of DECIMAL is exact.
        $totals = array_fill_keys(self::STATUSES, ['count' => 0, 'amount' => '0.00']);
        $t = $db->prepare('SELECT c.status, COUNT(*) AS n, SUM(c.amount) AS total FROM commissions c' . $partnerWhere . ' GROUP BY c.status');
        $t->execute($partnerParams);
        foreach ($t->fetchAll() as $r) {
            $totals[$r['status']] = ['count' => (int) $r['n'], 'amount' => Money::fromPaise(Money::toPaise((string) $r['total']))];
        }
        $c = $db->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(c.amount), 0) AS total FROM commissions c' . ($partnerWhere === '' ? ' WHERE' : $partnerWhere . ' AND') . ' c.clawback_due = 1');
        $c->execute($partnerParams);
        $claw = $c->fetch();
        $totals['clawbackDue'] = ['count' => (int) $claw['n'], 'amount' => Money::fromPaise(Money::toPaise((string) $claw['total']))];

        echo json_encode([
            'items' => array_map([CommissionService::class, 'format'], $stmt->fetchAll()),
            'page' => $page, 'perPage' => $perPage, 'total' => (int) $count->fetchColumn(),
            'totals' => $totals,
        ]);
    }

    public function payable(string $id): void
    {
        $admin = AuthMiddleware::requireRole(['admin']);
        echo json_encode(CommissionService::markPayable(AdminOrderController::id($id), (int) $admin['sub']));
    }

    public function paid(string $id): void
    {
        $admin = AuthMiddleware::requireRole(['admin']);
        $ref = AdminOrderController::body()['payoutReference'] ?? '';
        echo json_encode(CommissionService::markPaid(AdminOrderController::id($id), (int) $admin['sub'], is_string($ref) ? $ref : ''));
    }

    public function commissionSettings(): void
    {
        AuthMiddleware::requireRole(['admin']);
        echo json_encode(CommissionService::ratesRupees());
    }

    public function saveCommissionSettings(): void
    {
        $admin = AuthMiddleware::requireRole(['admin']);
        $data = AdminOrderController::body();
        // JSON numbers become their text form; anything else non-string fails validation as ''.
        $s = fn ($v) => is_string($v) ? $v : (is_int($v) || is_float($v) ? (string) $v : '');
        CommissionService::setRates($s($data['digital'] ?? null), $s($data['hardcopy'] ?? null), (int) $admin['sub']);
        echo json_encode(CommissionService::ratesRupees());
    }

    public function pricingSettings(): void
    {
        AuthMiddleware::requireRole(['admin']);
        echo json_encode(self::pricing());
    }

    /** Partial update of any of the five keys; every value validated, unknown keys rejected, one audit row. */
    public function savePricingSettings(): void
    {
        $admin = AuthMiddleware::requireRole(['admin']);
        $data = AdminOrderController::body();
        $to = [];
        $errors = [];
        foreach ($data as $key => $value) {
            if ($key === 'gstMode') {
                if (in_array($value, self::GST_MODES, true)) {
                    $to[$key] = $value;
                } else {
                    $errors[$key] = 'Must be inclusive or exclusive';
                }
            } elseif (isset(self::PRICING[$key])) {
                $to[$key] = Money::parseAmount($value, self::PRICING[$key][1]);
                if ($to[$key] === null) {
                    $errors[$key] = str_starts_with($key, 'gst') ? 'Must be a percent from 0 to 100 with at most 2 decimals' : 'Must be a rupee amount from 0 to 10000 with at most 2 decimals';
                }
            } else {
                $errors[(string) $key] = 'Unknown setting';
            }
        }
        if (!$errors && !$to) {
            $errors['body'] = 'Nothing to update; settable: ' . implode(', ', [...array_keys(self::PRICING), 'gstMode']);
        }
        if ($errors) {
            throw new ApiException('Validation failed', 400, 'validation_error', $errors);
        }
        $db = Db::connection();
        $db->beginTransaction();
        try {
            $db->query("SELECT `key` FROM settings WHERE `key` IN ('shipping_charge_digital', 'shipping_charge_hardcopy', 'gst_percent_digital', 'gst_percent_hardcopy', 'gst_mode') FOR UPDATE")->fetchAll();
            $before = self::pricing();
            foreach ($to as $key => $value) {
                Settings::set($key === 'gstMode' ? 'gst_mode' : self::PRICING[$key][0], $value);
            }
            CommissionService::audit((int) $admin['sub'], 'settings.pricing', 'settings', null, [
                'from' => array_intersect_key($before, $to), 'to' => $to,
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        echo json_encode(self::pricing());
    }

    private static function pricing(): array
    {
        $out = [];
        foreach (self::PRICING as $key => [$setting]) {
            $out[$key] = Money::fromPaise(Money::toPaise(Settings::get($setting, '0')));
        }
        $out['gstMode'] = Settings::get('gst_mode', 'inclusive');
        return $out;
    }
}
