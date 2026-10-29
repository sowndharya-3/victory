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
use App\Codes\CodeCipher;
use App\Codes\CodeGenerator;
use App\Database\Db;
use App\Http\ApiException;
use App\Orders\OrderFulfillment;
use App\Support\Money;

$db = Db::connection();
$tag = 'cod' . getmypid() . time();
$failures = 0;
$userIds = [];
$bookId = 0;
$plain = []; // every plaintext code seen, checked against the DB at the end

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
    q('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)', ["COD $n", "$tag-$n@example.com", 'x']);
    return $userIds[] = (int) $db->lastInsertId();
}

/** Paid digital order via the real markPaid path. @return array{id:int,uid:int} */
function paidDigital(): array
{
    global $db, $bookId, $tag;
    static $i = 0;
    $i++;
    $uid = mkUser();
    q("INSERT INTO orders (order_number, user_id, book_id, purchase_type, amount, status, fulfilment_status) VALUES (?, ?, ?, 'digital', ?, 'pending', 'not_applicable')", ["$tag-$i", $uid, $bookId, Money::fromPaise(10000)]);
    $id = (int) $db->lastInsertId();
    q("INSERT INTO payments (order_id, razorpay_order_id, amount, status) VALUES (?, ?, ?, 'created')", [$id, "order_$tag$i", Money::fromPaise(10000)]);
    OrderFulfillment::markPaid("order_$tag$i", "pay_$tag$i", 10000);
    return ['id' => $id, 'uid' => $uid];
}

function reveal(array $o): ?string
{
    global $plain;
    $c = AccessCodeService::revealForOwner($o['uid'], $o['id']);
    if ($c !== null) {
        $plain[] = $c;
    }
    return $c;
}

/** @return string error code, or 'ok:<bookId>:<orderId>' */
function tryRedeem(int $uid, string $input): string
{
    try {
        $r = AccessCodeService::redeem($uid, $input);
        return "ok:{$r['bookId']}:{$r['orderId']}";
    } catch (ApiException $e) {
        return $e->errorCode . '|' . $e->statusCode . '|' . $e->getMessage();
    }
}

/** Customer-path reissue (no admin). @return string 'ok' or 'code|status|message' */
function tryReissue(array $o): string
{
    global $plain;
    try {
        $plain[] = AccessCodeService::reissue($o['id'], null, $o['uid'], 'customer_request');
        return 'ok';
    } catch (ApiException $e) {
        return $e->errorCode . '|' . $e->statusCode . '|' . $e->getMessage();
    }
}

function access(int $uid): ?array
{
    global $bookId;
    return q('SELECT order_id, revoked_at FROM book_access WHERE user_id = ? AND book_id = ?', [$uid, $bookId])->fetch() ?: null;
}

try {
    // --- generator ---
    $seen = [];
    $okShape = true;
    for ($k = 0; $k < 1000; $k++) {
        $c = CodeGenerator::digital();
        $okShape = $okShape && preg_match('/^[0-9A-HJKMNP-TV-Z]{16}$/', $c) === 1;
        $seen[$c] = true;
    }
    check('digital(): 16 Crockford chars over 1000 draws', $okShape);
    check('digital(): 1000 distinct draws', count($seen) === 1000);
    $seen = [];
    $okShape = true;
    for ($k = 0; $k < 1000; $k++) {
        $c = CodeGenerator::tracking();
        $okShape = $okShape && preg_match('/^VTT-[0-9A-HJKMNP-TV-Z]{8}$/', $c) === 1;
        $seen[$c] = true;
    }
    check('tracking(): VTT- + 8 Crockford chars, 1000 distinct', $okShape && count($seen) === 1000);
    // Brief says ABCDEFGHIJKLMNOP, but normalise maps I/L->1 and O->0 by the same brief.
    check('normalise strips spaces/dashes, uppercases, maps I/L/O', CodeGenerator::normalise('abcd-efgh ijkl-mnop') === 'ABCDEFGH1JK1MN0P');
    check('normalise: no ambiguous letters left', CodeGenerator::normalise(' 0o1il-  ') === '00111');
    check('format groups of 4', CodeGenerator::format('ABCDEFGHJKMNPQRS') === 'ABCD-EFGH-JKMN-PQRS');

    // --- cipher ---
    $blob = CodeCipher::encrypt('ABCDEFGHJKMNPQRS', '101');
    check('cipher round-trips', CodeCipher::decrypt($blob, '101') === 'ABCDEFGHJKMNPQRS');
    check('blob bound to its order: other order id (AAD) -> null', CodeCipher::decrypt($blob, '102') === null);
    check('ciphertext does not contain plaintext', !str_contains($blob, 'ABCDEFGHJKMNPQRS'));
    check('two encryptions differ (random IV)', CodeCipher::encrypt('ABCDEFGHJKMNPQRS', '101') !== $blob);
    $flipped = $blob;
    $flipped[strlen($flipped) - 1] = chr(ord($flipped[strlen($flipped) - 1]) ^ 1);
    check('flipped byte -> null', CodeCipher::decrypt($flipped, '101') === null);
    check('truncated blob -> null', CodeCipher::decrypt('short', '101') === null);
    check('hash is 64 hex and deterministic', preg_match('/^[0-9a-f]{64}$/', CodeCipher::hash('ABC')) === 1 && CodeCipher::hash('ABC') === CodeCipher::hash('ABC'));
    $saved = getenv('CODE_SECRET');
    putenv('CODE_SECRET=changeme-generate-a-real-random-secret-xxxxxxxx');
    $rej1 = false;
    try { CodeCipher::hash('A'); } catch (\RuntimeException $e) { $rej1 = true; }
    putenv('CODE_SECRET=tooshort');
    $rej2 = false;
    try { CodeCipher::encrypt('A', '1'); } catch (\RuntimeException $e) { $rej2 = true; }
    putenv('CODE_SECRET=' . $saved);
    check('CODE_SECRET placeholder and short values rejected at use', $rej1 && $rej2);

    q('INSERT INTO books (title, online_price, hardcopy_price, published) VALUES (?, 100, 200, 1)', ["COD throwaway $tag"]);
    $bookId = (int) $db->lastInsertId();

    // --- issue + redeem by buyer ---
    $o = paidDigital();
    check('markPaid digital => one active code row, zero book_access', (int) val("SELECT COUNT(*) FROM access_codes WHERE order_id = ? AND status = 'active' AND code_enc IS NOT NULL AND user_id = ? AND book_id = ?", [$o['id'], $o['uid'], $bookId]) === 1 && access($o['uid']) === null);
    AccessCodeService::issueForOrder($o['id']);
    check('issueForOrder is idempotent', (int) val('SELECT COUNT(*) FROM access_codes WHERE order_id = ?', [$o['id']]) === 1);
    $code = reveal($o);
    check('reveal returns XXXX-XXXX-XXXX-XXXX', $code !== null && preg_match('/^([0-9A-HJKMNP-TV-Z]{4}-){3}[0-9A-HJKMNP-TV-Z]{4}$/', $code) === 1, (string) ($code === null ? 'null' : 'bad shape'));
    check('last4 stored', val('SELECT code_last4 FROM access_codes WHERE order_id = ?', [$o['id']]) === substr((string) $code, -4));
    $other = mkUser();
    check('reveal by a non-owner -> null', AccessCodeService::revealForOwner($other, $o['id']) === null);

    check('buyer redeems (lowercase, spaces ok)', tryRedeem($o['uid'], strtolower(str_replace('-', ' ', (string) $code))) === "ok:$bookId:{$o['id']}");
    $a = access($o['uid']);
    check('book_access row with order_id, not revoked', $a !== null && (int) $a['order_id'] === $o['id'] && $a['revoked_at'] === null);
    $row = q('SELECT status, redeemed_by_user_id, redeemed_at, code_enc FROM access_codes WHERE order_id = ?', [$o['id']])->fetch();
    check('code redeemed, redeemer set, code_enc NULL', $row['status'] === 'redeemed' && (int) $row['redeemed_by_user_id'] === $o['uid'] && $row['redeemed_at'] !== null && $row['code_enc'] === null);
    check('reveal after redeem -> null', AccessCodeService::revealForOwner($o['uid'], $o['id']) === null);
    check('buyer redeems again -> idempotent success', tryRedeem($o['uid'], (string) $code) === "ok:$bookId:{$o['id']}");
    check('second user -> code_already_redeemed 409', str_starts_with(tryRedeem($other, (string) $code), 'code_already_redeemed|409|'));
    check('second user has no access row', access($other) === null);

    // --- invalid inputs share one message ---
    $unknown = tryRedeem($other, CodeGenerator::format(CodeGenerator::digital()));
    check('unknown valid-format code -> code_invalid', str_starts_with($unknown, 'code_invalid|'));
    check('garbage -> identical code_invalid', tryRedeem($other, 'hello world!!') === $unknown);
    check('15-char input -> identical code_invalid', tryRedeem($other, substr(CodeGenerator::digital(), 0, 15)) === $unknown);
    check('tracking code -> identical code_invalid', tryRedeem($other, CodeGenerator::tracking()) === $unknown);

    // --- gift: a friend redeems an unredeemed code ---
    $g = paidDigital();
    $gc = reveal($g);
    $friend = mkUser();
    check('friend redeems a gifted code', tryRedeem($friend, (string) $gc) === "ok:$bookId:{$g['id']}" && access($friend) !== null);

    // --- customer reissue is restricted; admin reissue is not ---
    $needAdmin = 'reissue_requires_admin|403|Please contact support to reissue this code';
    check('buyer reissue of a code a friend redeemed -> reissue_requires_admin 403', tryReissue($g) === $needAdmin);
    check('refused reissue: friend keeps access, code still redeemed, no new code', access($friend)['revoked_at'] === null && (int) val('SELECT COUNT(*) FROM access_codes WHERE order_id = ?', [$g['id']]) === 1 && val('SELECT status FROM access_codes WHERE order_id = ?', [$g['id']]) === 'redeemed');
    $g2 = paidDigital();
    $friend2 = mkUser();
    tryRedeem($friend2, (string) reveal($g2));
    $plain[] = $adminCode = AccessCodeService::reissue($g2['id'], 1, null, 'admin reissue');
    check('admin reissue of a gifted code works, revokes the friend\'s access', AccessCodeService::revealForOwner($g2['uid'], $g2['id']) === $adminCode && access($friend2)['revoked_at'] !== null);
    $act = paidDigital();
    $actOld = reveal($act);
    check('buyer reissue of an active code -> allowed', tryReissue($act) === 'ok' && AccessCodeService::revealForOwner($act['uid'], $act['id']) !== $actOld);
    $cr = paidDigital();
    reveal($cr);
    AccessCodeService::revoke((int) val('SELECT id FROM access_codes WHERE order_id = ?', [$cr['id']]), 1, 'customer_request');
    check('buyer reissue of a code revoked for customer_request -> allowed', tryReissue($cr) === 'ok');

    // --- ciphertext is bound to its order (AAD): a blob moved to another order does not decrypt ---
    $hex = val("SELECT HEX(code_enc) FROM access_codes WHERE order_id = ? AND status = 'active'", [$act['id']]);
    q("UPDATE access_codes SET code_enc = UNHEX(?) WHERE order_id = ? AND status = 'active'", [$hex, $cr['id']]);
    check('code_enc copied from another order -> reveal null', $hex && AccessCodeService::revealForOwner($cr['uid'], $cr['id']) === null);

    // --- revoked ---
    $r = paidDigital();
    $rc = reveal($r);
    $admin = 1; // audit_logs.admin_id has no FK
    AccessCodeService::revoke((int) val('SELECT id FROM access_codes WHERE order_id = ?', [$r['id']]), $admin, 'test revoke');
    check('revoked code -> code_revoked 403', str_starts_with(tryRedeem($r['uid'], (string) $rc), 'code_revoked|403|'));
    check('revoke writes audit row', (int) val("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'access_code' AND action = 'access_code.revoke' AND entity_id = (SELECT id FROM access_codes WHERE order_id = ?)", [$r['id']]) === 1);
    check('revoked code: code_enc NULL', val('SELECT code_enc IS NULL FROM access_codes WHERE order_id = ?', [$r['id']]) == 1);

    // revoke after redemption removes access
    AccessCodeService::revoke((int) val('SELECT id FROM access_codes WHERE order_id = ?', [$g['id']]), $admin, 'test revoke redeemed');
    $fa = access($friend);
    check('revoke of redeemed code sets book_access.revoked_at', $fa !== null && $fa['revoked_at'] !== null);
    check('buyer reissue of an admin-revoked code -> reissue_requires_admin 403', tryReissue($g) === $needAdmin && tryReissue($r) === $needAdmin);

    // --- unpaid order ---
    $u = paidDigital();
    $uc = reveal($u);
    q("UPDATE orders SET status = 'refunded' WHERE id = ?", [$u['id']]);
    check('code on a non-paid order -> code_order_not_paid 403', str_starts_with(tryRedeem($u['uid'], (string) $uc), 'code_order_not_paid|403|'));
    check('reveal on non-paid order -> null', AccessCodeService::revealForOwner($u['uid'], $u['id']) === null);
    check('non-paid: no access row', access($u['uid']) === null);

    // --- reissue after redemption by the buyer themself (allowed on the customer path) ---
    $newCode = AccessCodeService::reissue($o['id'], null, $o['uid'], 'lost code');
    $plain[] = $newCode;
    check('reissue returns a new formatted code', $newCode !== $code && preg_match('/^([0-9A-HJKMNP-TV-Z]{4}-){3}[0-9A-HJKMNP-TV-Z]{4}$/', $newCode) === 1);
    $rows = q('SELECT id, status, replaced_by_id FROM access_codes WHERE order_id = ? ORDER BY id', [$o['id']])->fetchAll();
    check('old code revoked and linked to the new one', count($rows) === 2 && $rows[0]['status'] === 'revoked' && (int) $rows[0]['replaced_by_id'] === (int) $rows[1]['id'] && $rows[1]['status'] === 'active');
    $a = access($o['uid']);
    check('reissue after redemption revokes old access', $a !== null && $a['revoked_at'] !== null);
    check('old code now fails (code_revoked)', str_starts_with(tryRedeem($o['uid'], (string) $code), 'code_revoked|'));
    check('reissue audit row (user_id set, admin_id NULL)', (int) val("SELECT COUNT(*) FROM audit_logs WHERE action = 'access_code.reissue' AND user_id = ? AND admin_id IS NULL", [$o['uid']]) === 1);
    check('reveal shows the new code', AccessCodeService::revealForOwner($o['uid'], $o['id']) === $newCode);
    check('new code redeems and restores access', tryRedeem($o['uid'], $newCode) === "ok:$bookId:{$o['id']}" && access($o['uid'])['revoked_at'] === null);

    // --- already owns via another order: code not consumed ---
    $o2 = paidDigital();
    q('UPDATE orders SET user_id = ? WHERE id = ?', [$o['uid'], $o2['id']]);
    q('UPDATE access_codes SET user_id = ? WHERE order_id = ?', [$o['uid'], $o2['id']]);
    $c2 = AccessCodeService::revealForOwner($o['uid'], $o2['id']);
    $plain[] = (string) $c2;
    check('redeeming while already holding access -> already_owned, code stays active', str_starts_with(tryRedeem($o['uid'], (string) $c2), 'already_owned|409|') && val('SELECT status FROM access_codes WHERE order_id = ?', [$o2['id']]) === 'active');

    // --- two gift codes for the same book redeemed concurrently by one user: only one may be burned ---
    // Deterministic: hold the user's row lock so both children are queued before either checks ownership.
    $x1 = paidDigital();
    $x2 = paidDigital();
    $xc = [reveal($x1), reveal($x2)];
    $taker = mkUser();
    $db->beginTransaction();
    q('SELECT id FROM users WHERE id = ? FOR UPDATE', [$taker]);
    $procs = [];
    foreach ($xc as $c) {
        $pipes = [];
        $procs[] = [proc_open([PHP_BINARY, __DIR__ . '/helpers/redeem.php', (string) $taker, (string) $c], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
    }
    usleep(500000);
    $db->commit();
    $outs = [];
    foreach ($procs as [$p, $pipes]) {
        $outs[] = trim(stream_get_contents($pipes[1])) . trim(stream_get_contents($pipes[2]));
        proc_close($p);
    }
    sort($outs);
    check('concurrent redeems of two codes, same book: one ok, one already_owned', $outs === ['already_owned', 'ok'], implode(',', $outs));
    check('concurrent: exactly one code burned, the other still active', (int) val("SELECT COUNT(*) FROM access_codes WHERE order_id IN (?, ?) AND status = 'redeemed'", [$x1['id'], $x2['id']]) === 1 && (int) val("SELECT COUNT(*) FROM access_codes WHERE order_id IN (?, ?) AND status = 'active'", [$x1['id'], $x2['id']]) === 1);

    // --- no plaintext anywhere ---
    $ids = implode(',', array_map('intval', $userIds));
    $dump = json_encode([
        q("SELECT * FROM access_codes WHERE user_id IN ($ids)")->fetchAll(),
        q("SELECT * FROM audit_logs WHERE user_id IN ($ids) OR entity_id IN (SELECT id FROM access_codes WHERE user_id IN ($ids))")->fetchAll(),
        q("SELECT * FROM orders WHERE user_id IN ($ids)")->fetchAll(),
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    $leak = false;
    foreach ($plain as $p) {
        $n = str_replace('-', '', $p);
        $leak = $leak || str_contains($dump, $p) || str_contains($dump, $n);
    }
    check('DB never contains a plaintext code (' . count($plain) . ' checked)', !$leak && count($plain) >= 5);
} catch (\Throwable $e) {
    echo 'FAIL: unexpected ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    $ids = $userIds ? implode(',', array_map('intval', $userIds)) : '0';
    $orderIds = "SELECT id FROM orders WHERE user_id IN ($ids)";
    $db->exec("DELETE FROM audit_logs WHERE user_id IN ($ids) OR (entity_type = 'access_code' AND entity_id IN (SELECT id FROM access_codes WHERE order_id IN ($orderIds)))");
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
echo "\nAll access code tests passed.\n";
