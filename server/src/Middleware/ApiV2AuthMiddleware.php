<?php

namespace Chap\Middleware;

use Chap\Auth\AuthManager;
use Chap\Models\ApiToken;
use Chap\Models\User;
use Chap\Services\ApiV2\ApiTokenService;

/**
 * Bearer token authentication for /api/v2.
 */
class ApiV2AuthMiddleware
{
    public function handle(): bool
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($auth, 'Bearer ')) {
            $this->error('unauthorized', 'Missing bearer token', 401);
            return false;
        }

        $raw = trim(substr($auth, strlen('Bearer ')));
        if ($raw === '') {
            $this->error('unauthorized', 'Missing bearer token', 401);
            return false;
        }

        $hash = ApiTokenService::hashToken($raw);
        $token = ApiToken::findByTokenHash($hash);
        if (!$token || $token->isRevoked() || $token->isExpired()) {
            $this->error('unauthorized', 'Invalid or expired token', 401);
            return false;
        }

        $user = User::find((int)$token->user_id);
        if (!$user) {
            $this->error('unauthorized', 'Invalid token user', 401);
            return false;
        }

        // Attach to request globals for controllers.
        $token->touchLastUsed();
        AuthManager::authenticateOnce($user);
        $GLOBALS['chap_api_v2_token'] = $token;

        return true;
    }

    private function requestId(): string
    {
        if (!isset($GLOBALS['chap_request_id'])) {
            $GLOBALS['chap_request_id'] = 'req_' . bin2hex(random_bytes(12));
        }
        return (string)$GLOBALS['chap_request_id'];
    }

    private function error(string $code, string $message, int $status): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $this->requestId(),
            ],
        ]);
    }
}
