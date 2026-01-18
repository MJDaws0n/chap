<?php

namespace Chap\Controllers\ApiV2;

use Chap\Services\ApiV2\ApiTokenService;

class MeController extends BaseApiV2Controller
{
    public function show(): void
    {
        $token = $this->apiToken();
        if (!$token) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        if (!ApiTokenService::scopeAllows($token->scopesList(), 'activity:read') && !ApiTokenService::scopeAllows($token->scopesList(), 'settings:read')) {
            // Me is generally safe but still requires at least one read-ish scope.
            $this->v2Error('forbidden', 'Token lacks scope to read identity', 403);
            return;
        }

        $team = $this->user?->currentTeam();

        $this->ok([
            'data' => [
                'user' => $this->user?->toArray(),
                'current_team' => $team ? $team->toArray() : null,
            ],
        ]);
    }
}
