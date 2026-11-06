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

use App\Controllers\ContactController;
use App\Database\Db;
use App\Http\ApiException;
use App\Mail\Mailer;
use App\Mail\SmtpMailer;

$failures = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $failures;
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $name . ($ok || $extra === '' ? '' : " ($extra)") . "\n";
    if (!$ok) {
        $failures++;
    }
}

/** Test double: records messages, or throws to simulate a delivery failure. */
class StubMailer implements Mailer
{
    public array $sent = [];
    public function __construct(public bool $fail = false)
    {
    }
    public function send(string $subject, string $body, string $replyToEmail, string $replyToName): void
    {
        if ($this->fail) {
            throw new RuntimeException('smtp unexpected reply: 535 Authentication failed');
        }
        $this->sent[] = compact('subject', 'body', 'replyToEmail', 'replyToName');
    }
}

/** @return string "code|status|json fields" of an ApiException, "ok" when none. */
function run(array $data, StubMailer $m, string $bucket): string
{
    try {
        ContactController::handle($data, fn () => $m, $bucket);
        return 'ok';
    } catch (ApiException $e) {
        return $e->errorCode . '|' . $e->statusCode . '|' . json_encode($e->fields);
    }
}

$tag = 'ct' . getmypid() . time();
$good = ['name' => 'Asha K', 'email' => 'asha@example.com', 'phone' => '+91 98765 43210', 'subject' => 'Bulk order', 'message' => 'Hello, I would like a quote for 50 copies.', 'website' => ''];
$db = Db::connection();

try {
    // --- validation (field by field) ---
    $m = new StubMailer();
    $cases = [
        'name too short' => [['name' => 'A'], 'name'],
        'name 101 chars' => [['name' => str_repeat('a', 101)], 'name'],
        'name missing' => [['name' => null], 'name'],
        'name not a string' => [['name' => ['x']], 'name'],
        'email invalid' => [['email' => 'not-an-email'], 'email'],
        'email missing' => [['email' => null], 'email'],
        'email over 254' => [['email' => str_repeat('a', 250) . '@example.com'], 'email'],
        'message 9 chars' => [['message' => '123456789'], 'message'],
        'message 5001 chars' => [['message' => str_repeat('m', 5001)], 'message'],
        'phone with letters' => [['phone' => '98765abcde'], 'phone'],
        'phone 6 digits' => [['phone' => '123456'], 'phone'],
        'phone 21 chars' => [['phone' => str_repeat('1', 21)], 'phone'],
        'subject 151 chars' => [['subject' => str_repeat('s', 151)], 'subject'],
    ];
    foreach ($cases as $label => [$patch, $field]) {
        $data = array_filter(array_merge($good, $patch), fn ($v) => $v !== null);
        $r = run($data, $m, "$tag-v");
        check("$label -> 400 validation_error with fields.$field", str_starts_with($r, 'validation_error|400|') && isset(json_decode(substr($r, 21), true)[$field]), $r);
    }
    check('nothing sent for invalid input', $m->sent === []);
    check('boundaries accepted: name 2, message 10, phone 7, optional phone/subject omitted', run(['name' => 'Al', 'email' => 'a@example.com', 'message' => '1234567890'], $m, "$tag-b1") === 'ok'
        && run(['name' => str_repeat('n', 100), 'email' => 'a@example.com', 'message' => str_repeat('m', 5000), 'phone' => '1234567'], $m, "$tag-b2") === 'ok');
    check('Tamil name and message accepted by character count', run(['name' => 'அஆ', 'email' => 'a@example.com', 'message' => str_repeat('அ', 10)], $m, "$tag-b3") === 'ok');

    // --- honeypot ---
    $m = new StubMailer();
    check('honeypot filled -> silent success, nothing sent', run(['website' => 'http://spam.example'] + $good, $m, "$tag-h") === 'ok' && $m->sent === []);
    check('honeypot filled even with invalid fields -> silent success, nothing sent', run(['website' => 'x', 'name' => ''], $m, "$tag-h") === 'ok' && $m->sent === []);
    check('honeypot non-string value -> silent success, nothing sent', run(['website' => ['x']] + $good, $m, "$tag-h") === 'ok' && $m->sent === []);
    check('honeypot does not use the rate limit', (int) $db->query("SELECT COUNT(*) FROM rate_limits WHERE bucket = 'contact:$tag-h'")->fetchColumn() === 0);
    check('empty / whitespace honeypot is a normal submission', run(['website' => '  '] + $good, $m, "$tag-h2") === 'ok' && count($m->sent) === 1);

    // --- header injection ---
    $m = new StubMailer();
    $evil = ['name' => "Bob\r\nBcc: evil@example.com", 'subject' => "Hi\r\nBcc: evil@example.com\nX-Y: 1", 'message' => "Line one is long enough\r\nsecond line\r\n\r\n.dot line"] + $good;
    check('CR/LF in name/subject accepted but flattened', run($evil, $m, "$tag-i") === 'ok');
    $s = $m->sent[0];
    check('subject and reply-to name carry no CR/LF', !preg_match('/[\r\n]/', $s['subject'] . $s['replyToName']), json_encode($s['subject']));
    check('message keeps its line breaks, never CR', str_contains($s['body'], "second line\n\n.dot line") && !str_contains($s['body'], "\r"));
    $r = run(['email' => "a@example.com\r\nBcc: evil@example.com"] + $good, $m, "$tag-i2");
    check('CR/LF in email -> 400 (never reaches a header)', str_starts_with($r, 'validation_error|400|'), $r);
    check('exactly one message was sent so far', count($m->sent) === 1);

    // --- SMTP client against a scripted peer (helpers/fake_smtp.php): protocol, AUTH LOGIN, From/Reply-To, dot-safe body ---
    $transcript = tempnam(sys_get_temp_dir(), 'smtp');
    $peer = proc_open([PHP_BINARY, __DIR__ . '/helpers/fake_smtp.php', $transcript], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $port = (int) trim((string) fgets($pipes[1]));
    $mail = new SmtpMailer('127.0.0.1', $port, 'none', 'smtpuser', 'smtp-pass', 'shop@example.com', 'admin@example.com');
    $mail->send("Hello\r\nBcc: evil@example.com", "first\n.\nthird line", 'visitor@example.com', "Evil\" <x@y>\r\nBcc: z@example.com");
    proc_close($peer);
    $t = (string) file_get_contents($transcript);
    unlink($transcript);
    $afterData = substr($t, (int) strpos($t, "DATA\r\n") + 6);
    $headers = explode("\r\n\r\n", $afterData, 2)[0];
    check('SMTP: EHLO, AUTH LOGIN with base64 user/pass, MAIL FROM = MAIL_FROM, RCPT TO = admin', str_contains($t, 'EHLO ') && str_contains($t, "AUTH LOGIN\r\n" . base64_encode('smtpuser') . "\r\n" . base64_encode('smtp-pass') . "\r\n") && str_contains($t, "MAIL FROM:<shop@example.com>\r\nRCPT TO:<admin@example.com>\r\nDATA\r\n"));
    check('SMTP: From is MAIL_FROM, visitor only in Reply-To', str_contains($headers, "From: shop@example.com\r\n") && preg_match('/^Reply-To: .*<visitor@example\.com>\r?$/m', $headers) === 1 && !str_contains($headers, 'From: visitor'), $headers);
    check('SMTP: injected subject/name cannot add a header line', preg_match('/^Bcc:/mi', $headers) === 0, $headers);
    check('SMTP: body is base64, so a lone "." line cannot end DATA early; one terminator', preg_match('/\r\n\.\r\nQUIT\r\n$/', $t) === 1 && substr_count($t, "\r\n.\r\n") === 1);
    $bodyPart = (string) strstr(explode("\r\n\r\n", $afterData, 2)[1] ?? '', "\r\n.\r\n", true);
    check('SMTP: body decodes back intact', base64_decode((string) preg_replace('/\s+/', '', $bodyPart)) === "first\n.\nthird line");

    // --- rate limit: 5 per hour per IP bucket, 6th -> 429 ---
    $m = new StubMailer();
    $rl = [];
    for ($i = 0; $i < 6; $i++) {
        $rl[] = run($good, $m, "$tag-r");
    }
    check('5 submissions ok, the 6th -> 429 rate_limited, 5 sent', array_slice($rl, 0, 5) === array_fill(0, 5, 'ok') && str_starts_with($rl[5], 'rate_limited|429|') && count($m->sent) === 5, implode(',', $rl));
    check('another IP bucket is unaffected', run($good, $m, "$tag-r-other") === 'ok');
    check('invalid submissions do not use the budget', (function () use ($m, $good, $tag) {
        for ($i = 0; $i < 8; $i++) {
            run(['name' => ''] + $good, $m, "$tag-r2");
        }
        return run($good, $m, "$tag-r2") === 'ok';
    })());

    // --- delivery failure -> 502, never success ---
    $r = run($good, new StubMailer(true), "$tag-f");
    check('mailer failure -> 502 email_failed', str_starts_with($r, 'email_failed|502|'), $r);
    try {
        ContactController::handle($good, fn () => new StubMailer(true), "$tag-f2");
    } catch (ApiException $e) {
        check('502 message is the generic one', $e->getMessage() === 'Could not send your message. Please try again later or email us directly.');
    }
    $r = '';
    try {
        ContactController::handle($good, function () {
            putenv('SMTP_HOST=');
            return SmtpMailer::fromEnv();
        }, "$tag-f3");
    } catch (ApiException $e) {
        $r = $e->errorCode . '|' . $e->statusCode;
    }
    check('missing mail configuration -> 502 email_failed, not 500', $r === 'email_failed|502', $r);
    // Real SMTP client against a closed port: connect failure surfaces as 502 and the log line holds no body/secret.
    $logFile = tempnam(sys_get_temp_dir(), 'ctlog');
    $oldLog = ini_set('error_log', $logFile);
    $dead = stream_socket_server('tcp://127.0.0.1:0');
    $deadPort = (int) substr(strrchr(stream_socket_get_name($dead, false), ':'), 1);
    fclose($dead);
    $secretPass = 'S3cretPassw0rd-' . $tag;
    $r = '';
    try {
        ContactController::handle($good, fn () => new SmtpMailer('127.0.0.1', $deadPort, 'none', 'user', $secretPass, 'shop@example.com', 'admin@example.com'), "$tag-f5");
    } catch (ApiException $e) {
        $r = $e->errorCode . '|' . $e->statusCode;
    }
    ini_set('error_log', $oldLog ?: '');
    $log = (string) file_get_contents($logFile);
    unlink($logFile);
    check('real SmtpMailer to a closed port -> 502 email_failed', $r === 'email_failed|502', $r);
    check('failure is logged without credentials, message body or visitor email', str_contains($log, 'contact: email delivery failed') && !str_contains($log, $secretPass) && !str_contains($log, 'quote for 50') && !str_contains($log, 'asha@example.com'), $log);
} catch (Throwable $e) {
    echo 'FAIL: unexpected ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    $db->prepare("DELETE FROM rate_limits WHERE bucket LIKE ?")->execute(["contact:$tag%"]);
}

echo $failures ? "\n$failures test(s) FAILED.\n" : "\nAll contact tests passed.\n";
exit($failures ? 1 : 0);
