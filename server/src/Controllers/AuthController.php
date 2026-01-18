<?php

namespace Chap\Controllers;

use Chap\Auth\AuthManager;
use Chap\Models\User;
use Chap\Models\ActivityLog;
use Chap\Auth\OAuth\GitHubProvider;
use Chap\Services\Mailer;
use Chap\Security\Captcha\CaptchaVerifier;

/**
 * Authentication Controller
 */
class AuthController extends BaseController
{
    /**
     * Show login form
     */
    public function showLogin(): void
    {
        $this->view('auth/login', [
            'title' => 'Login'
        ], 'auth');
    }

    /**
     * Handle login
     */
    public function login(): void
    {
        // Verify CSRF
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request. Please try again.');
            $this->redirect('/login');
        }

        $email = trim($this->input('email', ''));
        $password = $this->input('password', '');
        $remember = $this->input('remember') === 'on';

        if (empty($email) || empty($password)) {
            flash('error', 'Please enter email and password');
            $this->redirect('/login');
        }

        if (!CaptchaVerifier::verify($_POST, $_SERVER['REMOTE_ADDR'] ?? null)) {
            flash('error', 'Please complete the captcha verification.');
            $_SESSION['_old_input'] = ['email' => $email];
            $this->redirect('/login');
        }

        $user = AuthManager::verifyCredentials($email, $password);

        if ($user && (bool)$user->two_factor_enabled) {
            // Store a short-lived MFA challenge in the session.
            $_SESSION['mfa_pending_user_id'] = (int)$user->id;
            $_SESSION['mfa_pending_started_at'] = time();
            $_SESSION['mfa_pending_remember'] = $remember ? 1 : 0;
            $_SESSION['_old_input'] = ['email' => $email];
            $this->redirect('/mfa');
        }

        if ($user) {
            AuthManager::login($user);
            // Log activity
            ActivityLog::log('user.login');

            // Handle remember me - extend session cookie
            if ($remember) {
                $lifetime = time() + (60 * 60 * 24 * 30); // 30 days from now
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    session_id(),
                    $lifetime,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }

            flash('success', 'Welcome back!');
            $this->redirect('/dashboard');
        } else {
            flash('error', 'Invalid email or password');
            $_SESSION['_old_input'] = ['email' => $email];
            $this->redirect('/login');
        }
    }

    /**
     * Show registration form
     */
    public function showRegister(): void
    {
        $this->view('auth/register', [
            'title' => 'Create Account'
        ], 'auth');
    }

    /**
     * Handle registration
     */
    public function register(): void
    {
        // Verify CSRF
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request. Please try again.');
            $this->redirect('/register');
        }

        $name = trim($this->input('name', ''));
        $username = trim($this->input('username', ''));
        $email = trim($this->input('email', ''));
        $password = $this->input('password', '');
        $passwordConfirmation = $this->input('password_confirmation', '');

        if (!CaptchaVerifier::verify($_POST, $_SERVER['REMOTE_ADDR'] ?? null)) {
            flash('error', 'Please complete the captcha verification.');
            $this->redirect('/register');
        }

        // Validation
        $errors = [];

        if (empty($name)) {
            $errors['name'] = 'Name is required';
        }

        if (empty($username)) {
            $errors['username'] = 'Username is required';
        } elseif (!preg_match('/^[a-zA-Z0-9_-]{3,30}$/', $username)) {
            $errors['username'] = 'Username must be 3-30 characters (letters, numbers, - _)';
        } elseif (User::findByUsername($username)) {
            $errors['username'] = 'Username already taken';
        }

        if (empty($email)) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email address';
        } elseif (User::findByEmail($email)) {
            $errors['email'] = 'Email already registered';
        }

        if (empty($password)) {
            $errors['password'] = 'Password is required';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        } elseif ($password !== $passwordConfirmation) {
            $errors['password'] = 'Passwords do not match';
        }

        if (!empty($errors)) {
            $_SESSION['_errors'] = $errors;
            $_SESSION['_old_input'] = ['name' => $name, 'username' => $username, 'email' => $email];
            $this->redirect('/register');
        }

        // Create user
        $user = User::create([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password_hash' => AuthManager::hashPassword($password),
            'is_admin' => User::count() === 0, // First user is admin
        ]);

        // Create personal team
        $user->createPersonalTeam();

        // Log in user
        AuthManager::login($user);

        // Log activity
        ActivityLog::log('user.register');

        flash('success', 'Welcome to Chap! Your account has been created.');
        $this->redirect('/dashboard');
    }

    /**
     * Handle logout
     */
    public function logout(): void
    {
        ActivityLog::log('user.logout');
        AuthManager::logout();
        flash('success', 'You have been logged out');
        $this->redirect('/login');
    }

    /**
     * Show forgot password form
     */
    public function showForgotPassword(): void
    {
        $this->view('auth/forgot-password', [
            'title' => 'Forgot Password'
        ], 'auth');
    }

    /**
     * Handle forgot password request
     */
    public function forgotPassword(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/forgot-password');
        }

        $email = trim($this->input('email', ''));

        if (empty($email)) {
            flash('error', 'Please enter your email address');
            $this->redirect('/forgot-password');
        }

        // If SMTP isn't configured, we can't send anything.
        if (!Mailer::isConfigured()) {
            flash('error', 'Email is not configured. Please ask an admin to configure SMTP in Admin Settings.');
            $this->redirect('/forgot-password');
        }

        $token = AuthManager::createPasswordResetToken($email);

        // Always show success message to prevent email enumeration
        flash('success', 'If an account exists with that email, a password reset email has been sent.');

        if ($token) {
            $resetUrl = request_base_url() . '/reset-password/' . urlencode($token) . '?email=' . urlencode($email);

            try {
                $user = User::findByEmail($email);
                $greetingName = $user?->name ?: ($user?->username ?: 'there');

                $subject = 'Reset your Chap password';
                $html = '<p>Hi ' . htmlspecialchars((string)$greetingName, ENT_QUOTES) . ',</p>'
                    . '<p>We received a request to reset your password.</p>'
                    . '<p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES) . '">Reset Password</a></p>'
                    . '<p>This link will expire in 1 hour.</p>'
                    . '<p>If you did not request this, you can ignore this email.</p>';
                $text = "Hi {$greetingName},\n\nWe received a request to reset your password.\n\nReset Password: {$resetUrl}\n\nThis link will expire in 1 hour.\n\nIf you did not request this, you can ignore this email.";

                Mailer::send($email, $subject, $html, $text);
            } catch (\Throwable $e) {
                // Don't reveal user existence; but do surface a real delivery/system failure.
                error_log('Password reset email failed: ' . $e->getMessage());
                flash('error', 'Failed to send password reset email. Please try again later.');
            }
        }

        $this->redirect('/forgot-password');
    }

    /**
     * Show reset password form
     */
    public function showResetPassword(string $token): void
    {
        $this->view('auth/reset-password', [
            'title' => 'Reset Password',
            'token' => $token,
            'email' => trim((string)$this->input('email', '')),
        ], 'auth');
    }

    /**
     * Handle password reset
     */
    public function resetPassword(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/login');
        }

        $token = $this->input('token', '');
        $email = trim($this->input('email', ''));
        $password = $this->input('password', '');
        $passwordConfirmation = $this->input('password_confirmation', '');

        if (empty($token) || empty($email) || empty($password)) {
            flash('error', 'Please fill in all fields');
            $this->redirect('/reset-password/' . $token);
        }

        if (strlen($password) < 8) {
            flash('error', 'Password must be at least 8 characters');
            $this->redirect('/reset-password/' . $token);
        }

        if ($password !== $passwordConfirmation) {
            flash('error', 'Passwords do not match');
            $this->redirect('/reset-password/' . $token);
        }

        if (AuthManager::resetPassword($token, $email, $password)) {
            flash('success', 'Password has been reset. You can now log in.');
            $this->redirect('/login');
        } else {
            flash('error', 'Invalid or expired reset token');
            $this->redirect('/forgot-password');
        }
    }

    /**
     * Redirect to GitHub OAuth
     */
    public function redirectToGitHub(): void
    {
        $github = new GitHubProvider();
        $url = $github->getAuthUrl();
        $this->redirect($url);
    }

    /**
     * Handle GitHub OAuth callback
     */
    public function handleGitHubCallback(): void
    {
        $code = $this->input('code', '');
        $state = $this->input('state', '');

        if (empty($code)) {
            flash('error', 'GitHub authentication failed');
            $this->redirect('/login');
        }

        $github = new GitHubProvider();
        
        // Verify state
        if (!$github->verifyState($state)) {
            flash('error', 'Invalid state. Please try again.');
            $this->redirect('/login');
        }

        // Get access token
        $tokenData = $github->getAccessToken($code);
        if (!$tokenData) {
            flash('error', 'Failed to get GitHub access token');
            $this->redirect('/login');
        }

        // Get user info
        $githubUser = $github->getUser($tokenData['access_token']);
        if (!$githubUser) {
            flash('error', 'Failed to get GitHub user info');
            $this->redirect('/login');
        }

        // Find or create user
        $user = User::findByGitHubId($githubUser['id']);

        if (!$user) {
            // Check if email exists
            if (!empty($githubUser['email'])) {
                $user = User::findByEmail($githubUser['email']);
            }

            if ($user) {
                // Link GitHub to existing account
                $user->update([
                    'github_id' => $githubUser['id'],
                    'github_token' => $tokenData['access_token'],
                    'avatar_url' => $githubUser['avatar_url'] ?? null,
                ]);
            } else {
                // Create new user
                $username = $githubUser['login'];
                
                // Ensure unique username
                $counter = 1;
                while (User::findByUsername($username)) {
                    $username = $githubUser['login'] . $counter;
                    $counter++;
                }

                $user = User::create([
                    'username' => $username,
                    'email' => $githubUser['email'] ?? $username . '@github.local',
                    'name' => $githubUser['name'] ?? $githubUser['login'],
                    'password_hash' => AuthManager::hashPassword(bin2hex(random_bytes(16))),
                    'github_id' => $githubUser['id'],
                    'github_token' => $tokenData['access_token'],
                    'avatar_url' => $githubUser['avatar_url'] ?? null,
                    'email_verified_at' => !empty($githubUser['email']) ? date('Y-m-d H:i:s') : null,
                    'is_admin' => User::count() === 1, // First user is admin
                ]);

                // Create personal team
                $user->createPersonalTeam();

                ActivityLog::log('user.register', 'User', $user->id, ['via' => 'github']);
            }
        } else {
            // Update token
            $user->update([
                'github_token' => $tokenData['access_token'],
                'avatar_url' => $githubUser['avatar_url'] ?? $user->avatar_url,
            ]);
        }

        // If MFA is enabled, require a TOTP challenge before creating a session.
        if ((bool)$user->two_factor_enabled && !empty($user->two_factor_secret)) {
            $_SESSION['mfa_pending_user_id'] = (int)$user->id;
            $_SESSION['mfa_pending_started_at'] = time();
            $_SESSION['mfa_pending_remember'] = 0;
            $this->redirect('/mfa');
        }

        AuthManager::login($user);
        ActivityLog::log('user.login', null, null, ['via' => 'github']);

        flash('success', 'Successfully logged in with GitHub!');
        $this->redirect('/dashboard');
    }
}
