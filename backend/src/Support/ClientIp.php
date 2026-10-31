<?php

namespace App\Support;

/**
 * Client address for IP-based rate-limit buckets.
 *
 * TRUSTED_PROXIES (comma list of IPs/CIDRs, empty = default) names the only peers allowed to tell us the client IP.
 * X-Forwarded-For is read only when REMOTE_ADDR is one of them, walked right to left, and the first address that is
 * not itself a trusted proxy wins (left-most entries are whatever the client typed). Anything malformed falls back
 * to REMOTE_ADDR. IPv6 is bucketed per /64 (one home connection = one /64), IPv4-mapped IPv6 as plain IPv4.
 * Preferred alternative: fix REMOTE_ADDR in the web server (nginx set_real_ip_from / Apache mod_remoteip).
 */
final class ClientIp
{
    public static function bucket(?array $server = null, ?string $trustedProxies = null): string
    {
        $server ??= $_SERVER;
        $trustedProxies ??= (string) getenv('TRUSTED_PROXIES');

        $remote = self::normalise($server['REMOTE_ADDR'] ?? null);
        if ($remote === null) {
            return 'unknown';
        }
        $proxies = self::parseList($trustedProxies);
        $ip = $remote;
        $xff = $server['HTTP_X_FORWARDED_FOR'] ?? null;
        if ($proxies && is_string($xff) && $xff !== '' && self::inAny($remote, $proxies)) {
            foreach (array_reverse(explode(',', $xff)) as $hop) {
                $hop = self::normalise(trim($hop));
                if ($hop === null) {
                    break; // malformed chain: keep REMOTE_ADDR
                }
                if (!self::inAny($hop, $proxies)) {
                    $ip = $hop;
                    break;
                }
            }
        }
        return self::group($ip);
    }

    /** Valid entries of a TRUSTED_PROXIES value as [packedNetwork, prefixBits]; invalid ones are dropped. */
    public static function parseList(string $list): array
    {
        $out = [];
        foreach (explode(',', $list) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            [$addr, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $ip = self::normalise($addr);
            if ($ip === null) {
                continue;
            }
            $packed = inet_pton($ip);
            $max = strlen($packed) * 8;
            if ($bits === null) {
                $bits = $max;
            } elseif (ctype_digit($bits) && (int) $bits <= $max) {
                $bits = (int) $bits;
            } else {
                continue;
            }
            $out[] = [$packed, $bits];
        }
        return $out;
    }

    /** Count of entries in a TRUSTED_PROXIES value that are not valid IPs/CIDRs (for check_config). */
    public static function invalidEntries(string $list): int
    {
        $entries = array_filter(array_map('trim', explode(',', $list)), fn($e) => $e !== '');
        return count($entries) - count(self::parseList($list));
    }

    private static function inAny(string $ip, array $proxies): bool
    {
        $packed = inet_pton($ip);
        foreach ($proxies as [$net, $bits]) {
            if (strlen($net) !== strlen($packed)) {
                continue;
            }
            $bytes = intdiv($bits, 8);
            if (substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    /** Canonical address or null; IPv4-mapped IPv6 (::ffff:a.b.c.d) becomes a.b.c.d. */
    private static function normalise(mixed $ip): ?string
    {
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($ip);
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return inet_ntop(substr($packed, 12));
        }
        return inet_ntop($packed);
    }

    private static function group(string $ip): string
    {
        $packed = inet_pton($ip);
        if (strlen($packed) === 4) {
            return $ip;
        }
        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}
