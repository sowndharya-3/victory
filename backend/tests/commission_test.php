<?php

// CommissionService (Task 20): flat-rupee rates, payout transitions, snapshot rule. Runs against the dev DB;
// every row it creates is removed and the commission rates are restored in `finally`.

require_once __DIR__ . '/../config/env.php';
loadEnv(__DIR__ . '/../.env');

spl_autoload_register(function (string $class) {
    if (str_starts_with($class, 'App\\')) {
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (file_exists($path)) {
            require $path;
        }
    }
});

use App\Codes\CodeGenerator;
use App\Database\Db;
use App\Http\ApiException;
use App\Orders\OrderFulfillment;
use App\Referral\CommissionService;
use App\Support\Money;

$db = Db::connection();
$tag = 'cms' . getmypid() . time();
$failures = 0;
$userIds = [];
$bookId = 0;
$partnerId = 0;
$ADMIN = 1;
$auditFrom = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM audit_logs')->fetchColumn();
$savedRates = $db->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'commission_rate_%'")->fetchAll(PDO::FETCH_KEY_PAIR);

function check(string $name, bool $ok, string $extra = ''): void
{
    global $failures;
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $name . ($ok || $extra === '' ? '' : " ($extra)") . "\n";
    if (!$ok) {
        $failures++;
    }
}

function q(string $sql, array $p = []): PDOStatement
{
    global $db;
    $s = $db->prepare($sql);
    $s->execute($p);
    return $s;
}

function val(string $sql, array $p = []): mixed
{
    return q($sql, $p)->fetchColumn();
}

/** @return string 'ok' or 'errorCode|status|fieldsJson' */
function attempt(callable $fn): string
{
    try {
        $fn();
        return 'ok';
    } catch (ApiException $e) {
        return $e->errorCode . '|' . $e->statusCode . '|' . json_encode($e->fields);
    }
}

/** Referral order paid through the real markPaid path; returns the commission id. */
function paidReferralOrder(string $type): int
{
    global $db, $bookId, $tag, $partnerId, $userIds;
    static $i = 0;
    $i++;
    q('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)', ["CMS $i", "$tag-$i@example.com", 'x']);
    $uid = $userIds[] = (int) $db->lastInsertId();
    q(
        "INSERT INTO orders (order_number, user_id, book_id, purchase_type, amount, status, fulfilment_status, referral_partner_id) VALUES (?, ?, ?, ?, '49.90', 'pending', 'not_applicable', ?)",
        ["$tag-$i", $uid, $bookId, $type, $partnerId]
    );
    $id = (int) $db->lastInsertId();
    q("INSERT INTO payments (order_id, razorpay_order_id, amount, status) VALUES (?, ?, '49.90', 'created')", [$id, "order_$tag$i"]);
    OrderFulfillment::markPaid("order_$tag$i", "pay_$tag$i", 4990);
    return (int) val('SELECT id FROM commissions WHERE order_id = ?', [$id]);
}

function cm(int $id): array
{
    return q('SELECT * FROM commissions WHERE id = ?', [$id])->fetch();
}

try {
    // --- partner codes ---
    $codes = [];
    for ($n = 0; $n < 200; $n++) {
        $codes[] = CodeGenerator::partner();
    }
    check('partner code = VTP- + 6 Crockford chars', count(array_filter($codes, fn ($c) => preg_match('/^VTP-[0-9A-HJKMNP-TV-Z]{6}$/', $c) === 1)) === 200);
    check('200 partner codes are distinct (random, not sequential)', count(array_unique($codes)) === 200);

    // --- rates ---
    $r = CommissionService::ratesRupees();
    check('ratesRupees returns 2-decimal strings for both types', is_string($r['digital']) && preg_match('/^\d+\.\d{2}$/', $r['digital']) === 1 && preg_match('/^\d+\.\d{2}$/', $r['hardcopy']) === 1, json_encode($r));
    foreach (['-1', '10000.01', '1.234', 'abc', '', '1e3', '0x10', '25 ', ' 25', '+5', '.5', '25.'] as $bad) {
        check("setRates rejects " . json_encode($bad), str_starts_with(attempt(fn () => CommissionService::setRates($bad, '25', $ADMIN)), 'validation_error|400|{"digital"'));
    }
    check('setRates names the hardcopy field', str_starts_with(attempt(fn () => CommissionService::setRates('25', '-3', $ADMIN)), 'validation_error|400|{"hardcopy"'));
    $before = (int) val('SELECT COUNT(*) FROM audit_logs WHERE id > ?', [$auditFrom]);
    check('rejected setRates writes no audit row', $before === 0);
    check('setRates accepts bounds 0 and 10000', attempt(fn () => CommissionService::setRates('0', '10000', $ADMIN)) === 'ok' && CommissionService::ratesRupees() === ['digital' => '0.00', 'hardcopy' => '10000.00']);

    q('INSERT INTO books (title, online_price, hardcopy_price, published) VALUES (?, 100, 200, 1)', ["CMS throwaway $tag"]);
    $bookId = (int) $db->lastInsertId();
    q("INSERT INTO referral_partners (name, phone, whatsapp, gmail, referral_code, active) VALUES ('CMS partner', '9876543210', '9876543210', ?, ?, 1)", ["$tag@example.com", substr("CT$tag", 0, 30)]);
    $partnerId = (int) $db->lastInsertId();

    CommissionService::setRates('25', '25', $ADMIN);
    $old = paidReferralOrder('digital');
    check('commission snapshotted at 25.00, pending', cm($old)['amount'] === '25.00' && cm($old)['status'] === 'pending');
    CommissionService::setRates('30', '25', $ADMIN);
    check("setRates('30','25') stores 30.00 / 25.00", CommissionService::ratesRupees() === ['digital' => '30.00', 'hardcopy' => '25.00']);
    $audit = q("SELECT * FROM audit_logs WHERE id > ? AND action = 'settings.commission' ORDER BY id DESC LIMIT 1", [$auditFrom])->fetch();
    $detail = json_decode($audit['detail'] ?? 'null', true);
    check('setRates writes an audit row with admin, from and to', $audit && (int) $audit['admin_id'] === $ADMIN && $detail['from'] === ['digital' => '25.00', 'hardcopy' => '25.00'] && $detail['to'] === ['digital' => '30.00', 'hardcopy' => '25.00'], json_encode($audit));
    check('rate change leaves the existing row at 25.00', cm($old)['amount'] === '25.00');
    $newD = paidReferralOrder('digital');
    $newH = paidReferralOrder('hardcopy');
    check('later digital order snapshots 30.00, hard copy 25.00', cm($newD)['amount'] === '30.00' && cm($newH)['amount'] === '25.00');

    // --- transitions ---
    check('markPaid on pending -> invalid_transition 400', str_starts_with(attempt(fn () => CommissionService::markPaid($old, $ADMIN, 'UPI-1')), 'invalid_transition|400|'));
    $p = CommissionService::markPayable($old, $ADMIN);
    $row = cm($old);
    check('markPayable: pending -> payable, payable_at set, returns row', $row['status'] === 'payable' && $row['payable_at'] !== null && $p['status'] === 'payable');
    check('markPayable twice -> invalid_transition', str_starts_with(attempt(fn () => CommissionService::markPayable($old, $ADMIN)), 'invalid_transition|400|'));
    foreach (['', '   ', str_repeat('A', 101), "UPI\n1", '<b>x</b>'] as $bad) {
        check('markPaid rejects reference ' . json_encode(substr($bad, 0, 12)), str_starts_with(attempt(fn () => CommissionService::markPaid($old, $ADMIN, $bad)), 'validation_error|400|{"payoutReference"'));
    }
    check('rejected markPaid leaves it payable', cm($old)['status'] === 'payable' && cm($old)['paid_at'] === null);
    $p = CommissionService::markPaid($old, $ADMIN, '  UPI/4521-889.x  ');
    $row = cm($old);
    check('markPaid: payable -> paid, paid_at + trimmed reference', $row['status'] === 'paid' && $row['paid_at'] !== null && $row['payout_reference'] === 'UPI/4521-889.x' && $p['status'] === 'paid');
    check('markPaid twice -> invalid_transition, reference kept', str_starts_with(attempt(fn () => CommissionService::markPaid($old, $ADMIN, 'OTHER')), 'invalid_transition|400|') && cm($old)['payout_reference'] === 'UPI/4521-889.x');
    check('paid -> payable -> invalid_transition', str_starts_with(attempt(fn () => CommissionService::markPayable($old, $ADMIN)), 'invalid_transition|400|'));
    check('payable + paid audit rows written', (int) val("SELECT COUNT(*) FROM audit_logs WHERE id > ? AND entity_type = 'commission' AND entity_id = ? AND action IN ('commission.payable', 'commission.paid')", [$auditFrom, $old]) === 2);

    q("UPDATE commissions SET status = 'reversed', reversed_at = NOW() WHERE id = ?", [$newD]);
    check('reversed -> payable -> invalid_transition', str_starts_with(attempt(fn () => CommissionService::markPayable($newD, $ADMIN)), 'invalid_transition|400|'));
    check('reversed -> paid -> invalid_transition', str_starts_with(attempt(fn () => CommissionService::markPaid($newD, $ADMIN, 'UPI-2')), 'invalid_transition|400|') && cm($newD)['status'] === 'reversed');
    check('unknown commission -> not_found 404', str_starts_with(attempt(fn () => CommissionService::markPayable(0, $ADMIN)), 'not_found|404|') && str_starts_with(attempt(fn () => CommissionService::markPaid(999999999, $ADMIN, 'X')), 'not_found|404|'));

    $snap = json_encode(q('SELECT * FROM commissions WHERE referral_partner_id = ? ORDER BY id', [$partnerId])->fetchAll());
    q('UPDATE referral_partners SET active = 0 WHERE id = ?', [$partnerId]);
    check('partner deactivation changes no commission row', json_encode(q('SELECT * FROM commissions WHERE referral_partner_id = ? ORDER BY id', [$partnerId])->fetchAll()) === $snap);
    check('earned commission of an inactive partner can still be paid out', attempt(fn () => CommissionService::markPayable($newH, $ADMIN)) === 'ok' && cm($newH)['status'] === 'payable');

    // --- payout held while the order can still be refunded ---
    $openRefund = function (int $commissionId, string $status): int {
        global $db;
        $orderId = (int) cm($commissionId)['order_id'];
        q("INSERT INTO refunds (order_id, payment_id, status, reason, amount) SELECT ?, id, ?, 'test', amount FROM payments WHERE order_id = ?", [$orderId, $status, $orderId]);
        return (int) $db->lastInsertId();
    };
    $held = paidReferralOrder('digital');
    $rf = $openRefund($held, 'requested');
    check('open (requested) refund blocks markPayable -> refund_open 409, row unchanged', str_starts_with(attempt(fn () => CommissionService::markPayable($held, $ADMIN)), 'refund_open|409|') && cm($held)['status'] === 'pending' && cm($held)['payable_at'] === null);
    check('commission row reports refundOpen', CommissionService::find($held)['refundOpen'] === true);
    q("UPDATE refunds SET status = 'rejected' WHERE id = ?", [$rf]);
    check('after the refund is rejected markPayable works; refundOpen false', attempt(fn () => CommissionService::markPayable($held, $ADMIN)) === 'ok' && CommissionService::find($held)['refundOpen'] === false);
    foreach (['approved', 'processing'] as $st) {
        $rf = $openRefund($held, $st);
        check("$st refund blocks markPaid -> refund_open 409", str_starts_with(attempt(fn () => CommissionService::markPaid($held, $ADMIN, 'UPI-9')), 'refund_open|409|') && cm($held)['status'] === 'payable');
        q("UPDATE refunds SET status = 'failed' WHERE id = ?", [$rf]);
    }
    check('after the refund failed markPaid works', attempt(fn () => CommissionService::markPaid($held, $ADMIN, 'UPI-9')) === 'ok' && cm($held)['status'] === 'paid');
    $gone = paidReferralOrder('hardcopy');
    q("UPDATE orders SET status = 'refunded' WHERE id = ?", [cm($gone)['order_id']]);
    check('order no longer paid blocks markPayable -> refund_open', str_starts_with(attempt(fn () => CommissionService::markPayable($gone, $ADMIN)), 'refund_open|409|'));
} catch (\Throwable $e) {
    echo 'FAIL: unexpected ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    foreach ($savedRates as $k => $v) {
        q('UPDATE settings SET `value` = ? WHERE `key` = ?', [$v, $k]);
    }
    q("DELETE FROM audit_logs WHERE id > ? AND entity_type IN ('commission', 'settings')", [$auditFrom]);
    $ids = $userIds ? implode(',', array_map('intval', $userIds)) : '0';
    $orderIds = "SELECT id FROM orders WHERE user_id IN ($ids)";
    $db->exec("DELETE FROM audit_logs WHERE user_id IN ($ids) OR (entity_type = 'access_code' AND entity_id IN (SELECT id FROM access_codes WHERE order_id IN ($orderIds)))");
    $db->exec("DELETE FROM refunds WHERE order_id IN ($orderIds)");
    $db->exec("DELETE FROM commissions WHERE order_id IN ($orderIds)");
    $db->exec("DELETE FROM access_codes WHERE order_id IN ($orderIds)");
    $db->exec("DELETE FROM payments WHERE order_id IN ($orderIds)");
    $db->exec("DELETE FROM orders WHERE user_id IN ($ids)");
    $db->exec("DELETE FROM users WHERE id IN ($ids)");
    if ($partnerId) {
        q('DELETE FROM referral_partners WHERE id = ?', [$partnerId]);
    }
    if ($bookId) {
        q('DELETE FROM books WHERE id = ?', [$bookId]);
    }
}

if ($failures > 0) {
    echo "\n{$failures} test(s) FAILED.\n";
    exit(1);
}
echo "\nAll commission tests passed.\n";
