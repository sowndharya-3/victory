<?php

spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

use App\Http\ApiException;

$failures = 0;

function assertTrue(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        echo "PASS: {$message}\n";
    } else {
        echo "FAIL: {$message}\n";
        $failures++;
    }
}

// Real-content MIME detection (bypassing the is_uploaded_file() gate, which
// only real HTTP uploads satisfy — tested over real HTTP in Task 10).
function detectMimeDirect(string $path): string
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $path);
    finfo_close($finfo);
    return $mime ?: 'application/octet-stream';
}

$realJpeg = __DIR__ . '/fixtures/test.jpg';
$realPdf = __DIR__ . '/fixtures/test.pdf';
$disguisedPdf = __DIR__ . '/fixtures/disguised.jpg'; // PDF bytes, .jpg name

assertTrue(detectMimeDirect($realJpeg) === 'image/jpeg', 'real JPEG sniffs as image/jpeg');
assertTrue(detectMimeDirect($realPdf) === 'application/pdf', 'real PDF sniffs as application/pdf');
assertTrue(detectMimeDirect($disguisedPdf) === 'application/pdf', 'PDF content renamed to .jpg still sniffs as application/pdf, not trusted by extension');

if ($failures > 0) {
    echo "\n{$failures} test(s) FAILED.\n";
    exit(1);
}
echo "\nAll upload validator tests passed.\n";
