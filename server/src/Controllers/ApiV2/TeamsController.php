<?php

namespace Chap\Controllers\ApiV2;

use Chap\Models\Team;
use Chap\Services\ApiV2\ApiTokenService;

class TeamsController extends BaseApiV2Controller
{
    public function index(): void
    {
        $token = $this->apiToken();
        if (!$token) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        if (!ApiTokenService::scopeAllows($token->scopesList(), 'teams:read')) {
            $this->v2Error('forbidden', 'Token lacks scope: teams:read', 403);
            return;
        }

        $teams = $this->user ? $this->user->teams() : [];
        $this->ok([
            'data' => array_map(fn($t) => $t->toArray(), $teams),
        ]);
    }

    public function select(string $team_id): void
    {
        $token = $this->apiToken();
        if (!$token) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        if (!ApiTokenService::scopeAllows($token->scopesList(), 'teams:write')) {
            $this->v2Error('forbidden', 'Token lacks scope: teams:write', 403);
            return;
        }

        $team = Team::findByUuid($team_id);
        if (!$team || !$this->user || (!$this->user->belongsToTeam($team) && !admin_view_all())) {
            $this->v2Error('forbidden', 'Forbidden', 403);
            return;
        }

        $_SESSION['current_team_id'] = $team->id;
        $this->ok(['data' => ['selected_team_id' => $team->uuid]]);
    }
}
