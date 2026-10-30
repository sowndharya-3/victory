<?php

namespace App\Reader;

use App\Database\Db;
use App\Http\ApiException;

/**
 * Private PDF -> per-page JPEG cache for the secure reader.
 * Paths are built from integer ids only; the PDF and the page cache live under
 * storage/books/{id}/, outside the web root, and are served only by ReaderController.
 */
class PdfPageRenderer
{
    private const ROOT = __DIR__ . '/../../storage/books';
    private const DPI = '110';
    private const TIMEOUT_SECONDS = 30;

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

        $out = self::run(['pdfinfo', self::pdf($bookId)], "pdfinfo book $bookId");
        if (!preg_match('/^Pages:\s+(\d+)\s*$/m', $out, $m) || (int) $m[1] < 1) {
            self::fail("pdfinfo book $bookId: no page count");
        }
        $count = (int) $m[1];
        Db::connection()->prepare('UPDATE books SET page_count = ? WHERE id = ?')->execute([$count, $bookId]);
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
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            self::fail("mkdir pages book $bookId");
        }

        // One renderer per page: others wait on the lock, then find the finished file.
        $lock = @fopen("$dir/$page.lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            self::fail("lock page $page book $bookId");
        }
        try {
            clearstatcache(true, $target);
            if (is_file($target)) {
                return $target;
            }
            // pdftoppm appends ".jpg" to the prefix (-singlefile: no page-number suffix).
            $prefix = "$dir/.tmp-$page-" . bin2hex(random_bytes(6));
            try {
                self::run(['pdftoppm', '-jpeg', '-r', self::DPI, '-f', (string) $page, '-l', (string) $page, '-singlefile', self::pdf($bookId), $prefix], "pdftoppm book $bookId page $page");
                if (!is_file("$prefix.jpg") || !rename("$prefix.jpg", $target)) {
                    self::fail("pdftoppm book $bookId page $page: no output");
                }
            } finally {
                @unlink("$prefix.jpg");
            }
            return $target;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** A new PDF was uploaded: forget the page count and the rendered pages. */
    public static function invalidate(int $bookId): void
    {
        self::assertId($bookId);
        Db::connection()->prepare('UPDATE books SET page_count = NULL WHERE id = ?')->execute([$bookId]);
        // ponytail: a reader mid-render during a re-upload may cache one old page; re-upload again or clear pages/ if that matters.
        foreach (glob(self::ROOT . "/$bookId/pages/*.jpg") ?: [] as $file) {
            @unlink($file);
        }
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

    /** Argument-array proc_open (no shell), hard timeout; returns stdout or fails without leaking output. */
    private static function run(array $cmd, string $what): string
    {
        $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            self::fail("$what: spawn failed");
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
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

    private static function fail(string $logDetail): never
    {
        error_log("reader render_failed: $logDetail"); // ids and exit codes only, never paths or stderr
        throw new ApiException('Page could not be rendered', 500, 'render_failed');
    }
}
