<?php

namespace Chap\Middleware;

use Chap\Security\RateLimiter;

/**
 * Rate limit incoming webhook receivers.
 */
class WebhookThrottleMiddleware
{
    public function handle(): bool
    {
        $ip = RateLimiter::clientIp();
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        // Bucket by IP + webhook UUID (best-effort).
        $key = 'webhook:' . $ip . ':' . (string)$uri;

        $limit = (int)env('RATE_LIMIT_WEBHOOK_PER_MIN', 120);
        $window = 60;

        $res = RateLimiter::hit('webhooks', $key, $limit, $window);

        header('X-RateLimit-Limit: ' . $res['limit']);
        header('X-RateLimit-Remaining: ' . $res['remaining']);

        if (!$res['allowed']) {
            header('Retry-After: ' . $res['retry_after']);
            json(['error' => 'Too Many Requests'], 429);
            return false;
        }

        return true;
    }
}
