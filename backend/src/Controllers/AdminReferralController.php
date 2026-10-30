<?php

namespace App\Controllers;

use App\Codes\CodeGenerator;
use App\Database\Db;
use App\Http\ApiException;
use App\Middleware\AuthMiddleware;
use App\Referral\CommissionService;

/**
 * Partner applications (approve / reject, only from pending, row-locked) and partners (toggle, contact/UPI edits).
 * Deactivating or editing a partner never touches orders or commissions: attribution was snapshotted at order time.
 */
class AdminReferralController
{
    private const APPLICATION_STATUSES = ['pending', 'approved', 'rejected'];

    public function list(): void
    {
        AuthMiddleware::requireRole(['admin']);
        [$page, $perPage] = AdminOrderController::paging();
        $status = $_GET['status'] ?? '';
        $where = '';
        $params = [];
        if ($status !== '') {
            if (!in_array($status, self::APPLICATION_STATUSES, true)) {
                throw new ApiException('Validation failed', 400, 'validation_error', ['status' => 'Must be one of ' . implode(', ', self::APPLICATION_STATUSES)]);
            }
            $where = ' WHERE a.application_status = ?';
            $params[] = $status;
        }
        $db = Db::connection();
        $count = $db->prepare('SELECT COUNT(*) FROM referral_applications a' . $where);
        $count->execute($params);
        $stmt = $db->prepare('SELECT a.*, p.id AS partner_id FROM referral_applications a LEFT JOIN referral_partners p ON p.application_id = a.id'
            . $where . ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        $stmt->execute($params);
        // ponytail: partners unpaginated (dozens expected); page them like applications if that grows.
        $partners = $db->query('SELECT * FROM referral_partners ORDER BY id DESC')->fetchAll();
        echo json_encode([
            'applications' => ['items' => array_map([self::class, 'application'], $stmt->fetchAll()), 'page' => $page, 'perPage' => $perPage, 'total' => (int) $count->fetchColumn()],
            'partners' => array_map([self::class, 'partner'], $partners),
        ]);
    }

    public function approve(string $id): void
    {
        $admin = AuthMiddleware::requireRole(['admin']);
        $appId = AdminOrderController::id($id);
        $db = Db::connection();
        $db->beginTransaction();
        try {
            $a = self::lockPending($appId, 'approved');
            $partnerId = null;
            for ($try = 0; $partnerId === null; $try++) {
                $code = CodeGenerator::partner();
                try {
                    $db->prepare('INSERT INTO referral_partners (application_id, name, phone, whatsapp, upi_id, gmail, referral_code, active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)')
                        ->execute([$appId, $a['name'], $a['phone'], $a['whatsapp'], $a['upi_id'], $a['gmail'], $code]);
                    $partnerId = (int) $db->lastInsertId();
                } catch (\PDOException $e) {
                    // referral_code is the only unique key besides the PK: a duplicate means a code collision, retry.
                    if (($e->errorInfo[1] ?? null) !== 1062 || $try >= 4) {
                        throw $e;
                    }
                }
            }
            $db->prepare("UPDATE referral_applications SET application_status = 'approved', reviewed_at = NOW() WHERE id = ?")->execute([$appId]);
            CommissionService::audit((int) $admin['sub'], 'referral.approve', 'referral_application', $appId, ['partnerId' => $partnerId, 'referralCode' => $code]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        echo json_encode(['application' => self::findApplication($appId), 'partner' => self::findPartner($partnerId)]);
    }

    public function reject(string $id): void
    {
        $admin = AuthMiddleware::requireRole(['admin']);
        $appId = AdminOrderController::id($id);
        $reason = AdminOrderController::text(AdminOrderController::body(), 'reason', 500, false);
        $db = Db::connection();
        $db->beginTransaction();
        try {
            self::lockPending($appId, 'rejected');
            $db->prepare("UPDATE referral_applications SET application_status = 'rejected', reviewed_at = NOW() WHERE id = ?")->execute([$appId]);
            CommissionService::audit((int) $admin['sub'], 'referral.reject', 'referral_application', $appId, ['reason' => $reason]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        echo json_encode(['application' => self::findApplication($appId)]);
    }

    public function toggleActive(string $id): void
    {
        $admin = AuthMiddleware::requireRole(['admin']);
        $partnerId = AdminOrderController::id($id);
        $db = Db::connection();
        $db->beginTransaction();
        try {
            $p = self::lockPartner($partnerId);
            $active = $p['active'] ? 0 : 1;
            $db->prepare('UPDATE referral_partners SET active = ? WHERE id = ?')->execute([$active, $partnerId]);
            CommissionService::audit((int) $admin['sub'], 'partner.toggle_active', 'referral_partner', $partnerId, ['active' => (bool) $active]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        echo json_encode(self::findPartner($partnerId));
    }

    public function update(string $id): void
    {
        $admin = AuthMiddleware::requireRole(['admin']);
        $partnerId = AdminOrderController::id($id);
        $set = ReferralApplicationController::validate(AdminOrderController::body(), ReferralApplicationController::PARTNER_FIELDS, true);
        $db = Db::connection();
        $db->beginTransaction();
        try {
            $p = self::lockPartner($partnerId);
            $from = $to = [];
            foreach ($set as $col => $v) {
                if ($p[$col] !== $v) {
                    $from[$col] = $p[$col];
                    $to[$col] = $v;
                }
            }
            if ($to) {
                // Column names come from the FIELDS whitelist, never from the request.
                $db->prepare('UPDATE referral_partners SET ' . implode(', ', array_map(fn ($c) => "$c = ?", array_keys($to))) . ' WHERE id = ?')
                    ->execute([...array_values($to), $partnerId]);
                CommissionService::audit((int) $admin['sub'], 'partner.update', 'referral_partner', $partnerId, ['from' => $from, 'to' => $to]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        echo json_encode(self::findPartner($partnerId));
    }

    private static function lockPending(int $appId, string $to): array
    {
        $stmt = Db::connection()->prepare('SELECT * FROM referral_applications WHERE id = ? FOR UPDATE');
        $stmt->execute([$appId]);
        $a = $stmt->fetch();
        if (!$a) {
            throw new ApiException('Application not found', 404, 'not_found');
        }
        if ($a['application_status'] !== 'pending') {
            throw new ApiException("Cannot move application from {$a['application_status']} to $to", 400, 'invalid_transition');
        }
        return $a;
    }

    private static function lockPartner(int $partnerId): array
    {
        $stmt = Db::connection()->prepare('SELECT * FROM referral_partners WHERE id = ? FOR UPDATE');
        $stmt->execute([$partnerId]);
        $p = $stmt->fetch();
        if (!$p) {
            throw new ApiException('Partner not found', 404, 'not_found');
        }
        return $p;
    }

    private static function findApplication(int $id): array
    {
        $stmt = Db::connection()->prepare('SELECT a.*, p.id AS partner_id FROM referral_applications a LEFT JOIN referral_partners p ON p.application_id = a.id WHERE a.id = ?');
        $stmt->execute([$id]);
        return self::application($stmt->fetch());
    }

    private static function findPartner(int $id): array
    {
        $stmt = Db::connection()->prepare('SELECT * FROM referral_partners WHERE id = ?');
        $stmt->execute([$id]);
        return self::partner($stmt->fetch());
    }

    public static function application(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'name' => $a['name'],
            'qualification' => $a['qualification'],
            'place' => $a['place'],
            'district' => $a['district'],
            'status' => $a['status'],
            'phone' => $a['phone'],
            'whatsapp' => $a['whatsapp'],
            'upiId' => $a['upi_id'],
            'gmail' => $a['gmail'],
            'applicationStatus' => $a['application_status'],
            'reviewedAt' => $a['reviewed_at'],
            'createdAt' => $a['created_at'],
            'partnerId' => $a['partner_id'] === null ? null : (int) $a['partner_id'],
        ];
    }

    public static function partner(array $p): array
    {
        return [
            'id' => (int) $p['id'],
            'applicationId' => $p['application_id'] === null ? null : (int) $p['application_id'],
            'name' => $p['name'],
            'phone' => $p['phone'],
            'whatsapp' => $p['whatsapp'],
            'upiId' => $p['upi_id'],
            'gmail' => $p['gmail'],
            'referralCode' => $p['referral_code'],
            'active' => (bool) $p['active'],
            'createdAt' => $p['created_at'],
            'updatedAt' => $p['updated_at'],
        ];
    }
}
