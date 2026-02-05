<?php
/**
 * Chap - Main Entry Point
 * 
 * All requests are routed through this file
 */

// Enable error reporting in development
error_reporting(E_ALL);
ini_set('display_errors', getenv('APP_DEBUG') === 'true' ? '1' : '0');

// Session hardening (must run before session_start)
// NOTE: keep this bootstrap lightweight; helpers/autoload are not loaded yet.
$envBool = function (string $key, bool $default = false): bool {
    $v = getenv($key);
    if ($v === false || $v === '') return $default;
    $s = strtolower(trim((string)$v));
    if (in_array($s, ['1', 'true', 'yes', 'on'], true)) return true;
    if (in_array($s, ['0', 'false', 'no', 'off'], true)) return false;
    return $default;
};

$trustProxy = $envBool('TRUST_PROXY_HEADERS', false);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
if (!$https && $trustProxy) {
    $xfp = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($xfp === 'https') {
        $https = true;
    }
}

$secureCookie = $envBool('SESSION_SECURE', $https);
$sameSite = getenv('SESSION_SAMESITE');
$sameSite = is_string($sameSite) && $sameSite !== '' ? $sameSite : 'Lax';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $secureCookie ? '1' : '0');
ini_set('session.cookie_samesite', $sameSite);

$lifetimeMinutes = (int)(getenv('SESSION_LIFETIME') ?: 120);
// When using "remember me" we need PHP's session GC to retain session data long enough,
// otherwise the browser cookie can outlive the server-side session storage.
$rememberDays = (int)(getenv('SESSION_REMEMBER_LIFETIME_DAYS') ?: 30);
$rememberLifetime = max(0, $rememberDays) * 86400;

$gcLifetime = max(300, $lifetimeMinutes * 60, $rememberLifetime);
ini_set('session.gc_maxlifetime', (string)$gcLifetime);

// Start session
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => $sameSite,
]);
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
