<?php

namespace Chap\Controllers;

use Chap\Auth\AuthManager;
use Chap\View\View;

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

        // Show landing page
        echo View::render('home/index', [
            'title' => 'Welcome'
        ], 'guest');
    }
}
