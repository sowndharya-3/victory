<?php
// Unit tests for App\Support\ClientIp (rate-limit client address). No DB.
spl_autoload_register(function (string $c) {
    if (str_starts_with($c, 'App\\')) {
        $p = __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, 4)) . '.php';
        if (file_exists($p)) {
            require $p;
        }
    }
});

use App\Support\ClientIp;

$pass = 0;
$fail = 0;
function check(string $name, string $expected, string $actual): void
{
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        echo "PASS: $name\n";
    } else {
        $fail++;
        echo "FAIL: $name (expected '$expected', got '$actual')\n";
    }
}
$b = fn(string $remote, ?string $xff, string $trusted) => ClientIp::bucket(
    array_filter(['REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => $xff], fn($v) => $v !== null),
    $trusted
);

// Default (TRUSTED_PROXIES empty): today's behaviour, the header never counts.
check('no proxy config', '203.0.113.9', $b('203.0.113.9', null, ''));
check('spoof: header ignored when no proxy is trusted', '203.0.113.9', $b('203.0.113.9', '1.2.3.4', ''));
check('spoof: header ignored when the request is not from a trusted proxy', '203.0.113.9', $b('203.0.113.9', '1.2.3.4', '10.0.0.1'));
check('missing REMOTE_ADDR', 'unknown', ClientIp::bucket([], ''));
check('garbage REMOTE_ADDR', 'unknown', ClientIp::bucket(['REMOTE_ADDR' => 'nope'], ''));

// Trusted proxy.
check('trusted proxy, one hop', '198.51.100.7', $b('10.0.0.1', '198.51.100.7', '10.0.0.1'));
check('trusted proxy, spoofed left-most entry ignored', '198.51.100.7', $b('10.0.0.1', '6.6.6.6, 198.51.100.7', '10.0.0.1'));
check('trusted chain: skips trusted hops right to left', '198.51.100.7', $b('10.0.0.1', '6.6.6.6, 198.51.100.7, 10.0.0.2', '10.0.0.0/8'));
check('trusted proxy, no header -> REMOTE_ADDR', '10.0.0.1', $b('10.0.0.1', null, '10.0.0.1'));
check('trusted proxy, garbage header -> REMOTE_ADDR', '10.0.0.1', $b('10.0.0.1', 'not-an-ip', '10.0.0.1'));
check('trusted proxy, malformed right-most hop -> REMOTE_ADDR', '10.0.0.1', $b('10.0.0.1', '198.51.100.7, 1.2.3', '10.0.0.1'));
check('trusted proxy, empty hop -> REMOTE_ADDR', '10.0.0.1', $b('10.0.0.1', '198.51.100.7,', '10.0.0.1'));
check('trusted proxy, ip:port hop is malformed -> REMOTE_ADDR', '10.0.0.1', $b('10.0.0.1', '198.51.100.7:443', '10.0.0.1'));
check('every hop trusted -> REMOTE_ADDR', '10.0.0.1', $b('10.0.0.1', '10.0.0.3, 10.0.0.2', '10.0.0.0/8'));

// CIDR matching.
check('CIDR /12 contains 172.18.0.5', '198.51.100.7', $b('172.18.0.5', '198.51.100.7', '172.16.0.0/12'));
check('CIDR /12 excludes 172.32.0.1', '172.32.0.1', $b('172.32.0.1', '198.51.100.7', '172.16.0.0/12'));
check('CIDR /31 non-byte boundary in', '198.51.100.7', $b('192.0.2.1', '198.51.100.7', '192.0.2.0/31'));
check('CIDR /31 non-byte boundary out', '192.0.2.2', $b('192.0.2.2', '198.51.100.7', '192.0.2.0/31'));
check('CIDR /0 trusts every hop too -> REMOTE_ADDR', '8.8.8.8', $b('8.8.8.8', '198.51.100.7', '0.0.0.0/0'));
check('list with spaces and invalid entries', '198.51.100.7', $b('10.1.2.3', '198.51.100.7', ' bogus , 10.0.0.0/33, 10.1.0.0/16 '));
check('invalid entries only -> nothing trusted', '10.1.2.3', $b('10.1.2.3', '198.51.100.7', 'bogus, 10.0.0.0/33, 10.0.0.0/x'));
check('invalidEntries counts bad items', '3', (string) ClientIp::invalidEntries('bogus, 10.0.0.0/33, 10.0.0.0/x, 10.0.0.1, ::1/128'));

// IPv6.
check('IPv6 client grouped by /64', '2001:db8:1:2::/64', $b('2001:db8:1:2:aaaa:bbbb:cccc:dddd', null, ''));
check('IPv6 same /64 same bucket', '2001:db8:1:2::/64', $b('2001:db8:1:2::1', null, ''));
check('IPv6 different /64 different bucket', '2001:db8:1:3::/64', $b('2001:db8:1:3::1', null, ''));
check('IPv6 trusted proxy CIDR, v6 client from header', '2001:db8:9::/64', $b('fd00::5', '2001:db8:9:0:1::2', 'fd00::/8'));
check('IPv6 proxy not trusted -> header ignored', 'fd00::/64', $b('fd00::5', '2001:db8:9::2', '10.0.0.0/8'));
check('IPv4-mapped IPv6 REMOTE_ADDR -> IPv4', '198.51.100.7', $b('::ffff:198.51.100.7', null, ''));
check('IPv4-mapped IPv6 proxy matches IPv4 CIDR', '203.0.113.5', $b('::ffff:10.0.0.1', '203.0.113.5', '10.0.0.0/8'));
check('IPv4-mapped hop in header -> IPv4', '203.0.113.5', $b('10.0.0.1', '::ffff:203.0.113.5', '10.0.0.0/8'));

// Environment variable is the default source.
putenv('TRUSTED_PROXIES=10.0.0.1');
check('TRUSTED_PROXIES env read by default', '198.51.100.7', ClientIp::bucket(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7']));
putenv('TRUSTED_PROXIES');
check('TRUSTED_PROXIES unset -> header ignored', '10.0.0.1', ClientIp::bucket(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7']));

echo "\nResults: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
