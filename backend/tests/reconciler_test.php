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
use App\Orders\OrderReconciler;
use App\Razorpay\Gateway;
use App\Support\Money;

$db = Db::connection();
$tag = 'rec' . getmypid() . time();
$failures = 0;
$userIds = [];
$bookId = 0;

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

/** @return array{id:int,rzp:string,uid:int} */
function mkOrder(int $ageMinutes, string $status = 'pending', string $type = 'digital'): array
{
    global $db, $bookId, $tag, $userIds;
    static $i = 0;
    $i++;
    q('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)', ["REC $i", "$tag-$i@example.com", 'x']);
    $uid = $userIds[] = (int) $db->lastInsertId();
    q(
        "INSERT INTO orders (order_number, user_id, book_id, purchase_type, amount, status, fulfilment_status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 'not_applicable', NOW() - INTERVAL ? MINUTE)",
        ["$tag-$i", $uid, $bookId, $type, Money::fromPaise(49900), $status, $ageMinutes]
    );
    $id = (int) $db->lastInsertId();
    q("INSERT INTO payments (order_id, razorpay_order_id, amount, status) VALUES (?, ?, ?, 'created')", [$id, "order_$tag$i", Money::fromPaise(49900)]);
    return ['id' => $id, 'rzp' => "order_$tag$i", 'uid' => $uid];
}

function status(int $id): string
{
    return (string) val('SELECT status FROM orders WHERE id = ?', [$id]);
}

function captured(string $rzp, string $pid): array
{
    return ['id' => $pid, 'order_id' => $rzp, 'amount' => 49900, 'currency' => 'INR', 'status' => 'captured'];
}

try {
    q('INSERT INTO books (title, online_price, hardcopy_price, published) VALUES (?, 499, 499, 0)', ["REC throwaway $tag"]);
    $bookId = (int) $db->lastInsertId();

    $fresh = mkOrder(20);          // captured => paid
    $stale = mkOrder(25 * 60);     // nothing => cancelled
    $young = mkOrder(5);           // too young => untouched
    $lateCap = mkOrder(25 * 60, 'pending', 'hardcopy'); // old but captured => paid, not cancelled
    $paid = mkOrder(25 * 60, 'paid');  // paid => untouched
    $notYet = mkOrder(20);         // no capture, not old enough to expire => stays pending
    $boom = mkOrder(25 * 60);      // gateway throws => untouched, error counted
    $race = mkOrder(25 * 60);      // webhook lands mid-run => stays paid
    $held = mkOrder(25 * 60);      // authorized (not captured) => not expired yet

    // Second pass: failed/cancelled orders from the last 7 days whose money arrived anyway.
    $failBoom = mkOrder(60, 'failed');               // gateway throws => untouched, error counted, others still run
    $failRetry = mkOrder(60, 'failed');              // first attempt failed, retry captured, webhook lost => paid
    $cancCap = mkOrder(3 * 24 * 60, 'cancelled');    // captured => paid
    $failNone = mkOrder(60, 'failed');               // nothing captured => untouched
    $cancNone = mkOrder(2 * 24 * 60, 'cancelled');   // authorized only => untouched (never expired/modified)
    $failOld = mkOrder(8 * 24 * 60, 'failed');       // older than 7 days => not queried

    // Every other eligible order in the DB is foreign: the stub throws for it, so it is never touched.
    $ours = [$fresh, $stale, $young, $lateCap, $paid, $notYet, $boom, $race, $held, $failBoom, $failRetry, $cancCap, $failNone, $cancNone, $failOld];
    $ourIds = implode(',', array_map(fn ($o) => $o['id'], $ours));
    $foreign = (int) val("SELECT COUNT(*) FROM orders o JOIN payments p ON p.order_id = o.id
         WHERE o.status = 'pending' AND o.created_at <= NOW() - INTERVAL 15 MINUTE AND p.razorpay_order_id IS NOT NULL AND o.id NOT IN ($ourIds)");
    $foreign += (int) val("SELECT COUNT(*) FROM orders o JOIN payments p ON p.order_id = o.id
         WHERE o.status IN ('failed', 'cancelled') AND o.created_at >= NOW() - INTERVAL 7 DAY AND p.razorpay_order_id IS NOT NULL AND o.id NOT IN ($ourIds)");

    $gateway = new class implements Gateway {
        public array $map = [];
        public array $calls = [];
        public function createOrder(int $amountPaise, string $receipt, array $notes = []): array
        {
            throw new \LogicException('unused');
        }
        public function fetchPayment(string $paymentId): array
        {
            throw new \LogicException('unused');
        }
        public function refund(string $razorpayPaymentId, int $amountPaise, string $receipt): array
        {
            throw new \LogicException('unused');
        }
        public function fetchOrderPayments(string $razorpayOrderId): array
        {
            $this->calls[] = $razorpayOrderId;
            $r = $this->map[$razorpayOrderId] ?? null;
            if ($r === null) {
                throw new \RuntimeException('stub: unknown order');
            }
            return is_callable($r) ? $r() : $r;
        }
    };
    $gateway->map = [
        $fresh['rzp'] => [['id' => "pay_{$tag}f0", 'order_id' => $fresh['rzp'], 'amount' => 49900, 'status' => 'failed'], captured($fresh['rzp'], "pay_{$tag}f1")],
        $stale['rzp'] => [],
        $young['rzp'] => [captured($young['rzp'], "pay_{$tag}y")],
        $lateCap['rzp'] => [captured($lateCap['rzp'], "pay_{$tag}l")],
        $paid['rzp'] => [],
        $notYet['rzp'] => [],
        $held['rzp'] => [['id' => "pay_{$tag}h", 'order_id' => $held['rzp'], 'amount' => 49900, 'status' => 'authorized']],
        $race['rzp'] => function () use ($race, $tag) {
            OrderFulfillment::markPaid($race['rzp'], "pay_{$tag}r", 49900); // the webhook wins between select and expire
            return [];
        },
        $failRetry['rzp'] => [['id' => "pay_{$tag}fr0", 'order_id' => $failRetry['rzp'], 'amount' => 49900, 'status' => 'failed'], captured($failRetry['rzp'], "pay_{$tag}fr1")],
        $cancCap['rzp'] => [captured($cancCap['rzp'], "pay_{$tag}cc")],
        $failNone['rzp'] => [['id' => "pay_{$tag}fn", 'order_id' => $failNone['rzp'], 'amount' => 49900, 'status' => 'failed']],
        $cancNone['rzp'] => [['id' => "pay_{$tag}cn", 'order_id' => $cancNone['rzp'], 'amount' => 49900, 'status' => 'authorized']],
        $failOld['rzp'] => [captured($failOld['rzp'], "pay_{$tag}fo")],
    ];

    $res = OrderReconciler::run($gateway);

    check('result keys paid/cancelled/errors', isset($res['paid'], $res['cancelled'], $res['errors']), json_encode($res));
    check('paid count 4 (2 pending + 2 failed/cancelled)', ($res['paid'] ?? null) === 4, json_encode($res));
    check('cancelled count 1', ($res['cancelled'] ?? null) === 1, json_encode($res));
    check('errors = 2 thrown + foreign', ($res['errors'] ?? null) === 2 + $foreign, json_encode($res) . " foreign=$foreign");

    check('failed order, retry captured => paid + code issued', status($failRetry['id']) === 'paid'
        && val('SELECT razorpay_payment_id FROM payments WHERE order_id = ?', [$failRetry['id']]) === "pay_{$tag}fr1"
        && (int) val("SELECT COUNT(*) FROM access_codes WHERE order_id = ? AND status = 'active'", [$failRetry['id']]) === 1);
    check('cancelled order, captured => paid', status($cancCap['id']) === 'paid'
        && val('SELECT status FROM payments WHERE order_id = ?', [$cancCap['id']]) === 'captured');
    check('failed order, no capture => untouched', status($failNone['id']) === 'failed'
        && val('SELECT status FROM payments WHERE order_id = ?', [$failNone['id']]) === 'created'
        && in_array($failNone['rzp'], $gateway->calls, true));
    check('cancelled order, authorized only => untouched', status($cancNone['id']) === 'cancelled'
        && val('SELECT status FROM payments WHERE order_id = ?', [$cancNone['id']]) === 'created'
        && in_array($cancNone['rzp'], $gateway->calls, true));
    check('failed order older than 7 days => not queried, untouched', status($failOld['id']) === 'failed' && !in_array($failOld['rzp'], $gateway->calls, true));
    check('gateway throws on failed order => untouched, later ones still processed', status($failBoom['id']) === 'failed'
        && in_array($failBoom['rzp'], $gateway->calls, true)
        && array_search($failBoom['rzp'], $gateway->calls, true) < array_search($failRetry['rzp'], $gateway->calls, true));
    check('order cancelled this run is not re-queried', count(array_keys($gateway->calls, $stale['rzp'], true)) === 1);

    check('20-min captured => paid', status($fresh['id']) === 'paid');
    check('20-min captured => access code issued', (int) val("SELECT COUNT(*) FROM access_codes WHERE order_id = ? AND status = 'active'", [$fresh['id']]) === 1);
    check('captured payment id recorded', val('SELECT razorpay_payment_id FROM payments WHERE order_id = ?', [$fresh['id']]) === "pay_{$tag}f1");

    $sp = q('SELECT status, failure_reason FROM payments WHERE order_id = ?', [$stale['id']])->fetch();
    check('25-h no payments => cancelled, payment failed/expired', status($stale['id']) === 'cancelled' && $sp['status'] === 'failed' && $sp['failure_reason'] === 'expired');

    check('5-min order untouched and not queried', status($young['id']) === 'pending' && !in_array($young['rzp'], $gateway->calls, true));
    check('25-h captured => paid (not cancelled)', status($lateCap['id']) === 'paid' && val('SELECT razorpay_payment_id FROM payments WHERE order_id = ?', [$lateCap['id']]) === "pay_{$tag}l");
    check('paid order untouched and not queried', status($paid['id']) === 'paid' && !in_array($paid['rzp'], $gateway->calls, true));
    check('20-min no capture => still pending', status($notYet['id']) === 'pending' && val('SELECT status FROM payments WHERE order_id = ?', [$notYet['id']]) === 'created');
    check('25-h authorized-only => still pending (may yet capture)', status($held['id']) === 'pending' && val('SELECT status FROM payments WHERE order_id = ?', [$held['id']]) === 'created');
    check('gateway throws => order untouched', status($boom['id']) === 'pending' && in_array($boom['rzp'], $gateway->calls, true));
    check('webhook mid-run wins: stays paid, payment captured', status($race['id']) === 'paid' && val('SELECT status FROM payments WHERE order_id = ?', [$race['id']]) === 'captured');

    // Money arrived after the sweep cancelled the order: the single paid path still fulfils it.
    check('late capture on cancelled order => paid', OrderFulfillment::markPaid($stale['rzp'], "pay_{$tag}s", 49900) === 'paid' && status($stale['id']) === 'paid'
        && val('SELECT status FROM payments WHERE order_id = ?', [$stale['id']]) === 'captured');

    // rate_limits purge
    q('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1), (?, ?, 1)', [
        "$tag-old", date('Y-m-d H:i:s', time() - 3 * 86400),
        "$tag-new", date('Y-m-d H:i:s', time() - 3600),
    ]);
    $purged = OrderReconciler::purgeRateLimits();
    check('purge returns >= 1', $purged >= 1, (string) $purged);
    check('old rate_limits row purged, recent kept',
        (int) val('SELECT COUNT(*) FROM rate_limits WHERE bucket = ?', ["$tag-old"]) === 0
        && (int) val('SELECT COUNT(*) FROM rate_limits WHERE bucket = ?', ["$tag-new"]) === 1);
} catch (\Throwable $e) {
    echo 'FAIL: unexpected ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    q('DELETE FROM rate_limits WHERE bucket LIKE ?', ["$tag-%"]);
    $ids = $userIds ? implode(',', array_map('intval', $userIds)) : '0';
    $orderIds = "SELECT id FROM orders WHERE user_id IN ($ids)";
    $db->exec("DELETE FROM commissions WHERE order_id IN ($orderIds)");
    $db->exec("DELETE FROM access_codes WHERE order_id IN ($orderIds)");
    $db->exec("DELETE FROM book_access WHERE user_id IN ($ids)");
    $db->exec("DELETE FROM payments WHERE order_id IN ($orderIds)");
    $db->exec("DELETE FROM orders WHERE user_id IN ($ids)");
    $db->exec("DELETE FROM users WHERE id IN ($ids)");
    if ($bookId) {
        q('DELETE FROM books WHERE id = ?', [$bookId]);
    }
}

if ($failures > 0) {
    echo "\n{$failures} test(s) FAILED.\n";
    exit(1);
}
echo "\nAll reconciler tests passed.\n";
