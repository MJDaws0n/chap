<?php
/**
 * Chap - Main Entry Point
 * 
 * All requests are routed through this file
 */

// Enable error reporting in development
error_reporting(E_ALL);
ini_set('display_errors', getenv('APP_DEBUG') === 'true' ? '1' : '0');

// Start session
session_start();

// Autoload
require_once __DIR__ . '/../vendor/autoload.php';

use Chap\App;
use Chap\Database\Migrator;
use Chap\Router\Router;

// Bootstrap the application
$app = new App();
$app->boot();

// In development, auto-apply new migrations on request so schema changes
// don't require a container restart.
Migrator::autoMigrate(
    $app->getDb(),
    __DIR__ . '/../migrations',
    __DIR__ . '/../storage/cache/migrate.lock'
);

// Get the router
$router = $app->getRouter();

// Include routes
require_once __DIR__ . '/../routes.php';

// Handle method override for forms (DELETE, PUT, PATCH via POST with _method)
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && isset($_POST['_method'])) {
    $method = strtoupper($_POST['_method']);
}

// Dispatch the request
$router->dispatch($method, $_SERVER['REQUEST_URI']);
