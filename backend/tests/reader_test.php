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
    check('render of corrupt PDF -> 500 render_failed, no jpg, no temp files', $e?->errorCode === 'render_failed' && leaksNothing($e)
        && array_values(array_diff(scandir("$STORAGE/$bad/pages"), ['.', '..', '1.lock'])) === []);
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
