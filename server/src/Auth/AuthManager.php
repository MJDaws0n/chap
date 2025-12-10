<?php

namespace Chap\Auth;

use Chap\App;
use Chap\Models\User;

/**
 * Authentication Manager
 */
class AuthManager
{
    private static ?User $user = null;
    private static bool $checked = false;

    /**
     * Attempt login with credentials
     */
    public static function attempt(string $email, string $password): bool
    {
        $db = App::db();
        
        $userData = $db->fetch(
            "SELECT * FROM users WHERE email = ? LIMIT 1",
            [$email]
        );

        if (!$userData) {
            return false;
        }

        if (!password_verify($password, $userData['password_hash'])) {
            return false;
        }

        // Create session
        self::createSession($userData);
        
        return true;
    }

    /**
     * Create session for user
     */
    private static function createSession(array $userData): void
    {
        $db = App::db();
        
        // Regenerate session ID for security
        session_regenerate_id(true);
        
        $sessionId = session_id();
        
        // Store session in database
        $db->query(
            "INSERT INTO sessions (id, user_id, ip_address, user_agent, payload, last_activity, created_at) 
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE last_activity = ?, ip_address = ?",
            [
                $sessionId,
                $userData['id'],
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                json_encode(['user_id' => $userData['id']]),
                time(),
                time(),
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]
        );

        // Store user ID in session
        $_SESSION['user_id'] = $userData['id'];
        $_SESSION['logged_in_at'] = time();
        
        // Load user
        self::$user = User::find($userData['id']);
        self::$checked = true;
    }

    /**
     * Check if user is authenticated
     */
    public static function check(): bool
    {
        if (!self::$checked) {
            self::loadUser();
        }
        return self::$user !== null;
    }

    /**
     * Get authenticated user
     */
    public static function user(): ?User
    {
        if (!self::$checked) {
            self::loadUser();
        }
        return self::$user;
    }

    /**
     * Get authenticated user ID
     */
    public static function id(): ?int
    {
        return self::user()?->id;
    }

    /**
     * Load user from session
     */
    private static function loadUser(): void
    {
        self::$checked = true;
        
        if (!isset($_SESSION['user_id'])) {
            return;
        }

        $db = App::db();
        
        // Verify session exists in database
        $session = $db->fetch(
            "SELECT * FROM sessions WHERE id = ? AND user_id = ?",
            [session_id(), $_SESSION['user_id']]
        );

        if (!$session) {
            self::logout();
            return;
        }

        // Check session expiry
        $lifetime = config('session.lifetime', 120) * 60;
        if (time() - $session['last_activity'] > $lifetime) {
            self::logout();
            return;
        }

        // Update last activity
        $db->query(
            "UPDATE sessions SET last_activity = ? WHERE id = ?",
            [time(), session_id()]
        );

        // Load user
        self::$user = User::find($_SESSION['user_id']);
    }

    /**
     * Login user directly
     */
    public static function login(User $user): void
    {
        $userData = [
            'id' => $user->id,
            'email' => $user->email,
            'username' => $user->username,
        ];
        self::createSession($userData);
    }

    /**
     * Login user by ID
     */
    public static function loginById(int $userId): bool
    {
        $user = User::find($userId);
        if ($user) {
            self::login($user);
            return true;
        }
        return false;
    }

    /**
     * Logout current user
     */
    public static function logout(): void
    {
        if (isset($_SESSION['user_id'])) {
            $db = App::db();
            $db->delete('sessions', 'id = ?', [session_id()]);
        }

        self::$user = null;
        self::$checked = false;
        
        $_SESSION = [];
        session_regenerate_id(true);
    }

    /**
     * Hash password
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Generate password reset token
     */
    public static function createPasswordResetToken(string $email): ?string
    {
        $db = App::db();
        
        $user = $db->fetch("SELECT id FROM users WHERE email = ?", [$email]);
        if (!$user) {
            return null;
        }

        $token = generate_token(32);
        $hashedToken = hash('sha256', $token);
        
        // Store token (expires in 1 hour)
        $db->query(
            "INSERT INTO password_resets (email, token, created_at) 
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE token = ?, created_at = NOW()",
            [$email, $hashedToken, $hashedToken]
        );

        return $token;
    }

    /**
     * Reset password with token
     */
    public static function resetPassword(string $token, string $email, string $newPassword): bool
    {
        $db = App::db();
        
        $hashedToken = hash('sha256', $token);
        
        $reset = $db->fetch(
            "SELECT * FROM password_resets 
             WHERE email = ? AND token = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            [$email, $hashedToken]
        );

        if (!$reset) {
            return false;
        }

        // Update password
        $db->update(
            'users',
            ['password_hash' => self::hashPassword($newPassword)],
            'email = ?',
            [$email]
        );

        // Delete reset token
        $db->delete('password_resets', 'email = ?', [$email]);

        return true;
    }
}
