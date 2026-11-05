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

use App\Codes\AccessCodeService;
use App\Database\Db;
use App\Http\ApiException;
use App\Orders\OrderFulfillment;
use App\Razorpay\FakeGateway;
use App\Razorpay\Gateway;
use App\Refunds\RefundService;
use App\Support\Money;

$db = Db::connection();
$tag = 'rfd' . getmypid() . time();
$failures = 0;
$userIds = [];
$bookId = 0;
$partnerId = 0;
$ADMIN = 1; // audit_logs.admin_id / refunds.reviewed_by_admin_id carry no FK

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
    q('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)', ["RFD $n", "$tag-$n@example.com", 'x']);
    return $userIds[] = (int) $db->lastInsertId();
}

/** Order paid through the real markPaid path (49.90 to exercise paise). @return array{id:int,uid:int,rzp:string,pay:string} */
function paidOrder(string $type = 'digital', bool $referral = false, bool $pay = true): array
{
    global $db, $bookId, $tag, $partnerId;
    static $i = 0;
    $i++;
    $uid = mkUser();
    q(
        "INSERT INTO orders (order_number, user_id, book_id, purchase_type, amount, status, fulfilment_status, referral_partner_id) VALUES (?, ?, ?, ?, ?, 'pending', 'not_applicable', ?)",
        ["$tag-$i", $uid, $bookId, $type, Money::fromPaise(4990), $referral ? $partnerId : null]
    );
    $id = (int) $db->lastInsertId();
    q("INSERT INTO payments (order_id, razorpay_order_id, amount, status) VALUES (?, ?, ?, 'created')", [$id, "order_$tag$i", Money::fromPaise(4990)]);
    if ($pay) {
        OrderFulfillment::markPaid("order_$tag$i", "pay_$tag$i", 4990);
    }
    return ['id' => $id, 'uid' => $uid, 'rzp' => "order_$tag$i", 'pay' => "pay_$tag$i"];
}

class StubGateway implements Gateway
{
    public array $calls = [];
    /** 'processed' | 'pending' | 'failed' | 'throw' */
    public string $next = 'processed';

    public function createOrder(int $amountPaise, string $receipt, array $notes = []): array
    {
        throw new \LogicException('unused');
    }

    public function fetchPayment(string $paymentId): array
    {
        throw new \LogicException('unused');
    }

    public function fetchOrderPayments(string $razorpayOrderId): array
    {
        throw new \LogicException('unused');
    }

    public function fetchRefund(string $refundId): array
    {
        throw new \LogicException('unused');
    }

    public function listRefundsForPayment(string $paymentId): array
    {
        throw new \LogicException('unused');
    }

    public function refund(string $razorpayPaymentId, int $amountPaise, string $receipt): array
    {
        $this->calls[] = [$razorpayPaymentId, $amountPaise, $receipt];
        if ($this->next === 'throw') {
            throw new ApiException('Payment gateway error', 502, 'gateway_error');
        }
        return ['id' => 'rfnd_stub_' . bin2hex(random_bytes(5)), 'status' => $this->next];
    }
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

/** A legacy customer-requested refund row (the customer request endpoint was removed; old rows may still exist). @return array{refund:array,created:bool} */
function seedRequest(int $uid, int $orderId, string $reason): array
{
    global $db;
    $open = q('SELECT * FROM refunds WHERE order_id = ? AND is_open = 1', [$orderId])->fetch();
    if ($open) {
        return ['refund' => $open, 'created' => false];
    }
    q("INSERT INTO refunds (order_id, payment_id, requested_by_user_id, status, reason, amount) SELECT ?, id, ?, 'requested', ?, amount FROM payments WHERE order_id = ? AND status = 'captured'", [$orderId, $uid, $reason, $orderId]);
    return ['refund' => refundRow((int) $db->lastInsertId()), 'created' => true];
}

function refundRow(int $id): array
{
    return q('SELECT * FROM refunds WHERE id = ?', [$id])->fetch();
}

function access(int $uid): ?array
{
    global $bookId;
    return q('SELECT order_id, revoked_at FROM book_access WHERE user_id = ? AND book_id = ?', [$uid, $bookId])->fetch() ?: null;
}

/** Everything finalise may touch, for an "unchanged" comparison. */
function snapshot(array $o, int $refundId): string
{
    return json_encode([
        q('SELECT * FROM refunds WHERE order_id = ?', [$o['id']])->fetchAll(),
        q('SELECT * FROM orders WHERE id = ?', [$o['id']])->fetchAll(),
        q('SELECT * FROM payments WHERE order_id = ?', [$o['id']])->fetchAll(),
        q('SELECT id, status, revoked_at, revoked_reason, code_enc IS NULL AS enc_null FROM access_codes WHERE order_id = ?', [$o['id']])->fetchAll(),
        q('SELECT * FROM book_access WHERE order_id = ?', [$o['id']])->fetchAll(),
        q('SELECT * FROM commissions WHERE order_id = ?', [$o['id']])->fetchAll(),
        q("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'refund' AND entity_id = ?", [$refundId])->fetchAll(),
    ], JSON_INVALID_UTF8_SUBSTITUTE);
}

try {
    q('INSERT INTO books (title, online_price, hardcopy_price, published) VALUES (?, 100, 200, 1)', ["RFD throwaway $tag"]);
    $bookId = (int) $db->lastInsertId();
    q("INSERT INTO referral_partners (name, phone, whatsapp, gmail, referral_code, active) VALUES ('RFD partner', '9876543210', '9876543210', ?, ?, 1)", ["$tag@example.com", substr("RT$tag", 0, 30)]);
    $partnerId = (int) $db->lastInsertId();
    $gw = new StubGateway();
    $svc = new RefundService($gw);

    // --- Fake gateway ---
    $fr = (new FakeGateway())->refund('pay_x', 100, 'refund_1');
    check('FakeGateway::refund -> rfnd_fake_<hex>, processed', preg_match('/^rfnd_fake_[0-9a-f]+$/', $fr['id']) === 1 && $fr['status'] === 'processed');

    // --- customer refund requests are gone: only legacy `requested` rows (seeded directly) can exist ---
    $d = paidOrder();
    $r1 = seedRequest($d['uid'], $d['id'], 'changed my mind');
    $row = refundRow((int) $r1['refund']['id']);
    check('seeded legacy request: status requested, requested_by set', $row['status'] === 'requested' && (int) $row['requested_by_user_id'] === $d['uid'] && $row['reviewed_by_admin_id'] === null);
    check('seeded amount is the captured payment amount (49.90), payment_id linked', $row['amount'] === '49.90' && (int) $row['payment_id'] === (int) val('SELECT id FROM payments WHERE order_id = ?', [$d['id']]));
    check('RefundService has no customer request() method', !method_exists(RefundService::class, 'request'));

    // --- two processes racing past the "existing open refund" check ---
    // Parent holds an uncommitted conflicting row so both children reach INSERT and queue on the unique key.
    $race = paidOrder();
    $db->beginTransaction();
    q("INSERT INTO refunds (order_id, payment_id, status, reason, amount) VALUES (?, (SELECT id FROM payments WHERE order_id = ?), 'requested', 'blocker', 1)", [$race['id'], $race['id']]);
    $procs = [];
    for ($k = 0; $k < 2; $k++) {
        $pipes = [];
        $procs[] = [proc_open([PHP_BINARY, __DIR__ . '/helpers/refund_request.php', (string) $race['uid'], (string) $race['id']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
    }
    usleep(500000);
    $db->rollBack();
    $outs = [];
    foreach ($procs as [$p, $pipes]) {
        $outs[] = trim(stream_get_contents($pipes[1])) . trim(stream_get_contents($pipes[2]));
        proc_close($p);
    }
    sort($outs);
    $raceId = (int) val('SELECT id FROM refunds WHERE order_id = ?', [$race['id']]);
    check('concurrent admin opens: one created, one existing, same id, exactly one row', $outs === ["created:$raceId", "existing:$raceId"] && (int) val('SELECT COUNT(*) FROM refunds WHERE order_id = ?', [$race['id']]) === 1, implode(',', $outs));

    // --- reject ---
    $rej = paidOrder();
    $rr = seedRequest($rej['uid'], $rej['id'], 'not needed');
    $rid = (int) $rr['refund']['id'];
    check('reject without note -> validation_error fields.note', str_starts_with(attempt(fn () => $svc->reject($rid, $ADMIN, '   ')), 'validation_error|400|{"note"'));
    check('reject with 501-char note -> validation_error', str_starts_with(attempt(fn () => $svc->reject($rid, $ADMIN, str_repeat('n', 501))), 'validation_error|400|{"note"'));
    $out = $svc->reject($rid, $ADMIN, ' outside policy ');
    $row = refundRow($rid);
    check('reject -> rejected, note trimmed, reviewer set, order still paid', $out['status'] === 'rejected' && $row['admin_note'] === 'outside policy' && (int) $row['reviewed_by_admin_id'] === $ADMIN && val('SELECT status FROM orders WHERE id = ?', [$rej['id']]) === 'paid');
    check('reject writes audit row with admin_id', (int) val("SELECT COUNT(*) FROM audit_logs WHERE action = 'refund.reject' AND entity_type = 'refund' AND entity_id = ? AND admin_id = ?", [$rid, $ADMIN]) === 1);
    check('approve a rejected refund -> invalid_transition 409', str_starts_with(attempt(fn () => $svc->approve($rid, $ADMIN, null)), 'invalid_transition|409|'));
    $again = seedRequest($rej['uid'], $rej['id'], 'second try');
    check('after rejection a new row may be opened', $again['created'] === true && (int) $again['refund']['id'] !== $rid);
    check('no gateway call for request/reject', count($gw->calls) === 0);

    // --- approve digital, redeemed code ---
    $code = AccessCodeService::revealForOwner($d['uid'], $d['id']);
    AccessCodeService::redeem($d['uid'], (string) $code);
    check('fixture: redeemed, active access', access($d['uid'])['revoked_at'] === null);
    $refId = (int) $r1['refund']['id'];
    $out = $svc->approve($refId, $ADMIN, 'ok by admin');
    $row = refundRow($refId);
    check('approve digital -> refunded, razorpay_refund_id stored, reviewer set', $out['status'] === 'refunded' && $row['status'] === 'refunded' && str_starts_with((string) $row['razorpay_refund_id'], 'rfnd_stub_') && (int) $row['reviewed_by_admin_id'] === $ADMIN);
    check('gateway called once with payment id, amount in paise, receipt refund_<id>', $gw->calls === [[$d['pay'], 4990, "refund_$refId"]], json_encode($gw->calls));
    check('order and payment -> refunded', val('SELECT status FROM orders WHERE id = ?', [$d['id']]) === 'refunded' && val('SELECT status FROM payments WHERE order_id = ?', [$d['id']]) === 'refunded');
    $c = q('SELECT status, revoked_reason, code_enc FROM access_codes WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$d['id']])->fetch();
    check("order's code -> revoked, reason refund, code_enc NULL", $c['status'] === 'revoked' && $c['revoked_reason'] === 'refund' && $c['code_enc'] === null);
    check("redeemer's book_access.revoked_at set", access($d['uid'])['revoked_at'] !== null);
    check('redeem attempt after refund -> code_revoked', str_starts_with(attempt(fn () => AccessCodeService::redeem($d['uid'], (string) $code)), 'code_revoked|403|'));
    check('customer reissue on refunded order refused (not_found)', str_starts_with(attempt(fn () => AccessCodeService::reissue($d['id'], null, $d['uid'], 'customer_request')), 'not_found|404|'));
    check('reveal on refunded order -> null', AccessCodeService::revealForOwner($d['uid'], $d['id']) === null);
    check('audit: refund.approve + refund.refunded rows with admin_id', (int) val("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'refund' AND entity_id = ? AND admin_id = ? AND action IN ('refund.approve', 'refund.refunded')", [$refId, $ADMIN]) === 2);

    // --- refunded order is never re-fulfilled ---
    check('markPaid on a refunded order -> already_paid, stays refunded, no new code', OrderFulfillment::markPaid($d['rzp'], $d['pay'], 4990) === 'already_paid' && val('SELECT status FROM orders WHERE id = ?', [$d['id']]) === 'refunded' && (int) val("SELECT COUNT(*) FROM access_codes WHERE order_id = ? AND status <> 'revoked'", [$d['id']]) === 0);

    // --- finalise twice changes nothing; approve again makes no second gateway call ---
    $before = snapshot($d, $refId);
    $svc->finalise($refId);
    $svc->finalise($refId);
    $out = $svc->approve($refId, $ADMIN, null);
    check('finalise twice + approve again: nothing changes, no second gateway call', snapshot($d, $refId) === $before && $out['status'] === 'refunded' && count($gw->calls) === 1);

    // --- digital, unredeemed code ---
    $u = paidOrder();
    $uid = (int) seedRequest($u['uid'], $u['id'], 'never used')['refund']['id'];
    $svc->approve($uid, $ADMIN, null);
    $c = q('SELECT status, code_enc FROM access_codes WHERE order_id = ?', [$u['id']])->fetch();
    check('unredeemed code -> revoked, code_enc NULL, no book_access', $c['status'] === 'revoked' && $c['code_enc'] === null && access($u['uid']) === null);

    // --- hard copy ---
    $h = paidOrder('hardcopy');
    $hid = (int) seedRequest($h['uid'], $h['id'], 'wrong book')['refund']['id'];
    $svc->approve($hid, $ADMIN, null);
    check('hardcopy before shipping -> fulfilment cancelled, order refunded', val("SELECT CONCAT(status, '|', fulfilment_status) FROM orders WHERE id = ?", [$h['id']]) === 'refunded|cancelled');
    $hs = paidOrder('hardcopy');
    q("UPDATE orders SET fulfilment_status = 'shipped', courier = 'X', tracking_number = 'T1', shipped_at = NOW() WHERE id = ?", [$hs['id']]);
    $hsid = (int) seedRequest($hs['uid'], $hs['id'], 'arrived damaged')['refund']['id'];
    $svc->approve($hsid, $ADMIN, null);
    check('hardcopy after shipped -> refund processed, fulfilment stays shipped', val("SELECT CONCAT(status, '|', fulfilment_status) FROM orders WHERE id = ?", [$hs['id']]) === 'refunded|shipped' && refundRow($hsid)['status'] === 'refunded');
    check('audit records the item had already shipped', (int) val("SELECT COUNT(*) FROM audit_logs WHERE action = 'refund.already_shipped' AND entity_type = 'refund' AND entity_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(detail, '$.fulfilmentStatus')) = 'shipped'", [$hsid]) === 1);

    // --- commissions ---
    $cp = paidOrder('digital', true);
    check('fixture: referral order has a pending commission', val('SELECT status FROM commissions WHERE order_id = ?', [$cp['id']]) === 'pending');
    $svc->approve((int) seedRequest($cp['uid'], $cp['id'], 'x')['refund']['id'], $ADMIN, null);
    $cm = q('SELECT status, reversed_at, clawback_due FROM commissions WHERE order_id = ?', [$cp['id']])->fetch();
    check('commission pending -> reversed with reversed_at, no clawback', $cm['status'] === 'reversed' && $cm['reversed_at'] !== null && (int) $cm['clawback_due'] === 0);
    $cy = paidOrder('digital', true);
    q("UPDATE commissions SET status = 'payable' WHERE order_id = ?", [$cy['id']]);
    $svc->approve((int) seedRequest($cy['uid'], $cy['id'], 'x')['refund']['id'], $ADMIN, null);
    check('commission payable -> reversed', val('SELECT status FROM commissions WHERE order_id = ?', [$cy['id']]) === 'reversed');
    $cpd = paidOrder('hardcopy', true);
    q("UPDATE commissions SET status = 'paid', paid_at = NOW(), payout_reference = 'UPI123' WHERE order_id = ?", [$cpd['id']]);
    $svc->approve((int) seedRequest($cpd['uid'], $cpd['id'], 'x')['refund']['id'], $ADMIN, null);
    $cm = q('SELECT status, reversed_at, clawback_due, payout_reference FROM commissions WHERE order_id = ?', [$cpd['id']])->fetch();
    check('commission already paid -> stays paid, clawback_due=1, not reversed', $cm['status'] === 'paid' && (int) $cm['clawback_due'] === 1 && $cm['reversed_at'] === null && $cm['payout_reference'] === 'UPI123');

    // --- gateway failure, then retry ---
    $f = paidOrder('digital', true);
    $fid = (int) seedRequest($f['uid'], $f['id'], 'x')['refund']['id'];
    $gw->next = 'throw';
    $out = $svc->approve($fid, $ADMIN, null);
    $row = refundRow($fid);
    check('gateway error -> failed with a note, order still paid, commission untouched', $out['status'] === 'failed' && $row['status'] === 'failed' && $row['admin_note'] !== null && $row['razorpay_refund_id'] === null && val('SELECT status FROM orders WHERE id = ?', [$f['id']]) === 'paid' && val('SELECT status FROM commissions WHERE order_id = ?', [$f['id']]) === 'pending');
    check('failed refund keeps code/access untouched', val('SELECT status FROM access_codes WHERE order_id = ?', [$f['id']]) === 'active');
    $gw->next = 'failed';
    check('gateway reporting status failed -> refund failed', $svc->approve($fid, $ADMIN, null)['status'] === 'failed' && val('SELECT status FROM orders WHERE id = ?', [$f['id']]) === 'paid');
    $gw->next = 'processed';
    $out = $svc->approve($fid, $ADMIN, null);
    check('second approve after failure succeeds -> refunded, commission reversed', $out['status'] === 'refunded' && val('SELECT status FROM orders WHERE id = ?', [$f['id']]) === 'refunded' && val('SELECT status FROM commissions WHERE order_id = ?', [$f['id']]) === 'reversed');
    check('audit has a refund.failed row', (int) val("SELECT COUNT(*) FROM audit_logs WHERE action = 'refund.failed' AND entity_id = ?", [$fid]) === 2);

    // --- gateway pending -> processing, finalised later (the webhook path) ---
    $pn = paidOrder();
    $pid = (int) seedRequest($pn['uid'], $pn['id'], 'x')['refund']['id'];
    $gw->next = 'pending';
    $calls = count($gw->calls);
    $out = $svc->approve($pid, $ADMIN, null);
    $row = refundRow($pid);
    check('gateway pending -> processing, refund id stored, order still paid, code active', $out['status'] === 'processing' && str_starts_with((string) $row['razorpay_refund_id'], 'rfnd_stub_') && val('SELECT status FROM orders WHERE id = ?', [$pn['id']]) === 'paid' && val('SELECT status FROM access_codes WHERE order_id = ?', [$pn['id']]) === 'active');
    $gw->next = 'processed';
    check('approve while processing -> no second gateway call, still processing', $svc->approve($pid, $ADMIN, null)['status'] === 'processing' && count($gw->calls) === $calls + 1);
    check('reject while processing -> invalid_transition', str_starts_with(attempt(fn () => $svc->reject($pid, $ADMIN, 'no')), 'invalid_transition|409|'));
    check('finalise(processing) -> true', $svc->finalise($pid) === true);
    check('after finalise: refunded, order refunded, code revoked', refundRow($pid)['status'] === 'refunded' && val('SELECT status FROM orders WHERE id = ?', [$pn['id']]) === 'refunded' && val('SELECT status FROM access_codes WHERE order_id = ?', [$pn['id']]) === 'revoked');
    $rq = paidOrder();
    $rqid = (int) seedRequest($rq['uid'], $rq['id'], 'x')['refund']['id'];
    check('finalise on a merely requested refund -> false, nothing changes', $svc->finalise($rqid) === false && refundRow($rqid)['status'] === 'requested' && val('SELECT status FROM orders WHERE id = ?', [$rq['id']]) === 'paid');

    // --- late webhook (fromWebhook=true): failed refund, no stored id, newer request open on the order ---
    $lw = paidOrder('digital', true);
    $lwFailed = (int) seedRequest($lw['uid'], $lw['id'], 'x')['refund']['id'];
    $gw->next = 'throw';
    $svc->approve($lwFailed, $ADMIN, null); // timed out, but the money actually went
    $gw->next = 'processed';
    $lwOpen = (int) seedRequest($lw['uid'], $lw['id'], 'still want it')['refund']['id'];
    check('fixture: failed refund with NULL refund id + newer requested refund on the same order', refundRow($lwFailed)['status'] === 'failed' && refundRow($lwFailed)['razorpay_refund_id'] === null && refundRow($lwOpen)['status'] === 'requested');
    $calls = count($gw->calls);
    $ok = $svc->finalise($lwFailed, "rfnd_late_$tag", true);
    check('webhook finalise of the failed one -> true, the open one refunded with the refund id, failed one untouched', $ok === true && refundRow($lwOpen)['status'] === 'refunded' && refundRow($lwOpen)['razorpay_refund_id'] === "rfnd_late_$tag" && refundRow($lwFailed)['status'] === 'failed' && refundRow($lwFailed)['razorpay_refund_id'] === null);
    check('exactly one refunded refund; order/payment refunded; code revoked; commission reversed; no gateway call', (int) val("SELECT COUNT(*) FROM refunds WHERE order_id = ? AND status = 'refunded'", [$lw['id']]) === 1 && val('SELECT status FROM orders WHERE id = ?', [$lw['id']]) === 'refunded' && val('SELECT status FROM payments WHERE order_id = ?', [$lw['id']]) === 'refunded' && val('SELECT status FROM access_codes WHERE order_id = ?', [$lw['id']]) === 'revoked' && val('SELECT status FROM commissions WHERE order_id = ?', [$lw['id']]) === 'reversed' && count($gw->calls) === $calls);
    $before = snapshot($lw, $lwOpen);
    check('replayed late webhook (failed one again) -> true, nothing changes', $svc->finalise($lwFailed, "rfnd_late_$tag", true) === true && snapshot($lw, $lwOpen) === $before);
    $lf = paidOrder();
    $lfId = (int) seedRequest($lf['uid'], $lf['id'], 'x')['refund']['id'];
    $gw->next = 'throw';
    $svc->approve($lfId, $ADMIN, null);
    $gw->next = 'processed';
    check('webhook finalise of a lone failed refund (NULL id) -> refunded, id stored', $svc->finalise($lfId, "rfnd_lone_$tag", true) === true && refundRow($lfId)['status'] === 'refunded' && refundRow($lfId)['razorpay_refund_id'] === "rfnd_lone_$tag" && val('SELECT status FROM orders WHERE id = ?', [$lf['id']]) === 'refunded');
    $wr = paidOrder();
    $wrId = (int) seedRequest($wr['uid'], $wr['id'], 'x')['refund']['id'];
    check('webhook finalise of a requested refund -> refunded, order refunded, code revoked', $svc->finalise($wrId, "rfnd_req_$tag", true) === true && refundRow($wrId)['status'] === 'refunded' && val('SELECT status FROM orders WHERE id = ?', [$wr['id']]) === 'refunded' && val('SELECT status FROM access_codes WHERE order_id = ?', [$wr['id']]) === 'revoked');

    // --- refund.failed: processing -> failed, nothing money-side; approve again retries ---
    $mf = paidOrder('digital', true);
    $mfId = (int) seedRequest($mf['uid'], $mf['id'], 'x')['refund']['id'];
    $gw->next = 'pending';
    $svc->approve($mfId, $ADMIN, null);
    $gw->next = 'processed';
    check('markFailed(processing) -> true, failed with note, refund id kept, order paid, code active, commission pending', $svc->markFailed($mfId) === true && refundRow($mfId)['status'] === 'failed' && refundRow($mfId)['admin_note'] !== null && refundRow($mfId)['razorpay_refund_id'] !== null && val('SELECT status FROM orders WHERE id = ?', [$mf['id']]) === 'paid' && val('SELECT status FROM access_codes WHERE order_id = ?', [$mf['id']]) === 'active' && val('SELECT status FROM commissions WHERE order_id = ?', [$mf['id']]) === 'pending');
    check('markFailed again -> false; refund.failed audit row written', $svc->markFailed($mfId) === false && (int) val("SELECT COUNT(*) FROM audit_logs WHERE action = 'refund.failed' AND entity_type = 'refund' AND entity_id = ?", [$mfId]) === 1);
    check('approve after a gateway-failed refund -> refunded', $svc->approve($mfId, $ADMIN, null)['status'] === 'refunded' && val('SELECT status FROM commissions WHERE order_id = ?', [$mf['id']]) === 'reversed');
    check('markFailed on a refunded / requested refund -> false, unchanged', $svc->markFailed($mfId) === false && refundRow($mfId)['status'] === 'refunded' && $svc->markFailed($wrId) === false);

    // --- two processes approving the same refund: one gateway call ---
    $ca = paidOrder();
    $caId = (int) seedRequest($ca['uid'], $ca['id'], 'x')['refund']['id'];
    $log = sys_get_temp_dir() . "/refund_calls_$tag.log";
    @unlink($log);
    $db->beginTransaction();
    q('SELECT id FROM refunds WHERE id = ? FOR UPDATE', [$caId]); // both children queue on the row lock
    $procs = [];
    for ($k = 0; $k < 2; $k++) {
        $pipes = [];
        $procs[] = [proc_open([PHP_BINARY, __DIR__ . '/helpers/refund_approve.php', (string) $caId, $log], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
    }
    usleep(500000);
    $db->rollBack();
    $outs = [];
    foreach ($procs as [$p, $pipes]) {
        $outs[] = trim(stream_get_contents($pipes[1])) . trim(stream_get_contents($pipes[2]));
        proc_close($p);
    }
    $logged = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];
    @unlink($log);
    sort($outs);
    // The loser may read `processing`: the winner commits it before finalising in a second transaction.
    check('concurrent approves: gateway called once, loser is a no-op, one refunded row', $logged === ["refund_$caId"] && in_array($outs, [['processing', 'refunded'], ['refunded', 'refunded']], true) && refundRow($caId)['status'] === 'refunded' && (int) val("SELECT COUNT(*) FROM audit_logs WHERE action = 'refund.refunded' AND entity_type = 'refund' AND entity_id = ?", [$caId]) === 1, implode(',', $outs) . ' calls=' . count($logged));

    // --- admin-opened refunds ---
    $ao = paidOrder('digital', true);
    $unpaid = paidOrder('digital', false, false);
    check('adminOpen: reason required', str_starts_with(attempt(fn () => $svc->adminOpen($ao['id'], $ADMIN, '')), 'validation_error|400|{"reason"'));
    check('adminOpen: unknown order -> not_found', str_starts_with(attempt(fn () => $svc->adminOpen(0, $ADMIN, 'x')), 'not_found|404|'));
    check('adminOpen: unpaid order -> not_refundable', str_starts_with(attempt(fn () => $svc->adminOpen($unpaid['id'], $ADMIN, 'x')), 'not_refundable|409|'));
    $res = $svc->adminOpen($ao['id'], $ADMIN, 'goodwill');
    $row = refundRow((int) $res['refund']['id']);
    check('adminOpen -> created and refunded directly, no requester, reviewer set', $res['created'] === true && $row['status'] === 'refunded' && $row['requested_by_user_id'] === null && (int) $row['reviewed_by_admin_id'] === $ADMIN && val('SELECT status FROM orders WHERE id = ?', [$ao['id']]) === 'refunded');
    $res = $svc->adminOpen($rq['id'], $ADMIN, 'approve the customer request');
    check('adminOpen with an open customer request approves that same refund', $res['created'] === false && (int) $res['refund']['id'] === $rqid && refundRow($rqid)['status'] === 'refunded');
    check('approve unknown refund -> not_found', str_starts_with(attempt(fn () => $svc->approve(0, $ADMIN, null)), 'not_found|404|'));

    // --- refund reconciliation for lost webhooks (OrderReconciler::reconcileRefunds with a scripted FakeGateway) ---
    $mkRefund = function (array $o, string $status, ?string $rzpRefundId, int $ageMinutes): int {
        global $db;
        q("INSERT INTO refunds (order_id, payment_id, reviewed_by_admin_id, status, reason, amount, razorpay_refund_id, updated_at)
           SELECT ?, id, 1, ?, 'reconcile test', amount, ?, NOW() - INTERVAL ? MINUTE FROM payments WHERE order_id = ?", [$o['id'], $status, $rzpRefundId, $ageMinutes, $o['id']]);
        return (int) $db->lastInsertId();
    };
    $redeemed = function (array $o): void {
        AccessCodeService::redeem($o['uid'], (string) AccessCodeService::revealForOwner($o['uid'], $o['id']));
    };
    $ent = fn (string $id, array $o, int $amount, string $status) => ['id' => $id, 'payment_id' => $o['pay'], 'amount' => $amount, 'status' => $status];
    $orderStatus = fn (array $o) => (string) val('SELECT status FROM orders WHERE id = ?', [$o['id']]);
    $commission = fn (array $o) => (string) val('SELECT status FROM commissions WHERE order_id = ?', [$o['id']]);
    $fg = new FakeGateway();

    $lost = paidOrder('digital', true);  $redeemed($lost);
    $lostId = $mkRefund($lost, 'processing', "rfnd_{$tag}_lost", 30);
    $fg->refunds["rfnd_{$tag}_lost"] = $ent("rfnd_{$tag}_lost", $lost, 4990, 'processed');
    $gone = paidOrder();
    $goneId = $mkRefund($gone, 'processing', "rfnd_{$tag}_gone", 30);
    $fg->refunds["rfnd_{$tag}_gone"] = $ent("rfnd_{$tag}_gone", $gone, 4990, 'failed');
    $slow = paidOrder();
    $slowId = $mkRefund($slow, 'processing', "rfnd_{$tag}_slow", 30);
    $fg->refunds["rfnd_{$tag}_slow"] = $ent("rfnd_{$tag}_slow", $slow, 4990, 'pending');
    $young = paidOrder();
    $youngId = $mkRefund($young, 'processing', "rfnd_{$tag}_young", 5);
    $fg->refunds["rfnd_{$tag}_young"] = $ent("rfnd_{$tag}_young", $young, 4990, 'processed');
    $tout = paidOrder('digital', true);  $redeemed($tout);
    $toutId = $mkRefund($tout, 'failed', null, 30);
    $fg->refunds["rfnd_{$tag}_tout0"] = $ent("rfnd_{$tag}_tout0", $tout, 4990, 'failed');
    $fg->refunds["rfnd_{$tag}_tout"] = $ent("rfnd_{$tag}_tout", $tout, 4990, 'processed');
    $part = paidOrder('digital', true);  $redeemed($part);
    $partId = $mkRefund($part, 'failed', null, 30);
    $fg->refunds["rfnd_{$tag}_part"] = $ent("rfnd_{$tag}_part", $part, 1000, 'processed');
    $boomR = paidOrder();
    $boomId = $mkRefund($boomR, 'processing', "rfnd_{$tag}_boom", 30);
    $fg->refunds["rfnd_{$tag}_boom"] = new ApiException('Payment gateway error', 502, 'gateway_error');
    $oldF = paidOrder();
    $oldFId = $mkRefund($oldF, 'failed', null, 8 * 24 * 60); // outside the 7-day window
    $fg->refunds["rfnd_{$tag}_oldf"] = $ent("rfnd_{$tag}_oldf", $oldF, 4990, 'processed');
    $realFail = paidOrder();
    $realFailId = $mkRefund($realFail, 'failed', "rfnd_{$tag}_realfail", 30); // came from a real refund.failed: not re-checked
    $fg->refunds["rfnd_{$tag}_realfail2"] = $ent("rfnd_{$tag}_realfail2", $realFail, 4990, 'processed');

    $mine = implode(',', [$lostId, $goneId, $slowId, $youngId, $toutId, $partId, $boomId, $oldFId, $realFailId]);
    $foreign = (int) val("SELECT COUNT(*) FROM refunds r JOIN payments p ON p.id = r.payment_id JOIN orders o ON o.id = r.order_id
        WHERE p.razorpay_payment_id IS NOT NULL AND r.updated_at <= NOW() - INTERVAL 10 MINUTE AND r.id NOT IN ($mine)
          AND (r.status = 'processing' OR (r.status = 'failed' AND r.razorpay_refund_id IS NULL AND o.status = 'paid' AND r.updated_at >= NOW() - INTERVAL 7 DAY))");
    $res = \App\Orders\OrderReconciler::reconcileRefunds($fg);
    check('reconcile refunds: finalised 2, failed 1, flagged 1, errors 1 (+ foreign rows the fake cannot answer)', $res === ['finalised' => 2, 'failed' => 1, 'flagged' => 1, 'errors' => 1 + $foreign], json_encode($res) . " foreign=$foreign");
    check('lost refund.processed healed: refunded, order refunded, access revoked, commission reversed',
        refundRow($lostId)['status'] === 'refunded' && $orderStatus($lost) === 'refunded' && access($lost['uid'])['revoked_at'] !== null && $commission($lost) === 'reversed');
    check('processing -> gateway failed: refund failed, order still paid', refundRow($goneId)['status'] === 'failed' && $orderStatus($gone) === 'paid');
    check('processing -> gateway pending: unchanged', refundRow($slowId)['status'] === 'processing' && $orderStatus($slow) === 'paid');
    check('processing younger than 10 min: not touched', refundRow($youngId)['status'] === 'processing' && $orderStatus($young) === 'paid');
    $t = refundRow($toutId);
    check('timed-out (failed, no id) but processed at Razorpay: finalised with that id, access revoked, commission reversed',
        $t['status'] === 'refunded' && $t['razorpay_refund_id'] === "rfnd_{$tag}_tout" && $orderStatus($tout) === 'refunded' && access($tout['uid'])['revoked_at'] !== null && $commission($tout) === 'reversed');
    $p = refundRow($partId);
    check('partial amount at Razorpay: never finalised; order paid, access live, commission untouched, flagged + audit',
        $p['status'] === 'failed' && $p['razorpay_refund_id'] === null && $orderStatus($part) === 'paid' && access($part['uid'])['revoked_at'] === null && $commission($part) === 'pending'
        && val('SELECT admin_flag FROM orders WHERE id = ?', [$part['id']]) === 'refund_external_unmatched'
        && (int) val("SELECT COUNT(*) FROM audit_logs WHERE action = 'refund.external_unmatched' AND entity_type = 'order' AND entity_id = ?", [$part['id']]) === 1);
    check('gateway error: refund untouched, counted as error', refundRow($boomId)['status'] === 'processing' && $orderStatus($boomR) === 'paid');
    check('failed (no id) older than 7 days: not re-checked', refundRow($oldFId)['status'] === 'failed' && $orderStatus($oldF) === 'paid');
    check('failed with a refund id (real refund.failed): not re-checked', refundRow($realFailId)['status'] === 'failed' && $orderStatus($realFail) === 'paid');

    $before = json_encode(q("SELECT id, status, razorpay_refund_id FROM refunds WHERE id IN ($mine) ORDER BY id")->fetchAll());
    $res2 = \App\Orders\OrderReconciler::reconcileRefunds($fg);
    check('replay: nothing new (no finalise/flag), rows identical, one refund.refunded audit each',
        $res2['finalised'] === 0 && $res2['flagged'] === 0 && $res2['failed'] === 0
        && json_encode(q("SELECT id, status, razorpay_refund_id FROM refunds WHERE id IN ($mine) ORDER BY id")->fetchAll()) === $before
        && (int) val("SELECT COUNT(*) FROM audit_logs WHERE action = 'refund.refunded' AND entity_type = 'refund' AND entity_id IN (?, ?)", [$lostId, $toutId]) === 2
        && (int) val("SELECT COUNT(*) FROM audit_logs WHERE action = 'refund.external_unmatched' AND entity_id = ?", [$part['id']]) === 1, json_encode($res2));
    $capped = \App\Orders\OrderReconciler::reconcileRefunds($fg, 10, 7, 1);
    check('call cap: at most maxCalls refunds looked at per run', array_sum($capped) <= 1, json_encode($capped));

    // External (dashboard) refund: admin trail only, never revokes or touches commissions; existing flag kept.
    $ext = paidOrder('digital', true);  $redeemed($ext);
    check('external refund on our payment -> flagged once', RefundService::flagExternalUnmatched($ext['pay'], "rfnd_{$tag}_ext", 4990, 'unknown_refund') === true
        && RefundService::flagExternalUnmatched($ext['pay'], "rfnd_{$tag}_ext", 4990, 'unknown_refund') === false);
    check('external refund: order paid, access live, commission pending, flag + one audit row',
        $orderStatus($ext) === 'paid' && access($ext['uid'])['revoked_at'] === null && $commission($ext) === 'pending'
        && val('SELECT admin_flag FROM orders WHERE id = ?', [$ext['id']]) === 'refund_external_unmatched'
        && (int) val("SELECT COUNT(*) FROM audit_logs WHERE action = 'refund.external_unmatched' AND entity_id = ?", [$ext['id']]) === 1);
    $dup = paidOrder();
    q("UPDATE orders SET admin_flag = 'duplicate_payment' WHERE id = ?", [$dup['id']]);
    check('external refund on an already-flagged order: audit row, existing flag kept',
        RefundService::flagExternalUnmatched($dup['pay'], "rfnd_{$tag}_dup", 4990, 'unknown_refund') === true
        && val('SELECT admin_flag FROM orders WHERE id = ?', [$dup['id']]) === 'duplicate_payment');
    check('external refund on a payment that is not ours -> false, nothing written',
        RefundService::flagExternalUnmatched("pay_not_ours_$tag", "rfnd_{$tag}_x", 4990, 'unknown_refund') === false
        && (int) val("SELECT COUNT(*) FROM audit_logs WHERE action = 'refund.external_unmatched' AND CAST(detail AS CHAR) LIKE ?", ["%rfnd_{$tag}_x%"]) === 0);

    // --- no plaintext codes or secrets in refund audit rows ---
    $ids = implode(',', array_map('intval', $userIds));
    check('refund audit detail never holds code_enc/plaintext keys', (int) val("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'refund' AND (CAST(detail AS CHAR) LIKE '%code_enc%' OR CAST(detail AS CHAR) LIKE '%secret%') AND entity_id IN (SELECT id FROM refunds WHERE order_id IN (SELECT id FROM orders WHERE user_id IN ($ids)))") === 0);
} catch (\Throwable $e) {
    echo 'FAIL: unexpected ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $ids = $userIds ? implode(',', array_map('intval', $userIds)) : '0';
    $orderIds = "SELECT id FROM orders WHERE user_id IN ($ids)";
    $db->exec("DELETE FROM audit_logs WHERE (entity_type = 'refund' AND entity_id IN (SELECT id FROM refunds WHERE order_id IN ($orderIds)))
                 OR user_id IN ($ids) OR (entity_type = 'access_code' AND entity_id IN (SELECT id FROM access_codes WHERE order_id IN ($orderIds)))
                 OR (entity_type IN ('order', 'commission') AND entity_id IN (SELECT id FROM orders WHERE user_id IN ($ids)))");
    $db->exec("DELETE FROM refunds WHERE order_id IN ($orderIds)");
    $db->exec("DELETE FROM commissions WHERE order_id IN ($orderIds)");
    $db->exec("DELETE FROM access_codes WHERE order_id IN ($orderIds)");
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
echo "\nAll refund tests passed.\n";
