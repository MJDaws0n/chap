<?php

namespace Chap\Controllers;

use Chap\Auth\AuthManager;

/**
 * Home Controller - Landing page
 */
class HomeController extends BaseController
{
    /**
     * Show landing page or redirect to dashboard
     */
    public function index(): void
    {
        // If logged in, redirect to dashboard
        if (AuthManager::check()) {
            redirect('/dashboard');
            return;
        }

        // Guest root should go straight to login.
        redirect('/login');
    }
}
