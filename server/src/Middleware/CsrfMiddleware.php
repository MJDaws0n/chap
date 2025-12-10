<?php

namespace Chap\Middleware;

/**
 * CSRF Protection Middleware
 */
class CsrfMiddleware
{
    /**
     * Handle the middleware
     */
    public function handle(): bool
    {
        // Skip for GET, HEAD, OPTIONS requests
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'])) {
            return true;
        }

        // Skip for API requests with Bearer token
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($auth, 'Bearer ')) {
            return true;
        }

        // Get token from request
        $token = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        if (!verify_csrf($token)) {
            if ($this->isApiRequest()) {
                json(['error' => 'CSRF token mismatch'], 403);
            } else {
                http_response_code(403);
                echo "CSRF token mismatch";
            }
            return false;
        }

        return true;
    }

    /**
     * Check if request is API request
     */
    private function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return str_starts_with($uri, '/api/');
    }
}
