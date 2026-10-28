<?php

namespace App\Support;

use App\Database\Db;
use App\Http\ApiException;

/** Fixed-window counter in rate_limits; throws 429 once a bucket exceeds $max in the window. */
class RateLimiter
{
    public static function hit(string $bucket, int $max, int $windowSeconds): void
    {
        $db = Db::connection();
        $window = date('Y-m-d H:i:s', intdiv(time(), $windowSeconds) * $windowSeconds);
        $db->prepare('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1')
            ->execute([$bucket, $window]);
        $read = $db->prepare('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?');
        $read->execute([$bucket, $window]);
        if ((int) $read->fetchColumn() > $max) {
            throw new ApiException('Too many requests', 429, 'rate_limited');
        }
    }
}
