<?php

namespace Chap\Controllers\Api;

use Chap\Controllers\BaseController;
use Chap\Models\User;
use Chap\App;

/**
 * API Authentication Controller
 */
class AuthController extends BaseController
{
    /**
     * Login and get API token
     */
    public function login(): void
    {
        $data = $this->all();

        if (empty($data['email']) || empty($data['password'])) {
            $this->json(['error' => 'Email and password required'], 400);
            return;
        }

        $user = User::findByEmail($data['email']);

        if (!$user || !$user->verifyPassword($data['password'])) {
            $this->json(['error' => 'Invalid credentials'], 401);
            return;
        }

        // Generate API token
        $token = $this->createApiToken($user);

        $this->json([
            'token' => $token,
            'user' => $user->toArray(),
        ]);
    }

    /**
     * Create an API token for a user
     */
    private function createApiToken(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);

        $db = App::db();
        
        // Store hashed token in sessions table with api type
        $db->insert('sessions', [
            'user_id' => $user->id,
            'token' => $hashedToken,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        return $token;
    }
}
