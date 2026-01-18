<?php

namespace Chap;

/**
 * Configuration Manager
 */
class Config
{
    /**
     * Chap server version (displayed in Settings)
     *
     * Update this value when you release a new server version.
     */
    public const SERVER_VERSION = '1.0.0';

    private static array $config = [];
    private static bool $loaded = false;

    /**
     * Load configuration
     */
    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$config = [
            'app' => [
                'name' => env('APP_NAME', 'Chap'),
                'env' => env('APP_ENV', 'production'),
                'debug' => env('APP_DEBUG', false),
                'url' => env('APP_URL', 'http://localhost:8080'),
                'secret' => env('APP_SECRET', ''),
            ],
            'database' => [
                'host' => env('DB_HOST', 'localhost'),
                'port' => env('DB_PORT', 3306),
                'database' => env('DB_DATABASE', 'chap'),
                'username' => env('DB_USERNAME', 'chap'),
                'password' => env('DB_PASSWORD', ''),
            ],
            'session' => [
                'lifetime' => env('SESSION_LIFETIME', 120),
                'secure' => env('SESSION_SECURE', false),
            ],
            'websocket' => [
                'port' => env('WS_PORT', 8081),
            ],
            'github' => [
                'client_id' => env('GITHUB_CLIENT_ID', ''),
                'client_secret' => env('GITHUB_CLIENT_SECRET', ''),
                'redirect_uri' => env('GITHUB_REDIRECT_URI', ''),
            ],
            'upload' => [
                'max_size' => env('UPLOAD_MAX_SIZE', 104857600), // 100MB
                'allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            ],
            'captcha' => [
                // Provider: none | recaptcha | autogate
                'provider' => env('CAPTCHA_PROVIDER', 'none'),
                // Widget theme hint for providers that support it
                'theme' => env('CAPTCHA_THEME', 'dark'),
                'recaptcha' => [
                    'site_key' => env('RECAPTCHA_SITE_KEY', ''),
                    'secret_key' => env('RECAPTCHA_SECRET_KEY', ''),
                ],
                'autogate' => [
                    'public_key' => env('AUTOGATE_PUBLIC_KEY', ''),
                    'private_key' => env('AUTOGATE_PRIVATE_KEY', ''),
                ],
            ],
        ];

        self::$loaded = true;
    }

    /**
     * Get configuration value using dot notation
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        $keys = explode('.', $key);
        $value = self::$config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Set configuration value
     */
    public static function set(string $key, mixed $value): void
    {
        self::load();

        $keys = explode('.', $key);
        $config = &self::$config;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $config[$k] = $value;
            } else {
                if (!isset($config[$k]) || !is_array($config[$k])) {
                    $config[$k] = [];
                }
                $config = &$config[$k];
            }
        }
    }

    /**
     * Get all configuration
     */
    public static function all(): array
    {
        self::load();
        return self::$config;
    }
}
