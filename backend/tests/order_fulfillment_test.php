<?php

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

use App\Database\Db;
use App\Orders\OrderFulfillment;
use App\Support\Money;

$db = Db::connection();
$tag = 'ofl' . getmypid() . time();
$failures = 0;
$userIds = [];
$bookId = 0;
$partnerId = 0;
$origHardRate = null;

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

function mkUser(): int
{
    global $userIds, $tag, $db;
    $n = count($userIds);
    q('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)', ["OFL $n", "$tag-$n@example.com", 'x']);
    return $userIds[] = (int) $db->lastInsertId();
}

/** @return array{id:int,rzp:string,uid:int} */
function mkOrder(string $type, ?int $partner, string $status = 'pending', int $paise = 49900): array
{
    global $db, $bookId, $tag;
    static $i = 0;
    $i++;
    $uid = mkUser();
    q(
        "INSERT INTO orders (order_number, user_id, book_id, purchase_type, amount, referral_partner_id, status, fulfilment_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'not_applicable')",
        ["$tag-$i", $uid, $bookId, $type, Money::fromPaise($paise), $partner, $status]
    );
    $id = (int) $db->lastInsertId();
    q("INSERT INTO payments (order_id, razorpay_order_id, amount, status) VALUES (?, ?, ?, 'created')", [$id, "order_$tag$i", Money::fromPaise($paise)]);
    return ['id' => $id, 'rzp' => "order_$tag$i", 'uid' => $uid];
}

function status(int $id): string
{
    return (string) val('SELECT status FROM orders WHERE id = ?', [$id]);
}

function accessCount(int $uid): int
{
    global $bookId;
    return (int) val('SELECT COUNT(*) FROM book_access WHERE user_id = ? AND book_id = ?', [$uid, $bookId]);
}

function commissions(int $id): int
{
    return (int) val('SELECT COUNT(*) FROM commissions WHERE order_id = ?', [$id]);
}

try {
    q('INSERT INTO books (title, online_price, hardcopy_price, published) VALUES (?, 100, 200, 0)', ["OFL throwaway $tag"]);
    $bookId = (int) $db->lastInsertId();
    q('INSERT INTO referral_partners (name, phone, whatsapp, gmail, referral_code) VALUES (?, ?, ?, ?, ?)', ['OFL P', '9999999999', '9999999999', "$tag-p@example.com", strtoupper(substr($tag, 0, 30))]);
    $partnerId = (int) $db->lastInsertId();
    $origHardRate = (string) val("SELECT `value` FROM settings WHERE `key` = 'commission_rate_hardcopy'");

    // digital paid + idempotence + commission
    $o = mkOrder('digital', $partnerId);
    check('digital markPaid returns paid', OrderFulfillment::markPaid($o['rzp'], 'pay_' . $tag . 'a', 49900, ['id' => 'pay_x']) === 'paid');
    check('order status paid', status($o['id']) === 'paid');
    check('paid_at set', val('SELECT paid_at FROM orders WHERE id = ?', [$o['id']]) !== null);
    check('digital: NO book_access row (granted by redemption)', accessCount($o['uid']) === 0);
    $pay = q('SELECT status, razorpay_payment_id, raw_response FROM payments WHERE order_id = ?', [$o['id']])->fetch();
    check('payment captured with id and raw_response', $pay['status'] === 'captured' && $pay['razorpay_payment_id'] === 'pay_' . $tag . 'a' && json_decode($pay['raw_response'], true) === ['id' => 'pay_x']);
    $c = q('SELECT amount, status, purchase_type FROM commissions WHERE order_id = ?', [$o['id']])->fetch();
    check('referral commission 25.00 pending', $c && $c['amount'] === '25.00' && $c['status'] === 'pending' && $c['purchase_type'] === 'digital');
    check('second markPaid returns already_paid', OrderFulfillment::markPaid($o['rzp'], 'pay_' . $tag . 'a', 49900) === 'already_paid');
    check('still exactly one commission', commissions($o['id']) === 1);

    // no partner
    $o2 = mkOrder('digital', null);
    OrderFulfillment::markPaid($o2['rzp'], 'pay_' . $tag . 'b', 49900);
    check('no partner => zero commissions', status($o2['id']) === 'paid' && commissions($o2['id']) === 0);

    // hardcopy
    $h = mkOrder('hardcopy', $partnerId);
    OrderFulfillment::markPaid($h['rzp'], 'pay_' . $tag . 'c', 49900);
    check('hardcopy => fulfilment confirmed', val('SELECT fulfilment_status FROM orders WHERE id = ?', [$h['id']]) === 'confirmed');
    check('hardcopy => no book_access', accessCount($h['uid']) === 0);
    check('hardcopy => tracking_code not generated here', val('SELECT tracking_code FROM orders WHERE id = ?', [$h['id']]) === null);

    // rate-change snapshot
    q("UPDATE settings SET `value` = '40' WHERE `key` = 'commission_rate_hardcopy'");
    $h2 = mkOrder('hardcopy', $partnerId);
    OrderFulfillment::markPaid($h2['rzp'], 'pay_' . $tag . 'd', 49900);
    q("UPDATE settings SET `value` = ? WHERE `key` = 'commission_rate_hardcopy'", [$origHardRate]);
    check('new order snapshots 40.00', val('SELECT amount FROM commissions WHERE order_id = ?', [$h2['id']]) === '40.00');
    check('older order stays 25.00', val('SELECT amount FROM commissions WHERE order_id = ?', [$h['id']]) === '25.00');

    // amount mismatch
    $m = mkOrder('digital', $partnerId);
    check('mismatch returns amount_mismatch', OrderFulfillment::markPaid($m['rzp'], 'pay_' . $tag . 'e', 100) === 'amount_mismatch');
    check(
        'mismatch: order pending, flagged, no commission, payment untouched',
        status($m['id']) === 'pending'
        && val('SELECT admin_flag FROM orders WHERE id = ?', [$m['id']]) === 'amount_mismatch'
        && commissions($m['id']) === 0
        && val('SELECT status FROM payments WHERE order_id = ?', [$m['id']]) === 'created'
        && accessCount($m['uid']) === 0
    );

    // failed then late capture
    $f = mkOrder('digital', $partnerId);
    check('markFailed returns failed', OrderFulfillment::markFailed($f['rzp'], 'pay_' . $tag . 'f', str_repeat('r', 300)) === 'failed');
    $pf = q('SELECT status, failure_reason FROM payments WHERE order_id = ?', [$f['id']])->fetch();
    check('failed: order failed, payment failed, reason truncated to 255', status($f['id']) === 'failed' && $pf['status'] === 'failed' && strlen($pf['failure_reason']) === 255);
    check('late capture after failed => paid', OrderFulfillment::markPaid($f['rzp'], 'pay_' . $tag . 'g', 49900) === 'paid' && status($f['id']) === 'paid' && commissions($f['id']) === 1);

    // paid then failed
    check(
        'markFailed on paid => ignored, still paid',
        OrderFulfillment::markFailed($o['rzp'], 'pay_' . $tag . 'h', 'late') === 'ignored'
        && status($o['id']) === 'paid'
        && val('SELECT status FROM payments WHERE order_id = ?', [$o['id']]) === 'captured'
    );

    // cancelled then paid
    $x = mkOrder('digital', null, 'cancelled');
    check('cancelled then markPaid => paid', OrderFulfillment::markPaid($x['rzp'], 'pay_' . $tag . 'i', 49900) === 'paid' && status($x['id']) === 'paid');

    // unknown
    check('markPaid unknown order', OrderFulfillment::markPaid('order_nope' . $tag, 'pay_z', 100) === 'unknown_order');
    check('markFailed unknown order', OrderFulfillment::markFailed('order_nope' . $tag, 'pay_z', 'r') === 'unknown_order');

    // concurrency: two processes, same order
    $r = mkOrder('hardcopy', $partnerId);
    $procs = [];
    for ($k = 0; $k < 2; $k++) {
        $pipes = [];
        $p = proc_open([PHP_BINARY, __DIR__ . '/helpers/mark_paid.php', $r['rzp'], 'pay_' . $tag . 'r', '49900'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    $outs = [];
    foreach ($procs as [$p, $pipes]) {
        $outs[] = trim(stream_get_contents($pipes[1])) . trim(stream_get_contents($pipes[2]));
        proc_close($p);
    }
    sort($outs);
    check('concurrent: one paid, one already_paid', $outs === ['already_paid', 'paid'], implode(',', $outs));
    check('concurrent: exactly one commission and status paid', commissions($r['id']) === 1 && status($r['id']) === 'paid');
} catch (\Throwable $e) {
    echo 'FAIL: unexpected ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    if ($origHardRate !== null) {
        q("UPDATE settings SET `value` = ? WHERE `key` = 'commission_rate_hardcopy'", [$origHardRate]);
    }
    $ids = $userIds ? implode(',', array_map('intval', $userIds)) : '0';
    $orderIds = "SELECT id FROM orders WHERE user_id IN ($ids)";
    $db->exec("DELETE FROM commissions WHERE order_id IN ($orderIds)");
    $db->exec("DELETE FROM book_access WHERE user_id IN ($ids)");
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
echo "\nAll order fulfilment tests passed.\n";
