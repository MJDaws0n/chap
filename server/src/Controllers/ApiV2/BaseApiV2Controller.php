<?php

namespace Chap\Controllers\ApiV2;

use Chap\Controllers\BaseController;
use Chap\Models\ApiToken;

/**
 * Base controller for /api/v2.
 */
abstract class BaseApiV2Controller extends BaseController
{
    protected function isApiRequest(): bool
    {
        return true;
    }

    protected function requestId(): string
    {
        if (!isset($GLOBALS['chap_request_id'])) {
            $GLOBALS['chap_request_id'] = 'req_' . bin2hex(random_bytes(12));
        }
        return (string)$GLOBALS['chap_request_id'];
    }

    protected function v2Error(string $code, string $message, int $status, ?array $details = null): void
    {
        $payload = [
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $this->requestId(),
            ],
        ];
        if ($details !== null) {
            $payload['error']['details'] = $details;
        }
        $this->json($payload, $status);
    }

    protected function ok(mixed $data, int $status = 200): void
    {
        $this->json($data, $status);
    }

    protected function apiToken(): ?ApiToken
    {
        $t = $GLOBALS['chap_api_v2_token'] ?? null;
        return $t instanceof ApiToken ? $t : null;
    }
}
