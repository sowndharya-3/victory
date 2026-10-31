<?php
/**
 * Deploy-time configuration check. CLI only. Prints PASS / WARN / FAIL per item, never a secret value.
 *   php backend/scripts/check_config.php        (in Docker: docker compose exec php php scripts/check_config.php)
 * Exit code 1 when any item FAILs.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

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
use App\Razorpay\Config;
use App\Support\Env;

$fails = 0;
function item(string $status, string $what): void
{
    global $fails;
    echo str_pad($status, 5) . "$what\n";
    if ($status === 'FAIL') {
        $fails++;
    }
}
function ok(bool $pass, string $what, string $failStatus = 'FAIL'): void
{
    item($pass ? 'PASS' : $failStatus, $what);
}

$dev = Env::isDev();
$appEnv = getenv('APP_ENV');
item($dev ? 'WARN' : 'PASS', 'APP_ENV ' . ($appEnv === false ? '(unset)' : json_encode($appEnv)) . ($dev ? ' is a dev value: fake gateway allowed, do not deploy' : ' is treated as production'));

try {
    $cfg = Config::load();
    item($cfg['mode'] === 'fake' ? 'WARN' : 'PASS', "payment gateway config loads (RAZORPAY_GATEWAY={$cfg['mode']})");
    if ($cfg['mode'] === 'live') {
        ok(str_starts_with($cfg['keyId'], 'rzp_live_'), 'RAZORPAY_KEY_ID is a live key (rzp_live_)' . (str_starts_with($cfg['keyId'], 'rzp_test_') ? ' - currently rzp_test_' : ''), 'WARN');
    }
} catch (Throwable $e) {
    item('FAIL', 'payment gateway config: ' . $e->getMessage()); // messages name variables, never values
}
ok(!Env::weakSecret((string) getenv('RAZORPAY_WEBHOOK_SECRET'), $dev ? 1 : 32), 'RAZORPAY_WEBHOOK_SECRET present' . ($dev ? '' : ', >= 32 chars, not a placeholder'));
foreach (['JWT_SECRET', 'CODE_SECRET'] as $k) {
    ok(!Env::weakSecret((string) getenv($k), 32), "$k >= 32 chars, not a placeholder");
}
ok(!Env::weakSecret((string) getenv('DB_PASS'), $dev ? 1 : 12), 'DB_PASS set' . ($dev ? '' : ', >= 12 chars, not a placeholder'), $dev ? 'WARN' : 'FAIL');

$origin = (string) getenv('FRONTEND_ORIGIN');
$parts = parse_url($origin);
$isOrigin = is_array($parts) && isset($parts['scheme'], $parts['host']) && !isset($parts['path']) && !isset($parts['query']) && !isset($parts['user']) && !str_ends_with($origin, '/');
ok($isOrigin, 'FRONTEND_ORIGIN is a bare origin (scheme://host[:port], no path or trailing slash)');
if ($isOrigin) {
    ok($parts['scheme'] === 'https', 'FRONTEND_ORIGIN uses https', $dev ? 'WARN' : 'FAIL');
}

try {
    $db = Db::connection();
    $db->query('SELECT 1');
    item('PASS', 'database reachable');
    $has = function (string $sql, array $p) use ($db): bool {
        $s = $db->prepare($sql);
        $s->execute($p);
        return (int) $s->fetchColumn() > 0;
    };
    $table = fn (string $t) => $has('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]);
    $column = fn (string $t, string $c) => $has('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]);
    $index = fn (string $t, string $i) => $has('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$t, $i]);
    $migrations = [
        '001_init' => fn () => $table('admins') && $table('referral_partners'),
        '002_book_management' => fn () => $column('books', 'archived') && $table('book_preview_pages'),
        '003_orders_payments' => fn () => $table('audit_logs') && $table('rate_limits') && $index('payments', 'uq_payments_rzp_payment') && $index('orders', 'uq_orders_tracking_code'),
        '004_refunds' => fn () => $table('refunds') && $column('commissions', 'clawback_due'),
        '005_books_page_count' => fn () => $column('books', 'page_count'),
        '006_audit_logs_entity_index' => fn () => $index('audit_logs', 'idx_audit_entity'),
        '007_commission_payable_at' => fn () => $column('commissions', 'payable_at'),
        '008_partner_email_unique' => fn () => $index('referral_partners', 'uq_partner_gmail') && $index('referral_applications', 'uq_open_application'),
    ];
    foreach ($migrations as $name => $applied) {
        ok($applied(), "migration $name applied");
    }
} catch (Throwable $e) {
    item('FAIL', 'database reachable (' . get_class($e) . ')');
}

$backend = realpath(__DIR__ . '/..');
$webRoot = realpath("$backend/public");
$storage = realpath("$backend/storage/books");
ok($storage !== false && is_dir($storage) && is_writable($storage), 'storage/books exists and is writable by this user (run as the PHP-FPM user)');
ok($storage !== false && $webRoot !== false && !str_starts_with($storage . '/', $webRoot . '/'), 'storage/books is outside the web root');
$uploads = realpath("$webRoot/uploads");
ok($uploads !== false && is_writable($uploads), 'public/uploads exists and is writable (covers, previews, index files)');

foreach (['pdfinfo', 'pdftoppm'] as $tool) {
    $found = false;
    foreach (explode(':', (string) getenv('PATH') ?: '/usr/bin:/bin') as $dir) {
        $found = $found || is_executable("$dir/$tool");
    }
    ok($found, "$tool installed (poppler-utils)");
}
ok(is_executable('/usr/bin/prlimit'), 'prlimit available (reader resource limits)', 'WARN');

echo $fails ? "\n$fails check(s) FAILED.\n" : "\nAll required checks passed.\n";
exit($fails ? 1 : 0);
