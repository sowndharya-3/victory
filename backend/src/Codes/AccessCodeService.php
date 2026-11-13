<?php

namespace App\Codes;

use App\Database\Db;
use App\Http\ApiException;
use PDOException;
use RuntimeException;

/**
 * Digital access codes. book_access is written ONLY by redeem() (and revoked by reissue/revoke).
 * Lock order everywhere: orders row, then access_codes rows, then (redeem) the users row and book_access
 * (markPaid: payments, orders, then inserts here).
 * Plaintext lives only in code_enc (AES-GCM) until redeemed/revoked; never logged, never in audit detail.
 */
class AccessCodeService
{
    /** Called inside markPaid's transaction. Idempotent: no-op when a live code exists or the order is not digital. */
    public static function issueForOrder(int $orderId): void
    {
        $db = Db::connection();
        $stmt = $db->prepare('SELECT id, user_id, book_id, purchase_type FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order || $order['purchase_type'] !== 'digital') {
            return;
        }
        $stmt = $db->prepare("SELECT COUNT(*) FROM access_codes WHERE order_id = ? AND status IN ('active', 'redeemed')");
        $stmt->execute([$orderId]);
        if ((int) $stmt->fetchColumn() === 0) {
            self::insert($order);
        }
    }

    /** Formatted code for the buyer while it is active and unredeemed on a paid order; else null. */
    public static function revealForOwner(int $userId, int $orderId): ?string
    {
        $stmt = Db::connection()->prepare(
            "SELECT ac.id, ac.code_enc FROM access_codes ac JOIN orders o ON o.id = ac.order_id
              WHERE o.id = ? AND o.user_id = ? AND o.status = 'paid' AND ac.status = 'active' AND ac.code_enc IS NOT NULL
              ORDER BY ac.id DESC LIMIT 1"
        );
        $stmt->execute([$orderId, $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $plain = CodeCipher::decrypt($row['code_enc'], (string) $orderId);
        if ($plain === null) {
            error_log("access code {$row['id']}: code_enc failed to decrypt (tampered or CODE_SECRET changed)");
            return null;
        }
        return CodeGenerator::format($plain);
    }

    /** @return array{bookId:int,orderId:int} */
    public static function redeem(int $userId, string $input): array
    {
        // One message for malformed and unknown input: no oracle for the code format.
        $invalid = new ApiException('Invalid code', 400, 'code_invalid');
        $code = strlen($input) <= 64 ? CodeGenerator::normalise($input) : '';
        if (!preg_match(CodeGenerator::DIGITAL_PATTERN, $code)) {
            throw $invalid;
        }
        $hash = CodeCipher::hash($code);
        $db = Db::connection();
        $stmt = $db->prepare('SELECT order_id FROM access_codes WHERE code_hash = ?');
        $stmt->execute([$hash]);
        $orderId = $stmt->fetchColumn();
        if ($orderId === false) {
            throw $invalid;
        }

        return self::transaction(function () use ($db, $userId, $hash, $orderId) {
            $order = self::lockOrder((int) $orderId);
            $stmt = $db->prepare('SELECT * FROM access_codes WHERE code_hash = ? FOR UPDATE');
            $stmt->execute([$hash]);
            $c = $stmt->fetch();
            $result = ['bookId' => (int) $c['book_id'], 'orderId' => (int) $c['order_id']];

            if ($c['status'] === 'revoked') {
                throw new ApiException('This code is no longer valid', 403, 'code_revoked');
            }
            if ($order['status'] !== 'paid') {
                throw new ApiException('The order for this code is not paid', 403, 'code_order_not_paid');
            }
            if ($c['status'] === 'redeemed') {
                if ((int) $c['redeemed_by_user_id'] === $userId) {
                    return $result;
                }
                throw new ApiException('This code has already been redeemed', 409, 'code_already_redeemed');
            }
            // Don't burn a code (and re-point access to another order) when the user already reads this book.
            // The users row lock serialises this user's redeems, so two codes for one book can't both pass;
            // FOR UPDATE makes the check a current read, not the transaction snapshot.
            // ponytail: per-user lock, not per (user, book); fine at one redeem per human at a time.
            $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE')->execute([$userId]);
            $stmt = $db->prepare('SELECT COUNT(*) FROM book_access WHERE user_id = ? AND book_id = ? AND revoked_at IS NULL FOR UPDATE');
            $stmt->execute([$userId, $c['book_id']]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new ApiException('You already own this book', 409, 'already_owned');
            }

            $db->prepare("UPDATE access_codes SET status = 'redeemed', redeemed_by_user_id = ?, redeemed_at = NOW(), code_enc = NULL WHERE id = ?")
                ->execute([$userId, $c['id']]);
            $db->prepare('INSERT INTO book_access (user_id, book_id, order_id) VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE order_id = ?, revoked_at = NULL, granted_at = NOW()')
                ->execute([$userId, $c['book_id'], $c['order_id'], $c['order_id']]);
            return $result;
        });
    }

    /** Revokes the current code (and the access it granted), issues a new one; returns it formatted. */
    public static function reissue(int $orderId, ?int $adminId, ?int $userId, string $reason): string
    {
        if ($adminId !== null) {
            self::adminReason($reason);
        }
        return self::transaction(function () use ($orderId, $adminId, $userId, $reason) {
            $db = Db::connection();
            $order = self::lockOrder($orderId);
            if (!$order || $order['status'] !== 'paid' || $order['purchase_type'] !== 'digital') {
                throw new ApiException('Order not found', 404, 'not_found');
            }
            $stmt = $db->prepare('SELECT * FROM access_codes WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $stmt->execute([$orderId]);
            $old = $stmt->fetch();
            // Customer path: never take back access someone else redeemed, or undo an admin revoke.
            if ($adminId === null && $old && !(
                $old['status'] === 'active'
                || ($old['status'] === 'redeemed' && (int) $old['redeemed_by_user_id'] === (int) $order['user_id'])
                || ($old['status'] === 'revoked' && $old['revoked_reason'] === 'customer_request')
            )) {
                throw new ApiException('Please contact support to reissue this code', 403, 'reissue_requires_admin');
            }
            if ($old && $old['status'] !== 'revoked') {
                self::revokeRow($old, $reason);
            }
            $new = self::insert($order);
            if ($old) {
                $db->prepare('UPDATE access_codes SET replaced_by_id = ? WHERE id = ?')->execute([$new['id'], $old['id']]);
            }
            self::audit($adminId, $userId, 'access_code.reissue', $new['id'], ['orderId' => $orderId, 'replacedCodeId' => $old ? (int) $old['id'] : null, 'reason' => $reason]);
            return $new['code'];
        });
    }

    /** Idempotent: revoking an already revoked code changes nothing. */
    public static function revoke(int $codeId, int $adminId, string $reason): void
    {
        self::adminReason($reason);
        $db = Db::connection();
        $stmt = $db->prepare('SELECT order_id FROM access_codes WHERE id = ?');
        $stmt->execute([$codeId]);
        $orderId = $stmt->fetchColumn();
        if ($orderId === false) {
            throw new ApiException('Access code not found', 404, 'not_found');
        }
        self::transaction(function () use ($db, $codeId, $orderId, $adminId, $reason) {
            self::lockOrder((int) $orderId);
            $stmt = $db->prepare('SELECT * FROM access_codes WHERE id = ? FOR UPDATE');
            $stmt->execute([$codeId]);
            $c = $stmt->fetch();
            if ($c['status'] === 'revoked') {
                return;
            }
            self::revokeRow($c, $reason);
            self::audit($adminId, null, 'access_code.revoke', $codeId, ['orderId' => (int) $orderId, 'reason' => $reason]);
        });
    }

    /**
     * Refund path: revokes the order's current code (and the access its redeemer got), reason 'refund'.
     * Runs inside the caller's transaction with the orders row already locked (RefundService::finalise).
     * Older codes need nothing: reissue always revoked them (and their access) before issuing the next.
     */
    public static function revokeForRefund(int $orderId, ?int $adminId): void
    {
        self::revokeLatest($orderId, $adminId, 'refund');
    }

    /**
     * Admin revoke of the order's current code, decided under the orders + access_codes locks, so a reissue
     * racing in can't leave the new code live. Idempotent when it is already revoked. 404 when the order has no code.
     */
    public static function revokeCurrent(int $orderId, int $adminId, string $reason): void
    {
        self::adminReason($reason);
        self::transaction(function () use ($orderId, $adminId, $reason) {
            if (!self::revokeLatest($orderId, $adminId, $reason)) {
                throw new ApiException('Access code not found', 404, 'not_found');
            }
        });
    }

    /** Lock order: orders row, then the latest access_codes row. Runs in the caller's transaction. False when no code. */
    private static function revokeLatest(int $orderId, ?int $adminId, string $reason): bool
    {
        self::lockOrder($orderId);
        $stmt = Db::connection()->prepare('SELECT * FROM access_codes WHERE order_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $stmt->execute([$orderId]);
        $c = $stmt->fetch();
        if (!$c) {
            return false;
        }
        if ($c['status'] !== 'revoked') {
            self::revokeRow($c, $reason);
            self::audit($adminId, null, 'access_code.revoke', (int) $c['id'], ['orderId' => $orderId, 'reason' => $reason]);
        }
        return true;
    }

    /** 'customer_request' is the sentinel that lets a buyer reissue; an admin reason must never equal it. */
    private static function adminReason(string $reason): void
    {
        if (strtolower(trim($reason)) === 'customer_request') {
            throw new ApiException('Validation failed', 400, 'validation_error', ['reason' => 'This reason is reserved']);
        }
    }

    private static function revokeRow(array $c, string $reason): void
    {
        $db = Db::connection();
        $db->prepare("UPDATE access_codes SET status = 'revoked', revoked_at = NOW(), revoked_reason = ?, code_enc = NULL WHERE id = ?")
            ->execute([mb_substr($reason, 0, 255), $c['id']]);
        if ($c['status'] === 'redeemed') {
            $db->prepare('UPDATE book_access SET revoked_at = NOW() WHERE user_id = ? AND book_id = ? AND order_id = ? AND revoked_at IS NULL')
                ->execute([$c['redeemed_by_user_id'], $c['book_id'], $c['order_id']]);
        }
    }

    /** @return array{id:int,code:string} code is formatted plaintext, for the caller to hand to the owner only */
    private static function insert(array $order): array
    {
        $db = Db::connection();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $code = CodeGenerator::digital();
            try {
                // UNHEX keeps the binary ciphertext out of the connection charset.
                $db->prepare('INSERT INTO access_codes (order_id, user_id, book_id, code_hash, code_last4, code_enc) VALUES (?, ?, ?, ?, ?, UNHEX(?))')
                    ->execute([$order['id'], $order['user_id'], $order['book_id'], CodeCipher::hash($code), substr($code, -4), bin2hex(CodeCipher::encrypt($code, (string) $order['id']))]);
                return ['id' => (int) $db->lastInsertId(), 'code' => CodeGenerator::format($code)];
            } catch (PDOException $e) {
                if ((string) $e->getCode() !== '23000' || !str_contains($e->getMessage(), 'code_hash')) {
                    throw $e; // only a code_hash collision is retried
                }
            }
        }
        throw new RuntimeException('Could not allocate a unique access code');
    }

    private static function lockOrder(int $orderId): array|false
    {
        $stmt = Db::connection()->prepare('SELECT id, user_id, book_id, status, purchase_type FROM orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        return $stmt->fetch();
    }

    private static function audit(?int $adminId, ?int $userId, string $action, int $entityId, array $detail): void
    {
        Db::connection()->prepare("INSERT INTO audit_logs (admin_id, user_id, action, entity_type, entity_id, detail) VALUES (?, ?, ?, 'access_code', ?, ?)")
            ->execute([$adminId, $userId, $action, $entityId, json_encode($detail)]);
    }

    /** Runs $fn in a transaction; a deadlock (1213, whole transaction rolled back) is retried once. */
    private static function transaction(callable $fn, bool $retry = true): mixed
    {
        $db = Db::connection();
        $db->beginTransaction();
        try {
            $out = $fn();
            $db->commit();
            return $out;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($retry && $e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1213) {
                return self::transaction($fn, false);
            }
            throw $e;
        }
    }
}
