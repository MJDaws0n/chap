<?php

namespace Chap;

use Chap\Database\Connection;
use Chap\Router\Router;
use Chap\Router\Route;

/**
 * Main Application Class
 */
class App
{
    private static ?App $instance = null;
    private Router $router;
    private Connection $db;

    public function __construct()
    {
        self::$instance = $this;
    }

    /**
     * Get application instance
     */
    public static function getInstance(): ?App
    {
        return self::$instance;
    }

    /**
     * Boot the application
     */
    public function boot(): void
    {
        // Load configuration
        Config::load();

        // Initialize database connection
        $this->db = new Connection();

        // Initialize router and set it for the Route facade
        $this->router = new Router();
        Route::setRouter($this->router);
    }

    /**
     * Get router instance
     */
    public function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * Get database connection
     */
    public function getDb(): Connection
    {
        return $this->db;
    }

    /**
     * Get database connection statically
     */
    public static function db(): Connection
    {
        return self::$instance->db;
    }
}
