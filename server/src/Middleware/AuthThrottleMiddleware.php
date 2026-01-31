<?php

namespace Chap\Middleware;

use Chap\Security\RateLimiter;

/**
 * Rate limit authentication-sensitive public endpoints (login/register/MFA/password reset).
 */
class AuthThrottleMiddleware
{
    public function handle(): bool
    {
        $ip = RateLimiter::clientIp();
        $key = 'auth:' . $ip;

        $limit = (int)env('RATE_LIMIT_AUTH_PER_MIN', 20);
        $window = 60;

        $res = RateLimiter::hit('auth', $key, $limit, $window);

        header('X-RateLimit-Limit: ' . $res['limit']);
        header('X-RateLimit-Remaining: ' . $res['remaining']);

        if (!$res['allowed']) {
            header('Retry-After: ' . $res['retry_after']);
            http_response_code(429);

            $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            $isApi = str_starts_with((string)$uri, '/api/') || str_contains((string)$accept, 'application/json');
            if ($isApi) {
                json(['error' => 'Too Many Requests'], 429);
            }

            echo view('errors/429', ['title' => 'Too Many Requests']);
            return false;
        }

        return true;
    }
}
