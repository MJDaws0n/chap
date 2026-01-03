<?php

namespace Chap\Middleware;

use Chap\Auth\AuthManager;

/**
 * Site Admin Middleware
 */
class AdminMiddleware
{
    public function handle(): bool
    {
        $user = AuthManager::user();

        if (!$user || !$user->is_admin) {
            if ($this->isApiRequest()) {
                json(['error' => 'Admin access required'], 403);
            } else {
                flash('error', 'Admin access required');
                redirect('/dashboard');
            }
            return false;
        }

        return true;
    }

    private function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        return str_starts_with($uri, '/api/') || str_contains($accept, 'application/json');
    }
}
