<?php

namespace Chap\Middleware;

use Chap\Auth\AuthManager;

/**
 * Admin-only gate for /api/v2/admin.
 *
 * Must run after ApiV2AuthMiddleware.
 */
class ApiV2AdminMiddleware
{
    public function handle(): bool
    {
        $user = AuthManager::user();
        if (!$user || !(bool)($user->is_admin ?? false)) {
            $this->error('forbidden', 'Admin access required', 403);
            return false;
        }
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