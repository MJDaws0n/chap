<?php

namespace Chap\Controllers\ApiV2\Admin;

use Chap\Controllers\ApiV2\BaseApiV2Controller;
use Chap\Models\ApiToken;
use Chap\Models\User;
use Chap\Security\TwoFactorService;
use Chap\Services\ApiV2\ApiTokenService;

class AuthController extends BaseApiV2Controller
{
    /**
     * POST /api/v2/admin/auth/session
     */
    public function session(): void
    {
        $data = $this->all();

        $email = (string)($data['email'] ?? '');
        $password = (string)($data['password'] ?? '');
        $totp = (string)($data['totp'] ?? ($data['mfa_code'] ?? ''));

        if ($email === '' || $password === '') {
            $this->v2Error('invalid_request', 'Email and password required', 400);
            return;
        }

        $user = User::findByEmail($email);
        if (!$user || !$user->verifyPassword($password)) {
            $this->v2Error('unauthorized', 'Invalid credentials', 401);
            return;
        }
        if (!(bool)($user->is_admin ?? false)) {
            $this->v2Error('forbidden', 'Admin access required', 403);
            return;
        }

        if ((bool)$user->two_factor_enabled) {
            if ($totp === '') {
                $this->v2Error('mfa_required', 'MFA required', 401, ['mfa_required' => true]);
                return;
            }
            if (!TwoFactorService::verifyCode((string)$user->two_factor_secret, $totp)) {
                $this->v2Error('mfa_invalid', 'Invalid MFA code', 401, ['mfa_required' => true]);
                return;
            }
        }

        $rawToken = bin2hex(random_bytes(32));
        $hash = ApiTokenService::hashToken($rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        // Admin sessions are broad; use PATs for automation.
        $scopes = ['*'];
        $token = ApiToken::create([
            'uuid' => uuid(),
            'user_id' => $user->id,
            'name' => 'admin-session',
            'type' => 'session',
            'token_hash' => $hash,
            'scopes' => json_encode($scopes),
            'constraints' => null,
            'expires_at' => $expiresAt,
        ]);

        $this->ok([
            'access_token' => $rawToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);
    }
}
