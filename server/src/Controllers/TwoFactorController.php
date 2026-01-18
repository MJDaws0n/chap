<?php

namespace Chap\Controllers;

use Chap\Auth\AuthManager;
use Chap\Models\ActivityLog;
use Chap\Models\User;
use Chap\Security\TwoFactorService;

class TwoFactorController extends BaseController
{
    public function showChallenge(): void
    {
        if (AuthManager::check()) {
            $this->redirect('/dashboard');
            return;
        }

        $pendingUserId = (int)($_SESSION['mfa_pending_user_id'] ?? 0);
        $startedAt = (int)($_SESSION['mfa_pending_started_at'] ?? 0);

        if ($pendingUserId <= 0 || $startedAt <= 0 || (time() - $startedAt) > 600) {
            unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_started_at'], $_SESSION['mfa_pending_remember']);
            flash('error', 'Your login session expired. Please try again.');
            $this->redirect('/login');
            return;
        }

        $user = User::find($pendingUserId);
        if (!$user || !(bool)$user->two_factor_enabled || empty($user->two_factor_secret)) {
            unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_started_at'], $_SESSION['mfa_pending_remember']);
            flash('error', 'MFA is not available for this account. Please login again.');
            $this->redirect('/login');
            return;
        }

        $this->view('auth/mfa', [
            'title' => 'Two-Factor Authentication',
            'email' => $user->email,
        ], 'auth');
    }

    public function verifyChallenge(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request. Please try again.');
            $this->redirect('/mfa');
            return;
        }

        $pendingUserId = (int)($_SESSION['mfa_pending_user_id'] ?? 0);
        $startedAt = (int)($_SESSION['mfa_pending_started_at'] ?? 0);

        if ($pendingUserId <= 0 || $startedAt <= 0 || (time() - $startedAt) > 600) {
            unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_started_at'], $_SESSION['mfa_pending_remember']);
            flash('error', 'Your login session expired. Please try again.');
            $this->redirect('/login');
            return;
        }

        $code = trim((string)$this->input('code', ''));

        $user = User::find($pendingUserId);
        if (!$user || !(bool)$user->two_factor_enabled || empty($user->two_factor_secret)) {
            unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_started_at'], $_SESSION['mfa_pending_remember']);
            flash('error', 'MFA is not available for this account. Please login again.');
            $this->redirect('/login');
            return;
        }

        if (!TwoFactorService::verifyCode((string)$user->two_factor_secret, $code)) {
            flash('error', 'Invalid authentication code');
            $this->redirect('/mfa');
            return;
        }

        AuthManager::login($user);

        // Remember-me handling mirrors AuthController
        $remember = (int)($_SESSION['mfa_pending_remember'] ?? 0) === 1;
        if ($remember) {
            $lifetime = time() + (60 * 60 * 24 * 30); // 30 days
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

        unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_started_at'], $_SESSION['mfa_pending_remember']);

        ActivityLog::log('user.login');
        flash('success', 'Welcome back!');
        $this->redirect('/dashboard');
    }

    public function showProfile(): void
    {
        $setupSecret = (string)($_SESSION['mfa_setup_secret'] ?? '');
        $setupUri = (string)($_SESSION['mfa_setup_uri'] ?? '');

        $qr = null;
        if ($setupSecret !== '' && $setupUri !== '') {
            try {
                $qr = TwoFactorService::qrCodeDataUri($setupUri);
            } catch (\Throwable) {
                $qr = null;
            }
        }

        $this->view('profile/mfa', [
            'title' => 'Multi-Factor Authentication',
            'setupSecret' => $setupSecret,
            'setupUri' => $setupUri,
            'setupQr' => $qr,
        ]);
    }

    public function startSetup(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/profile/mfa');
            return;
        }

        if (!$this->user) {
            $this->redirect('/login');
            return;
        }

        $secret = TwoFactorService::generateSecret();
        $uri = TwoFactorService::provisioningUri($this->user->email, $secret);

        $_SESSION['mfa_setup_secret'] = $secret;
        $_SESSION['mfa_setup_uri'] = $uri;
        $_SESSION['mfa_setup_started_at'] = time();

        $this->redirect('/profile/mfa');
    }

    public function confirmSetup(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/profile/mfa');
            return;
        }

        if (!$this->user) {
            $this->redirect('/login');
            return;
        }

        $secret = (string)($_SESSION['mfa_setup_secret'] ?? '');
        $startedAt = (int)($_SESSION['mfa_setup_started_at'] ?? 0);
        if ($secret === '' || $startedAt <= 0 || (time() - $startedAt) > 1800) {
            unset($_SESSION['mfa_setup_secret'], $_SESSION['mfa_setup_uri'], $_SESSION['mfa_setup_started_at']);
            flash('error', 'Setup expired. Please try again.');
            $this->redirect('/profile/mfa');
            return;
        }

        $code = trim((string)$this->input('code', ''));
        if (!TwoFactorService::verifyCode($secret, $code)) {
            flash('error', 'Invalid authentication code');
            $this->redirect('/profile/mfa');
            return;
        }

        $this->user->update([
            'two_factor_secret' => $secret,
            'two_factor_enabled' => true,
        ]);

        unset($_SESSION['mfa_setup_secret'], $_SESSION['mfa_setup_uri'], $_SESSION['mfa_setup_started_at']);

        ActivityLog::log('user.mfa.enabled');
        flash('success', 'MFA enabled');
        $this->redirect('/profile/mfa');
    }

    public function disable(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/profile/mfa');
            return;
        }

        if (!$this->user) {
            $this->redirect('/login');
            return;
        }

        $password = (string)$this->input('current_password', '');
        $code = (string)$this->input('code', '');

        if (!$this->user->verifyPassword($password)) {
            flash('error', 'Current password is incorrect');
            $this->redirect('/profile/mfa');
            return;
        }

        if (!TwoFactorService::verifyCode((string)$this->user->two_factor_secret, $code)) {
            flash('error', 'Invalid authentication code');
            $this->redirect('/profile/mfa');
            return;
        }

        $this->user->update([
            'two_factor_secret' => null,
            'two_factor_enabled' => false,
        ]);

        ActivityLog::log('user.mfa.disabled');
        flash('success', 'MFA disabled');
        $this->redirect('/profile/mfa');
    }
}
