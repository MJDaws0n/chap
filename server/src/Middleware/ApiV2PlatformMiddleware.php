<?php

namespace Chap\Middleware;

use Chap\Models\PlatformApiKey;
use Chap\Services\ApiV2\ApiTokenService;

/**
 * Bearer authentication for platform API keys.
 *
 * This does NOT authenticate a user session. It only attaches the platform key to the request.
 */
class ApiV2PlatformMiddleware
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
        $key = PlatformApiKey::findByTokenHash($hash);
        if (!$key || $key->isRevoked() || $key->isExpired()) {
            $this->error('unauthorized', 'Invalid or expired token', 401);
            return false;
        }

        $key->touchLastUsed();
        $GLOBALS['chap_platform_api_key'] = $key;

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
