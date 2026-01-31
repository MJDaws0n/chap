<?php

namespace Chap\Security;

/**
 * Minimal file-backed rate limiter.
 *
 * Notes:
 * - Designed for single-instance deployments. For multi-instance, use Redis or a shared store.
 * - Uses a fixed window counter.
 */
final class RateLimiter
{
    /**
     * @return array{allowed:bool,limit:int,remaining:int,retry_after:int}
     */
    public static function hit(string $bucket, string $key, int $limit, int $windowSeconds, ?string $storageDir = null): array
    {
        $limit = max(1, (int)$limit);
        $windowSeconds = max(1, (int)$windowSeconds);

        $now = time();
        $resetAt = $now + $windowSeconds;

        $base = $storageDir ?: (__DIR__ . '/../../storage/cache/ratelimit');
        if (!is_dir($base)) {
            @mkdir($base, 0777, true);
        }

        $safeBucket = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $bucket) ?: 'default';
        $hash = hash('sha256', $key);
        $file = rtrim($base, '/').'/'.$safeBucket.'-'.$hash.'.json';

        $count = 0;
        $windowEnd = $resetAt;

        $fp = @fopen($file, 'c+');
        if ($fp) {
            try {
                @flock($fp, LOCK_EX);
                $raw = stream_get_contents($fp);
                $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
                if (is_array($data) && isset($data['reset'], $data['count'])) {
                    $windowEnd = (int)$data['reset'];
                    $count = (int)$data['count'];
                }

                if ($windowEnd <= $now) {
                    // Reset window
                    $windowEnd = $resetAt;
                    $count = 0;
                }

                $count++;

                // Rewrite file
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode(['reset' => $windowEnd, 'count' => $count]));
                fflush($fp);
            } finally {
                @flock($fp, LOCK_UN);
                fclose($fp);
            }
        } else {
            // If we cannot write, fail open but report generous remaining.
            return [
                'allowed' => true,
                'limit' => $limit,
                'remaining' => $limit,
                'retry_after' => 0,
            ];
        }

        $allowed = $count <= $limit;
        $remaining = max(0, $limit - $count);
        $retryAfter = $allowed ? 0 : max(1, $windowEnd - $now);

        // Opportunistic cleanup: if window expired and we're over limit, delete.
        if ($windowEnd <= $now) {
            @unlink($file);
        }

        return [
            'allowed' => $allowed,
            'limit' => $limit,
            'remaining' => $remaining,
            'retry_after' => $retryAfter,
        ];
    }

    public static function clientIp(): string
    {
        // Default to REMOTE_ADDR. If you run behind a proxy, terminate TLS and set REMOTE_ADDR correctly,
        // or add your own trusted-proxy parsing.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ip = is_string($ip) ? trim($ip) : '';
        return $ip !== '' ? $ip : 'unknown';
    }
}
