<?php

namespace App\Support;

use App\Database\Db;
use App\Http\ApiException;

/** Fixed-window counter in rate_limits; throws 429 once a bucket exceeds $max in the window. */
class RateLimiter
{
    public static function hit(string $bucket, int $max, int $windowSeconds): void
    {
        self::record($bucket, $windowSeconds);
        if (self::hits($bucket, $windowSeconds) > $max) {
            throw new ApiException('Too many requests', 429, 'rate_limited');
        }
    }

    /**
     * Failure-only limits: hit() before the attempt (atomic, so parallel guesses can't overshoot),
     * then release() the slot when the attempt succeeded.
     */
    public static function release(string $bucket, int $windowSeconds): void
    {
        Db::connection()->prepare('UPDATE rate_limits SET hits = GREATEST(hits - 1, 0) WHERE bucket = ? AND window_start = ?')
            ->execute([$bucket, self::window($windowSeconds)]);
    }

    /**
     * Runs $attempt under failure-only limits ($buckets: bucket => max per window). A slot is reserved in every
     * bucket first and given back when the attempt succeeds or when a bucket was already full (no attempt made).
     */
    public static function failuresOnly(array $buckets, int $windowSeconds, callable $attempt): mixed
    {
        $reserved = [];
        try {
            foreach ($buckets as $bucket => $max) {
                self::hit($bucket, $max, $windowSeconds);
                $reserved[] = $bucket;
            }
            $result = $attempt();
        } catch (ApiException $e) {
            if ($e->errorCode === 'rate_limited') {
                array_map(fn ($b) => self::release($b, $windowSeconds), $reserved);
            }
            throw $e;
        }
        array_map(fn ($b) => self::release($b, $windowSeconds), $reserved);
        return $result;
    }

    private static function record(string $bucket, int $windowSeconds): void
    {
        Db::connection()->prepare('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1')
            ->execute([$bucket, self::window($windowSeconds)]);
    }

    private static function hits(string $bucket, int $windowSeconds): int
    {
        $read = Db::connection()->prepare('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?');
        $read->execute([$bucket, self::window($windowSeconds)]);
        return (int) $read->fetchColumn();
    }

    private static function window(int $windowSeconds): string
    {
        return date('Y-m-d H:i:s', intdiv(time(), $windowSeconds) * $windowSeconds);
    }
}
