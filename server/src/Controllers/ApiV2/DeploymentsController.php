<?php

namespace Chap\Controllers\ApiV2;

use Chap\App;
use Chap\Auth\TeamPermissionService;
use Chap\Models\Application;
use Chap\Models\Deployment;
use Chap\Models\Team;
use Chap\Services\ApiV2\ApiTokenService;
use Chap\Services\DeploymentService;
use Chap\Services\ApiV2\CursorService;
use Chap\Services\ChapScript\ChapScriptPreDeploy;

class DeploymentsController extends BaseApiV2Controller
{
    public function indexForApplication(string $application_id): void
    {
        $token = $this->apiToken();
        if (!$token) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        if (!ApiTokenService::scopeAllows($token->scopesList(), 'deployments:read')) {
            $this->v2Error('forbidden', 'Token lacks scope: deployments:read', 403);
            return;
        }

        $app = Application::findByUuid($application_id);
        if (!$app) {
            $this->v2Error('not_found', 'Application not found', 404);
            return;
        }

        $project = $app->environment()?->project();
        $team = $project ? Team::find((int)$project->team_id) : null;
        if (!$project || !$team) {
            $this->v2Error('not_found', 'Application not found', 404);
            return;
        }

        if (!ApiTokenService::constraintsAllow($token->constraintsMap(), [
            'team_id' => (string)$team->uuid,
            'project_id' => (string)$project->uuid,
            'environment_id' => (string)($app->environment()?->uuid ?? ''),
            'application_id' => (string)$app->uuid,
        ])) {
            $this->v2Error('forbidden', 'Token constraints forbid this application', 403);
            return;
        }

        $userId = (int)($this->user?->id ?? 0);
        if (!(bool)($this->user?->is_admin ?? false)) {
            if ($userId <= 0 || !TeamPermissionService::can((int)$team->id, $userId, 'deployments', 'read')) {
                $this->v2Error('forbidden', 'Permission denied', 403);
                return;
            }
        }

        $limit = CursorService::parseLimit($_GET['page']['limit'] ?? null);
        $cursor = CursorService::decodeId($_GET['page']['cursor'] ?? null);

        $db = App::db();
        $params = [(int)$app->id];
        $where = 'application_id = ?';
        if ($cursor) {
            $where .= ' AND id > ?';
            $params[] = $cursor;
        }

        $rows = $db->fetchAll(
            "SELECT id, uuid, status, git_commit_sha, git_commit_message, started_at, finished_at, error_message, created_at\n" .
            "FROM deployments WHERE {$where} ORDER BY id ASC LIMIT {$limit}",
            $params
        );

        $nextCursor = null;
        if (count($rows) === $limit) {
            $nextCursor = CursorService::encodeId((int)$rows[count($rows) - 1]['id']);
        }

        $data = array_map(function($r) {
            $uuid = (string)($r['uuid'] ?? '');
            return [
                'internal_id' => (int)($r['id'] ?? 0),
                'id' => $uuid,
                'uuid' => $uuid,
                'status' => (string)($r['status'] ?? ''),
                'commit_sha' => $r['git_commit_sha'] ? (string)$r['git_commit_sha'] : null,
                'commit_message' => $r['git_commit_message'] ? (string)$r['git_commit_message'] : null,
                'started_at' => $r['started_at'] ? date('c', strtotime((string)$r['started_at'])) : null,
                'finished_at' => $r['finished_at'] ? date('c', strtotime((string)$r['finished_at'])) : null,
                'error_message' => $r['error_message'] ? (string)$r['error_message'] : null,
                'created_at' => $r['created_at'] ? date('c', strtotime((string)$r['created_at'])) : null,
            ];
        }, $rows);

        $this->ok([
            'data' => $data,
            'page' => ['next_cursor' => $nextCursor, 'limit' => $limit],
        ]);
    }

    public function createForApplication(string $application_id): void
    {
        $token = $this->apiToken();
        if (!$token) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        if (!ApiTokenService::scopeAllows($token->scopesList(), 'applications:deploy')) {
            $this->v2Error('forbidden', 'Token lacks scope: applications:deploy', 403);
            return;
        }

        $app = Application::findByUuid($application_id);
        if (!$app) {
            $this->v2Error('not_found', 'Application not found', 404);
            return;
        }

        $project = $app->environment()?->project();
        $team = $project ? Team::find((int)$project->team_id) : null;
        if (!$project || !$team) {
            $this->v2Error('not_found', 'Application not found', 404);
            return;
        }

        if (!ApiTokenService::constraintsAllow($token->constraintsMap(), [
            'team_id' => (string)$team->uuid,
            'project_id' => (string)$project->uuid,
            'environment_id' => (string)($app->environment()?->uuid ?? ''),
            'application_id' => (string)$app->uuid,
        ])) {
            $this->v2Error('forbidden', 'Token constraints forbid this application', 403);
            return;
        }

        $userId = (int)($this->user?->id ?? 0);
        if (!(bool)($this->user?->is_admin ?? false)) {
            if ($userId <= 0 || !TeamPermissionService::can((int)$team->id, $userId, 'deployments', 'execute')) {
                $this->v2Error('forbidden', 'Permission denied', 403);
                return;
            }
        }

        if ((string)($app->status ?? '') === 'deploying') {
            $this->v2Error('failed_precondition', 'Application is already deploying', 409);
            return;
        }

        $data = $this->all();
        $commitSha = isset($data['commit_sha']) ? trim((string)$data['commit_sha']) : null;
        if ($commitSha === '') $commitSha = null;

        // Run template pre-deploy script (ChapScribe) if configured.
        try {
            $pre = ChapScriptPreDeploy::run($app, [
                'commit_sha' => $commitSha,
                'triggered_by' => $this->user ? 'user' : 'api',
                'triggered_by_name' => $this->user?->displayName(),
                'user_id' => (int)($this->user?->id ?? 0),
            ]);

            if (($pre['status'] ?? '') === 'waiting') {
                $run = $pre['run'] ?? null;
                $this->v2Error('action_required', 'Template script requires input', 409, [
                    'script_run' => $run ? ['uuid' => $run->uuid, 'status' => $run->status] : null,
                    'prompt' => $pre['prompt'] ?? null,
                ]);
                return;
            }

            if (($pre['status'] ?? '') === 'stopped') {
                $this->v2Error('validation_error', (string)($pre['message'] ?? 'Deployment blocked by template script'), 422);
                return;
            }
        } catch (\Throwable $e) {
            $this->v2Error('validation_error', $e->getMessage(), 422);
            return;
        }

        try {
            $deployment = DeploymentService::create($app, $commitSha, [
                'triggered_by' => $this->user ? 'user' : 'api',
                'triggered_by_name' => $this->user?->displayName(),
            ]);
        } catch (\Throwable $e) {
            $this->v2Error('validation_error', $e->getMessage(), 422);
            return;
        }

        $this->ok([
            'data' => [
                'deployment_id' => (string)$deployment->uuid,
                'deployment' => [
                    'id' => (string)$deployment->uuid,
                    'uuid' => (string)$deployment->uuid,
                    'status' => (string)$deployment->status,
                    'commit_sha' => $deployment->git_commit_sha ?? $deployment->commit_sha,
                    'created_at' => $deployment->created_at ? date('c', strtotime((string)$deployment->created_at)) : null,
                ],
            ],
        ], 201);
    }

    public function show(string $deployment_id): void
    {
        $token = $this->apiToken();
        if (!$token) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        if (!ApiTokenService::scopeAllows($token->scopesList(), 'deployments:read')) {
            $this->v2Error('forbidden', 'Token lacks scope: deployments:read', 403);
            return;
        }

        $deployment = Deployment::findByUuid($deployment_id);
        if (!$deployment) {
            $this->v2Error('not_found', 'Deployment not found', 404);
            return;
        }

        $app = $deployment->application();
        $project = $app?->environment()?->project();
        $team = $project ? Team::find((int)$project->team_id) : null;
        if (!$app || !$project || !$team) {
            $this->v2Error('not_found', 'Deployment not found', 404);
            return;
        }

        if (!ApiTokenService::constraintsAllow($token->constraintsMap(), [
            'team_id' => (string)$team->uuid,
            'project_id' => (string)$project->uuid,
            'environment_id' => (string)($app->environment()?->uuid ?? ''),
            'application_id' => (string)$app->uuid,
            'node_id' => $app->node()?->uuid ? (string)$app->node()?->uuid : null,
        ])) {
            $this->v2Error('forbidden', 'Token constraints forbid this deployment', 403);
            return;
        }

        $this->ok([
            'data' => [
                'id' => (string)$deployment->uuid,
                'uuid' => (string)$deployment->uuid,
                'status' => (string)$deployment->status,
                'application_id' => (string)$app->uuid,
                'commit_sha' => $deployment->git_commit_sha ?? $deployment->commit_sha,
                'commit_message' => $deployment->git_commit_message ?? $deployment->commit_message,
                'error_message' => $deployment->error_message,
                'started_at' => $deployment->started_at ? date('c', strtotime((string)$deployment->started_at)) : null,
                'finished_at' => $deployment->finished_at ? date('c', strtotime((string)$deployment->finished_at)) : null,
                'created_at' => $deployment->created_at ? date('c', strtotime((string)$deployment->created_at)) : null,
            ],
        ]);
    }

    public function logs(string $deployment_id): void
    {
        $token = $this->apiToken();
        if (!$token) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        if (!ApiTokenService::scopeAllows($token->scopesList(), 'deployments:read')) {
            $this->v2Error('forbidden', 'Token lacks scope: deployments:read', 403);
            return;
        }

        $deployment = Deployment::findByUuid($deployment_id);
        if (!$deployment) {
            $this->v2Error('not_found', 'Deployment not found', 404);
            return;
        }

        $this->ok([
            'data' => [
                'status' => (string)$deployment->status,
                'logs' => $deployment->logsArray(),
            ],
        ]);
    }

    public function cancel(string $deployment_id): void
    {
        $token = $this->apiToken();
        if (!$token) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        $scopes = $token->scopesList();
        if (!ApiTokenService::scopeAllows($scopes, 'deployments:write') && !ApiTokenService::scopeAllows($scopes, 'deployments:cancel')) {
            $this->v2Error('forbidden', 'Token lacks scope: deployments:write (or deployments:cancel)', 403);
            return;
        }

        $deployment = Deployment::findByUuid($deployment_id);
        if (!$deployment) {
            $this->v2Error('not_found', 'Deployment not found', 404);
            return;
        }

        if (!$deployment->canBeCancelled()) {
            $this->v2Error('failed_precondition', 'Deployment cannot be cancelled', 409);
            return;
        }

        DeploymentService::cancel($deployment);
        $this->ok(['data' => ['cancelled' => true]]);
    }

    public function rollback(string $deployment_id): void
    {
        $token = $this->apiToken();
        if (!$token) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        $scopes = $token->scopesList();
        if (!ApiTokenService::scopeAllows($scopes, 'deployments:write') && !ApiTokenService::scopeAllows($scopes, 'deployments:rollback')) {
            $this->v2Error('forbidden', 'Token lacks scope: deployments:write (or deployments:rollback)', 403);
            return;
        }

        $deployment = Deployment::findByUuid($deployment_id);
        if (!$deployment) {
            $this->v2Error('not_found', 'Deployment not found', 404);
            return;
        }

        if (!in_array((string)$deployment->status, ['running', 'failed'], true)) {
            $this->v2Error('failed_precondition', 'Cannot rollback to this deployment', 409);
            return;
        }

        $new = DeploymentService::rollback($deployment, [
            'triggered_by' => 'rollback',
            'triggered_by_name' => $this->user?->displayName(),
        ]);

        $this->ok([
            'data' => [
                'deployment_id' => (string)$new->uuid,
                'deployment' => [
                    'id' => (string)$new->uuid,
                    'uuid' => (string)$new->uuid,
                    'status' => (string)$new->status,
                    'commit_sha' => $new->git_commit_sha ?? $new->commit_sha,
                ],
            ],
        ], 201);
    }
}