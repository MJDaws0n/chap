<?php

namespace Chap\Middleware;

use Chap\Auth\AuthManager;

/**
 * Authentication Middleware
 */
class AuthMiddleware
{
    /**
     * Handle the middleware
     */
    public function handle(): bool
    {
        if (!AuthManager::check()) {
            if ($this->isApiRequest()) {
                json(['error' => 'Unauthorized'], 401);
            } else {
                redirect('/login');
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
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        
        return str_starts_with($uri, '/api/') || str_contains($accept, 'application/json');
    }
}
