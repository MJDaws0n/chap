<?php

namespace Chap\Controllers\ApiV2;

use Chap\App;
use Chap\Models\Project;
use Chap\Services\ApiV2\ApiTokenService;
use Chap\Services\ApiV2\CursorService;

class ProjectsController extends BaseApiV2Controller
{
    public function index(): void
    {
        $token = $this->apiToken();
        if (!$token) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        if (!ApiTokenService::scopeAllows($token->scopesList(), 'projects:read')) {
            $this->v2Error('forbidden', 'Token lacks scope: projects:read', 403);
            return;
        }

        $teamUuid = $_GET['filter']['team_id'] ?? ($_SERVER['HTTP_X_TEAM_ID'] ?? null);
        $team = $teamUuid ? \Chap\Models\Team::findByUuid((string)$teamUuid) : $this->user?->currentTeam();
        if (!$team) {
            $this->v2Error('invalid_request', 'No team selected', 400);
            return;
        }

        // Constraints: team_id if present.
        if (!ApiTokenService::constraintsAllow($token->constraintsMap(), ['team_id' => (string)$team->uuid])) {
            $this->v2Error('forbidden', 'Token constraints forbid this team', 403);
            return;
        }

        $limit = CursorService::parseLimit($_GET['page']['limit'] ?? null);
        $cursor = CursorService::decodeId($_GET['page']['cursor'] ?? null);

        $db = App::db();
        $params = [(int)$team->id];
        $where = "team_id = ?";
        if ($cursor) {
            $where .= " AND id > ?";
            $params[] = $cursor;
        }

        $rows = $db->fetchAll(
            "SELECT * FROM projects WHERE {$where} ORDER BY id ASC LIMIT {$limit}",
            $params
        );

        $nextCursor = null;
        if (count($rows) === $limit) {
            $nextCursor = CursorService::encodeId((int)$rows[count($rows) - 1]['id']);
        }

        $data = array_map(fn($r) => Project::fromArray($r)->toArray(), $rows);
        $this->ok([
            'data' => $data,
            'page' => ['next_cursor' => $nextCursor, 'limit' => $limit],
        ]);
    }
}
