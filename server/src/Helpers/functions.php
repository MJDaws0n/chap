<?php
/**
 * Helper Functions
 */

if (!function_exists('env')) {
    /**
     * Get environment variable with default
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        
        if ($value === false) {
            return $default;
        }
        
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'null':
            case '(null)':
                return null;
            case 'empty':
            case '(empty)':
                return '';
        }
        
        return $value;
    }
}

if (!function_exists('config')) {
    /**
     * Get configuration value
     */
    function config(string $key, mixed $default = null): mixed
    {
        return \Chap\Config::get($key, $default);
    }
}

if (!function_exists('view')) {
    /**
     * Render a view template
     */
    function view(string $template, array $data = []): string
    {
        return \Chap\View\View::render($template, $data);
    }
}

if (!function_exists('redirect')) {
    /**
     * Redirect to URL
     */
    function redirect(string $url, int $status = 302): void
    {
        header("Location: $url", true, $status);
        exit;
    }
}

if (!function_exists('json')) {
    /**
     * Return JSON response
     */
    function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Generate or get CSRF token
     */
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Generate CSRF hidden field
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
    }
}

if (!function_exists('verify_csrf')) {
    /**
     * Verify CSRF token
     */
    function verify_csrf(string $token): bool
    {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('e')) {
    /**
     * Escape HTML entities
     */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8', false);
    }
}

if (!function_exists('uuid')) {
    /**
     * Generate UUID v4
     */
    function uuid(): string
    {
        $data = random_bytes(16);
        // Set version to 0100
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        // Set variant to 10xx
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('asset')) {
    /**
     * Get asset URL
     */
    function asset(string $path): string
    {
        $baseUrl = rtrim(env('APP_URL', ''), '/');
        return $baseUrl . '/assets/' . ltrim($path, '/');
    }
}

if (!function_exists('url')) {
    /**
     * Generate URL
     */
    function url(string $path = ''): string
    {
        $baseUrl = rtrim(env('APP_URL', ''), '/');
        return $baseUrl . '/' . ltrim($path, '/');
    }
}

if (!function_exists('request_base_url')) {
    /**
     * Best-effort base URL for the current request.
     *
     * Prefers APP_URL when configured, otherwise derives from request headers.
     */
    function request_base_url(): string
    {
        $configured = rtrim((string)env('APP_URL', ''), '/');
        if ($configured !== '') {
            return $configured;
        }

        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
        if (!is_string($proto) || $proto === '') {
            $https = $_SERVER['HTTPS'] ?? '';
            $proto = (!empty($https) && $https !== 'off') ? 'https' : 'http';
        }

        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (!is_string($host) || $host === '') {
            $host = 'localhost';
        }

        return $proto . '://' . $host;
    }
}

if (!function_exists('old')) {
    /**
     * Get old input value
     */
    function old(string $key, mixed $default = ''): mixed
    {
        return $_SESSION['_old_input'][$key] ?? $default;
    }
}

if (!function_exists('flash')) {
    /**
     * Set or get flash message
     */
    function flash(?string $key = null, ?string $message = null): mixed
    {
        if ($key === null) {
            $messages = $_SESSION['_flash'] ?? [];
            unset($_SESSION['_flash']);
            return $messages;
        }
        
        if ($message === null) {
            $value = $_SESSION['_flash'][$key] ?? null;
            unset($_SESSION['_flash'][$key]);
            return $value;
        }
        
        $_SESSION['_flash'][$key] = $message;
        return null;
    }
}

if (!function_exists('auth')) {
    /**
     * Get authenticated user
     */
    function auth(): ?\Chap\Models\User
    {
        return \Chap\Auth\AuthManager::user();
    }
}

if (!function_exists('is_admin')) {
    /**
     * Check if the current user is a site admin.
     */
    function is_admin(): bool
    {
        return (bool)(auth()?->is_admin ?? false);
    }
}

if (!function_exists('admin_view_all')) {
    /**
     * Whether the current admin has enabled "view all" mode.
     */
    function admin_view_all(): bool
    {
        if (!is_admin()) {
            return false;
        }
        return ($_SESSION['admin_view_mode'] ?? 'personal') === 'all';
    }
}

if (!function_exists('is_authenticated')) {
    /**
     * Check if user is authenticated
     */
    function is_authenticated(): bool
    {
        return \Chap\Auth\AuthManager::check();
    }
}

if (!function_exists('sanitize_filename')) {
    /**
     * Sanitize filename
     */
    function sanitize_filename(string $filename): string
    {
        // Remove any path components
        $filename = basename($filename);
        // Remove special characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        // Remove multiple dots
        $filename = preg_replace('/\.+/', '.', $filename);
        return $filename;
    }
}

if (!function_exists('format_bytes')) {
    /**
     * Format bytes to human readable
     */
    function format_bytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

if (!function_exists('time_ago')) {
    /**
     * Format timestamp to "time ago" string
     */
    function time_ago(string $datetime): string
    {
        $time = strtotime($datetime);
        $diff = time() - $time;
        
        if ($diff < 60) {
            return 'just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M j, Y', $time);
        }
    }
}

if (!function_exists('generate_token')) {
    /**
     * Generate secure random token
     */
    function generate_token(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }
}

if (!function_exists('slug')) {
    /**
     * Generate URL-friendly slug from string
     */
    function slug(string $text): string
    {
        // Convert to lowercase
        $text = strtolower($text);
        // Replace non-alphanumeric characters with dashes
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        // Remove leading/trailing dashes
        $text = trim($text, '-');
        // Remove multiple consecutive dashes
        $text = preg_replace('/-+/', '-', $text);
        return $text;
    }
}
