<?php

namespace Chap\Middleware;

use Chap\Auth\AuthManager;

/**
 * Guest Only Middleware (redirect authenticated users)
 */
class GuestMiddleware
{
    /**
     * Handle the middleware
     */
    public function handle(): bool
    {
        if (AuthManager::check()) {
            redirect('/dashboard');
            return false;
        }
        return true;
    }
}
