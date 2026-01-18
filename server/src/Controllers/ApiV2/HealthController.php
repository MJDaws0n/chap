<?php

namespace Chap\Controllers\ApiV2;

use Chap\Config;

class HealthController extends BaseApiV2Controller
{
    public function health(): void
    {
        $this->ok([
            'status' => 'ok',
            'server_time' => gmdate('c'),
            'version' => Config::serverVersion(),
        ]);
    }

    public function capabilities(): void
    {
        $this->ok([
            'data' => [
                'api' => ['version' => 'v2'],
                'auth' => [
                    'pat' => true,
                    'session' => true,
                    'idempotency' => true,
                ],
                'limits' => [
                    'page' => ['default_limit' => 50, 'max_limit' => 200],
                    'node_token_ttl_sec' => ['min' => 30, 'max' => 600],
                ],
            ],
        ]);
    }
}
