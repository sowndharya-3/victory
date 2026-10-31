<?php

namespace App\Reader;

use App\Database\Db;
use App\Http\ApiException;

/**
 * Private PDF -> per-page JPEG cache for the secure reader.
 * Paths are built from integer ids only; the PDF and the page cache live under
 * storage/books/{id}/, outside the web root, and are served only by ReaderController.
 *
 * Limits (PHP-FPM runs only pm.max_children=5 workers, so nothing may hold one for long):
 * - every pdfinfo/pdftoppm run: TIMEOUT_SECONDS wall clock (SIGKILL + reap), and under prlimit
 *   (address space, CPU seconds, output file size) when prlimit exists;
 * - a request waits at most LOCK_WAIT_SECONDS for another worker rendering the same page, then 503 render_busy;
 * - a failed page / page count is remembered for FAIL_TTL seconds (*.failed marker) instead of re-spawning the tools;
 * - pages larger than MAX_SIDE_PT or MAX_PIXELS at DPI are refused, page counts outside 1..MAX_PAGES are refused.
 */
class PdfPageRenderer
{
    private const ROOT = __DIR__ . '/../../storage/books';
    private const DPI = 110;
    public const TIMEOUT_SECONDS = 30;
    public const LOCK_WAIT_SECONDS = 10;
    public const RETRY_AFTER_SECONDS = 5;
    private const FAIL_TTL = 60;
    private const MAX_PAGES = 5000;
    private const MAX_SIDE_PT = 5000;
    private const MAX_PIXELS = 12_000_000;
    private const LIMIT_AS_BYTES = 1024 * 1024 * 1024;
    private const LIMIT_FSIZE_BYTES = 64 * 1024 * 1024;

    /** Test seam only: how long pagePath() waits for another renderer's lock. */
    public static float $lockWaitSeconds = self::LOCK_WAIT_SECONDS;

    public static function pageCount(int $bookId): int
    {
        self::assertId($bookId);
        $stmt = Db::connection()->prepare('SELECT page_count FROM books WHERE id = ?');
        $stmt->execute([$bookId]);
        $cached = $stmt->fetchColumn();
        if ($cached === false) {
            throw new ApiException('Book not found', 404, 'not_found');
        }
        if ($cached !== null) {
            return (int) $cached;
        }

        $pdf = self::pdf($bookId);
        $marker = self::ROOT . "/$bookId/info.failed";
        self::assertNotRecentlyFailed($marker, "pdfinfo book $bookId");
        $sig = self::signature($pdf);
        try {
            $out = self::run(['pdfinfo', $pdf], "pdfinfo book $bookId");
            // Last anchored match: a crafted Title ("x\nPages: 9999") prints before the real line.
            if (!preg_match_all('/^Pages:\s+(\d{1,9})$/m', $out, $m)) {
                self::fail("pdfinfo book $bookId: no page count");
            }
            $count = (int) end($m[1]);
            if ($count < 1 || $count > self::MAX_PAGES) {
                self::fail("pdfinfo book $bookId: page count $count out of range");
            }
        } catch (ApiException $e) {
            if (self::signature($pdf) === $sig) {
                @touch($marker);
            }
            throw $e;
        }
        $db = Db::connection();
        // Only fill an empty cache, and only if the PDF we measured is still the one on disk (a re-upload
        // renames a new file in, then invalidate() nulls the column; re-check after writing to close the gap).
        $db->prepare('UPDATE books SET page_count = ? WHERE id = ? AND page_count IS NULL')->execute([$count, $bookId]);
        if (self::signature($pdf) !== $sig) {
            $db->prepare('UPDATE books SET page_count = NULL WHERE id = ? AND page_count = ?')->execute([$bookId, $count]);
            self::busy("pdfinfo book $bookId: PDF replaced during count");
        }
        return $count;
    }

    /** Absolute path of the cached JPEG for one page, rendering it on first request. */
    public static function pagePath(int $bookId, int $page): string
    {
        self::assertId($bookId);
        if ($page < 1 || $page > self::pageCount($bookId)) {
            throw new ApiException('Page not found', 404, 'not_found');
        }
        $dir = self::ROOT . "/$bookId/pages";
        $target = "$dir/$page.jpg";
        if (is_file($target)) {
            return $target;
        }
        $marker = "$dir/$page.failed";
        self::assertNotRecentlyFailed($marker, "page $page book $bookId");
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            self::fail("mkdir pages book $bookId");
        }

        // One renderer per page; others poll the lock briefly, then get 503 instead of holding an FPM worker.
        $lock = @fopen("$dir/$page.lock", 'c');
        if ($lock === false) {
            self::fail("lock page $page book $bookId");
        }
        try {
            $deadline = microtime(true) + self::$lockWaitSeconds;
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    self::busy("page $page book $bookId: lock wait");
                }
                usleep(200000);
            }
            clearstatcache(true, $target);
            if (is_file($target)) {
                return $target;
            }
            self::assertNotRecentlyFailed($marker, "page $page book $bookId");
            $pdf = self::pdf($bookId);
            $sig = self::signature($pdf);
            // pdftoppm appends ".jpg" to the prefix (-singlefile: no page-number suffix).
            $prefix = "$dir/.tmp-$page-" . bin2hex(random_bytes(6));
            try {
                self::assertRenderableSize($pdf, $bookId, $page);
                self::run(['pdftoppm', '-jpeg', '-r', (string) self::DPI, '-f', (string) $page, '-l', (string) $page, '-singlefile', $pdf, $prefix], "pdftoppm book $bookId page $page");
                if (self::signature($pdf) !== $sig) {
                    self::busy("pdftoppm book $bookId page $page: PDF replaced during render");
                }
                if (!is_file("$prefix.jpg") || !rename("$prefix.jpg", $target)) {
                    self::fail("pdftoppm book $bookId page $page: no output");
                }
                if (self::signature($pdf) !== $sig) { // replaced between the check and the rename
                    @unlink($target);
                    self::busy("pdftoppm book $bookId page $page: PDF replaced during render");
                }
            } catch (ApiException $e) {
                if ($e->errorCode === 'render_failed') {
                    @touch($marker);
                }
                throw $e;
            } finally {
                @unlink("$prefix.jpg");
            }
            return $target;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** A new PDF was uploaded: forget the page count, rendered pages, failure markers and render leftovers. */
    public static function invalidate(int $bookId): void
    {
        self::assertId($bookId);
        Db::connection()->prepare('UPDATE books SET page_count = NULL WHERE id = ?')->execute([$bookId]);
        $dir = self::ROOT . "/$bookId";
        // A render in flight re-checks the PDF signature before and after caching, so deleting under it is safe.
        foreach ([...(glob("$dir/pages/*.jpg") ?: []), ...(glob("$dir/pages/*.failed") ?: []), ...(glob("$dir/pages/.tmp-*") ?: []), "$dir/info.failed"] as $file) {
            @unlink($file);
        }
    }

    /**
     * Argument-array proc_open (no shell), hard wall-clock timeout (SIGKILL, then reaped by proc_close);
     * returns stdout or fails without leaking output. Public as a test seam (fake slow binaries).
     */
    public static function run(array $cmd, string $what, int $timeout = self::TIMEOUT_SECONDS): string
    {
        if (is_executable('/usr/bin/prlimit')) {
            // prlimit sets the limits on itself, then execs the tool: same pid, so the SIGKILL below reaches it.
            $cmd = ['/usr/bin/prlimit', '--as=' . self::LIMIT_AS_BYTES, '--cpu=' . $timeout, '--fsize=' . self::LIMIT_FSIZE_BYTES, '--', ...$cmd];
        }
        $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            self::fail("$what: spawn failed");
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $deadline = microtime(true) + $timeout;
        $exit = -1;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $w = $e = null;
            if (@stream_select($read, $w, $e, 0, 200000) > 0) {
                foreach ($read as $r) {
                    $chunk = (string) fread($r, 65536);
                    if ($r === $pipes[1] && strlen($out) < 65536) {
                        $out .= $chunk; // stderr is drained and dropped
                    }
                }
            }
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exit = $status['exitcode'];
                $out .= (string) stream_get_contents($pipes[1]);
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                self::fail("$what: timed out");
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        if ($exit !== 0) {
            self::fail("$what: exit $exit");
        }
        return $out;
    }

    /** Refuse pages whose JPEG at DPI would be huge (a 14400 pt MediaBox is ~22000 px a side). */
    private static function assertRenderableSize(string $pdf, int $bookId, int $page): void
    {
        $out = self::run(['pdfinfo', '-f', (string) $page, '-l', (string) $page, $pdf], "pdfinfo size book $bookId page $page");
        if (!preg_match_all('/^Page\s+' . $page . ' size:\s+(\d{1,9}(?:\.\d+)?) x (\d{1,9}(?:\.\d+)?) pts/m', $out, $m)) {
            self::fail("pdfinfo size book $bookId page $page: no size");
        }
        $w = (float) end($m[1]);
        $h = (float) end($m[2]);
        $pixels = ($w / 72 * self::DPI) * ($h / 72 * self::DPI);
        if ($w <= 0 || $h <= 0 || $w > self::MAX_SIDE_PT || $h > self::MAX_SIDE_PT || $pixels > self::MAX_PIXELS) {
            self::fail("pdfinfo size book $bookId page $page: {$w}x{$h} pt over the limit");
        }
    }

    private static function assertNotRecentlyFailed(string $marker, string $what): void
    {
        clearstatcache(true, $marker);
        $t = @filemtime($marker);
        if ($t !== false && time() - $t < self::FAIL_TTL) {
            self::fail("$what: failed recently, not retried");
        }
    }

    /** Identity of the PDF on disk: a re-upload renames a new file in (new inode). */
    private static function signature(string $pdf): string
    {
        clearstatcache(true, $pdf);
        $s = @stat($pdf);
        return $s === false ? '' : "{$s['ino']}:{$s['size']}:{$s['mtime']}";
    }

    private static function assertId(int $bookId): void
    {
        if ($bookId < 1) {
            throw new ApiException('Book not found', 404, 'not_found');
        }
    }

    private static function pdf(int $bookId): string
    {
        $pdf = self::ROOT . "/$bookId/book.pdf";
        if (!is_file($pdf)) {
            self::fail("book $bookId has no PDF");
        }
        return $pdf;
    }

    private static function busy(string $logDetail): never
    {
        error_log("reader render_busy: $logDetail");
        if (!headers_sent()) {
            header('Retry-After: ' . self::RETRY_AFTER_SECONDS);
        }
        throw new ApiException('The page is being prepared, please retry shortly', 503, 'render_busy');
    }

    private static function fail(string $logDetail): never
    {
        error_log("reader render_failed: $logDetail"); // ids and exit codes only, never paths or stderr
        throw new ApiException('Page could not be rendered', 500, 'render_failed');
    }
}
