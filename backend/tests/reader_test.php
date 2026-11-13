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

use App\Controllers\ReaderController;
use App\Database\Db;
use App\Http\ApiException;
use App\Reader\PdfPageRenderer;

$STORAGE = __DIR__ . '/../storage/books';

// Child mode for the concurrency check: render one page and exit 0/1.
if (($argv[1] ?? '') === 'child') {
    try {
        PdfPageRenderer::pagePath((int) $argv[2], (int) $argv[3]);
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, get_class($e) . "\n");
        exit(1);
    }
}

$db = Db::connection();
$tag = 'rdr' . getmypid() . time();
$failures = 0;
$bookIds = [];

function check(string $name, bool $ok, string $extra = ''): void
{
    global $failures;
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $name . ($ok || $extra === '' ? '' : " ($extra)") . "\n";
    if (!$ok) {
        $failures++;
    }
}

/** @return array{0:?ApiException,1:mixed} */
function attempt(callable $fn): array
{
    try {
        return [null, $fn()];
    } catch (ApiException $e) {
        return [$e, null];
    }
}

function rejected(callable $fn, int $status, string $code): bool
{
    [$e] = attempt($fn);
    return $e !== null && $e->statusCode === $status && $e->errorCode === $code;
}

function leaksNothing(?ApiException $e): bool
{
    return $e !== null && !preg_match('#/|storage|book\.pdf|pdftoppm|pdfinfo|Syntax|Error:#i', $e->getMessage());
}

function tempBook(?string $pdfSource): int
{
    global $db, $tag, $bookIds, $STORAGE;
    $db->prepare('INSERT INTO books (title, online_price, hardcopy_price, published) VALUES (?, 100, 200, 0)')
        ->execute(["RDR throwaway $tag"]);
    $id = $bookIds[] = (int) $db->lastInsertId();
    if ($pdfSource !== null) {
        mkdir("$STORAGE/$id", 0755, true);
        copy($pdfSource, "$STORAGE/$id/book.pdf");
    }
    return $id;
}

/** Minimal valid PDF: $pages blank pages of $mediaBox; $title is a raw PDF string body (escapes like \n apply). */
function makePdf(string $path, string $mediaBox, int $pages, string $title = 'T'): void
{
    $kids = implode(' ', array_map(fn ($i) => (4 + $i) . ' 0 R', range(0, $pages - 1)));
    $objs = ['<< /Type /Catalog /Pages 2 0 R >>', "<< /Type /Pages /Kids [" . ($pages ? $kids : '') . "] /Count $pages >>", "<< /Title ($title) >>"];
    for ($i = 0; $i < max($pages, 1); $i++) {
        $objs[] = "<< /Type /Page /Parent 2 0 R /MediaBox [$mediaBox] >>";
    }
    $pdf = "%PDF-1.4\n";
    $off = [];
    foreach ($objs as $i => $o) {
        $off[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($off as $o) {
        $pdf .= sprintf("%010d 00000 n \n", $o);
    }
    $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R /Info 3 0 R >>\nstartxref\n$xref\n%%EOF\n";
    file_put_contents($path, $pdf);
}

function rmTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        is_dir("$dir/$f") ? rmTree("$dir/$f") : unlink("$dir/$f");
    }
    rmdir($dir);
}

try {
    $fixture = __DIR__ . '/fixtures/reader.pdf';
    check('fixture is a small valid PDF', is_file($fixture) && filesize($fixture) < 50 * 1024);

    // ---- id parsing (route segments are strings; only plain digits are ids)
    check('positiveInt("12") === 12', ReaderController::positiveInt('12') === 12);
    foreach (['1;ls', '-1', 'abc', '', '1e3', ' 1', '0x1', '1.0', '../1', '1/2', '9999999999999'] as $raw) {
        check("positiveInt(" . json_encode($raw) . ") -> 404 not_found", rejected(fn () => ReaderController::positiveInt($raw), 404, 'not_found'));
    }

    // ---- pageCount
    $id = tempBook($fixture);
    $info = shell_exec('pdfinfo ' . escapeshellarg("$STORAGE/$id/book.pdf") . ' 2>/dev/null') ?? '';
    preg_match('/^Pages:\s+(\d+)/m', $info, $m);
    check('pdfinfo available in the container', isset($m[1]));
    check('pageCount matches pdfinfo (3)', PdfPageRenderer::pageCount($id) === 3 && ($m[1] ?? '') === '3');
    $stored = $db->query("SELECT page_count FROM books WHERE id = $id")->fetchColumn();
    check('books.page_count cached', (string) $stored === '3');
    $db->exec("UPDATE books SET page_count = 7 WHERE id = $id");
    check('pageCount served from books.page_count', PdfPageRenderer::pageCount($id) === 7);
    $db->exec("UPDATE books SET page_count = NULL WHERE id = $id");

    // ---- pagePath: renders, then reuses the cache
    $p1 = PdfPageRenderer::pagePath($id, 1);
    check('pagePath returns storage/books/{id}/pages/1.jpg', str_ends_with($p1, "/books/$id/pages/1.jpg") && is_file($p1));
    $bytes = file_get_contents($p1);
    check('page 1 is a JPEG (FF D8 FF)', substr($bytes, 0, 3) === "\xFF\xD8\xFF");
    clearstatcache();
    $mtime = filemtime($p1);
    sleep(1);
    $again = PdfPageRenderer::pagePath($id, 1);
    clearstatcache();
    check('second call reuses the cached file (same path, mtime unchanged)', $again === $p1 && filemtime($p1) === $mtime);
    $left = array_values(array_diff(scandir("$STORAGE/$id/pages"), ['.', '..', '1.jpg', '1.lock']));
    check('no temp files left after render', $left === [], implode(',', $left));

    // ---- range checks
    foreach ([0, 4, -1, PHP_INT_MAX] as $bad) {
        check("pagePath(page $bad) -> 404 not_found", rejected(fn () => PdfPageRenderer::pagePath($id, $bad), 404, 'not_found'));
    }
    check('pagePath(book 0) -> 404 not_found', rejected(fn () => PdfPageRenderer::pagePath(0, 1), 404, 'not_found'));
    check('page 3 (last) renders', is_file(PdfPageRenderer::pagePath($id, 3)));

    // ---- concurrent first renders of one page: all succeed, one file, no temp leftovers
    $procs = [];
    for ($i = 0; $i < 3; $i++) {
        $procs[] = proc_open([PHP_BINARY, __FILE__, 'child', (string) $id, '2'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    }
    $codes = array_map(fn ($p) => proc_close($p), $procs);
    $files = array_values(array_diff(scandir("$STORAGE/$id/pages"), ['.', '..']));
    sort($files);
    check('3 concurrent renders of page 2 all exit 0', $codes === [0, 0, 0], implode(',', $codes));
    check('only final jpgs + lock files in pages dir', $files === ['1.jpg', '1.lock', '2.jpg', '2.lock', '3.jpg', '3.lock'], implode(',', $files));

    // ---- invalidate (new PDF upload): clears page_count and cached pages
    PdfPageRenderer::invalidate($id);
    $stored = $db->query("SELECT page_count FROM books WHERE id = $id")->fetchColumn();
    check('invalidate clears page_count and page cache', $stored === null && !glob("$STORAGE/$id/pages/*.jpg"));

    // ---- failures never leak paths or tool output
    $none = tempBook(null);
    [$e] = attempt(fn () => PdfPageRenderer::pageCount($none));
    check('missing PDF -> 500 render_failed, no path leaked', $e?->statusCode === 500 && $e?->errorCode === 'render_failed' && leaksNothing($e), $e?->getMessage() ?? 'no exception');

    $bad = tempBook(null);
    mkdir("$STORAGE/$bad", 0755, true);
    file_put_contents("$STORAGE/$bad/book.pdf", "%PDF-1.4\nthis is not a pdf\n");
    [$e] = attempt(fn () => PdfPageRenderer::pageCount($bad));
    check('corrupt PDF -> 500 render_failed, no stderr leaked', $e?->statusCode === 500 && $e?->errorCode === 'render_failed' && leaksNothing($e), $e?->getMessage() ?? 'no exception');
    $db->exec("UPDATE books SET page_count = 2 WHERE id = $bad");
    [$e] = attempt(fn () => PdfPageRenderer::pagePath($bad, 1));
    check('render of corrupt PDF -> 500 render_failed, no jpg, no temp files (only lock + failure marker)', $e?->errorCode === 'render_failed' && leaksNothing($e)
        && array_values(array_diff(scandir("$STORAGE/$bad/pages"), ['.', '..', '1.lock', '1.failed'])) === []);

    // ---- negative cache: a failed page is not re-rendered for FAIL_TTL, even once the PDF is fine
    copy($fixture, "$STORAGE/$bad/book.pdf");
    [$e] = attempt(fn () => PdfPageRenderer::pagePath($bad, 1));
    check('failed page remembered: fixed PDF still render_failed within TTL, no jpg', $e?->errorCode === 'render_failed' && !is_file("$STORAGE/$bad/pages/1.jpg"));
    PdfPageRenderer::invalidate($bad);
    $db->exec("UPDATE books SET page_count = NULL WHERE id = $bad");
    check('invalidate clears failure markers (info + page); page renders again', !is_file("$STORAGE/$bad/info.failed") && !is_file("$STORAGE/$bad/pages/1.failed")
        && is_file(PdfPageRenderer::pagePath($bad, 1)));

    // ---- digit validation: no trailing newline, no leading zero
    foreach (["12\n", "1\n", '01', '0', '007'] as $raw) {
        check('positiveInt(' . json_encode($raw) . ') -> 404 not_found', rejected(fn () => ReaderController::positiveInt($raw), 404, 'not_found'));
    }
    check('positiveInt("999999999") === 999999999', ReaderController::positiveInt('999999999') === 999999999);

    // ---- crafted PDFs (generated here, a few hundred bytes each)
    $title = tempBook(null);
    mkdir("$STORAGE/$title", 0755, true);
    makePdf("$STORAGE/$title/book.pdf", '0 0 200 300', 1, 'x\nPages: 9999');
    $raw = (string) shell_exec('pdfinfo ' . escapeshellarg("$STORAGE/$title/book.pdf") . ' 2>/dev/null');
    check('fixture: Title injects a "Pages: 9999" line into pdfinfo output', (bool) preg_match('/^Pages:\s+9999$/m', $raw));
    check('Title "Pages: 9999" injection ignored -> pageCount 1', PdfPageRenderer::pageCount($title) === 1);

    $zero = tempBook(null);
    mkdir("$STORAGE/$zero", 0755, true);
    makePdf("$STORAGE/$zero/book.pdf", '0 0 200 300', 0);
    [$e] = attempt(fn () => PdfPageRenderer::pageCount($zero));
    check('zero-page PDF -> 500 render_failed, page_count stays NULL', $e?->errorCode === 'render_failed'
        && $db->query("SELECT page_count FROM books WHERE id = $zero")->fetchColumn() === null);

    $huge = tempBook(null);
    mkdir("$STORAGE/$huge", 0755, true);
    makePdf("$STORAGE/$huge/book.pdf", '0 0 14400 14400', 1);
    $t = microtime(true);
    [$e] = attempt(fn () => PdfPageRenderer::pagePath($huge, 1));
    check('oversize page (14400 pt MediaBox) -> render_failed before pdftoppm, no jpg', $e?->errorCode === 'render_failed' && leaksNothing($e)
        && !glob("$STORAGE/$huge/pages/*.jpg") && microtime(true) - $t < 5);
    makePdf("$STORAGE/$huge/book.pdf", '0 0 4900 4900', 1); // each side under 5000 pt, but ~56 MP at 110 dpi
    PdfPageRenderer::invalidate($huge);
    [$e] = attempt(fn () => PdfPageRenderer::pagePath($huge, 1));
    check('page over the pixel cap (4900 pt square) -> render_failed', $e?->errorCode === 'render_failed' && !glob("$STORAGE/$huge/pages/*.jpg"));

    // ---- wall-clock timeout: the child is SIGKILLed and reaped (no process, no zombie left)
    $t = microtime(true);
    [$e] = attempt(fn () => PdfPageRenderer::run(['sleep', '37.25'], 'fake slow tool', 1));
    $elapsed = microtime(true) - $t;
    check('slow tool -> render_failed after ~1 s timeout', $e?->errorCode === 'render_failed' && $elapsed >= 0.9 && $elapsed < 4, sprintf('%.1fs', $elapsed));
    $left = [];
    foreach (glob('/proc/[0-9]*/stat') ?: [] as $stat) {
        if (preg_match('/\((sleep|prlimit)\)/', (string) @file_get_contents($stat))) {
            $left[] = $stat;
        }
    }
    check('timed-out child killed and reaped (no sleep/prlimit process or zombie)', $left === [], implode(',', $left));
    check('PdfPageRenderer::TIMEOUT_SECONDS is 30', PdfPageRenderer::TIMEOUT_SECONDS === 30);

    // ---- busy lock: a second worker waits at most the lock budget, then 503 render_busy (no failure marker)
    $lockFh = fopen("$STORAGE/$id/pages/2.lock", 'c');
    flock($lockFh, LOCK_EX);
    PdfPageRenderer::$lockWaitSeconds = 1;
    $t = microtime(true);
    [$e] = attempt(fn () => PdfPageRenderer::pagePath($id, 2));
    $elapsed = microtime(true) - $t;
    check('page locked by another renderer -> 503 render_busy after ~1 s', $e?->statusCode === 503 && $e?->errorCode === 'render_busy' && $elapsed >= 0.9 && $elapsed < 3, sprintf('%.1fs', $elapsed));
    check('busy leaves no failure marker', !is_file("$STORAGE/$id/pages/2.failed"));
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    PdfPageRenderer::$lockWaitSeconds = PdfPageRenderer::LOCK_WAIT_SECONDS;
    check('lock released -> page renders', is_file(PdfPageRenderer::pagePath($id, 2)));

    // ---- re-upload: atomic replace (new inode) + invalidate removes pages, markers and render leftovers
    file_put_contents("$STORAGE/$id/pages/.tmp-3-deadbeef.jpg", 'x');
    touch("$STORAGE/$id/pages/3.failed");
    copy($fixture, "$STORAGE/$id/.tmp-upload-test.pdf");
    rename("$STORAGE/$id/.tmp-upload-test.pdf", "$STORAGE/$id/book.pdf");
    PdfPageRenderer::invalidate($id);
    $files = array_values(array_diff(scandir("$STORAGE/$id/pages"), ['.', '..']));
    check('invalidate removes jpgs, .failed markers and .tmp-* leftovers (locks only remain)', $files === ['1.lock', '2.lock', '3.lock'], implode(',', $files));
    check('after re-upload: page_count recomputed (3) and page 3 renders', PdfPageRenderer::pageCount($id) === 3 && is_file(PdfPageRenderer::pagePath($id, 3)));

    // ---- a cached page that vanished before sending -> JSON 503, never an empty image
    [$e] = attempt(fn () => ReaderController::sendJpeg("$STORAGE/$id/pages/999.jpg"));
    check('sendJpeg of a vanished file -> 503 render_busy (no image headers)', $e?->statusCode === 503 && $e?->errorCode === 'render_busy');
} catch (Throwable $e) {
    echo 'FAIL: unexpected ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    foreach ($bookIds as $bid) {
        rmTree("$STORAGE/$bid");
        $db->prepare('DELETE FROM books WHERE id = ?')->execute([$bid]);
    }
}

if ($failures > 0) {
    echo "\n{$failures} test(s) FAILED.\n";
    exit(1);
}
echo "\nAll reader tests passed.\n";
