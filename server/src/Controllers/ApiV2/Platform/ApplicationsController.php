<?php

namespace Chap\Controllers\ApiV2\Platform;

use Chap\App;
use Chap\Models\Application;
use Chap\Models\Environment;
use Chap\Models\Node;
use Chap\Models\Project;
use Chap\Models\Team;
use Chap\Models\User;
use Chap\Services\ResourceAllocator;
use Chap\Services\ResourceHierarchy;

class ApplicationsController extends BasePlatformController
{
    public function index(): void
    {
        $key = $this->requirePlatformScope('applications:read');
        if (!$key) return;

        $filterTeamUuid = trim((string)($_GET['filter']['team_id'] ?? ''));
        $filterProjectUuid = trim((string)($_GET['filter']['project_id'] ?? ''));
        $filterEnvUuid = trim((string)($_GET['filter']['environment_id'] ?? ''));
        $filterAppUuid = trim((string)($_GET['filter']['application_id'] ?? ''));
        $filterNodeUuid = trim((string)($_GET['filter']['node_id'] ?? ''));
        $filterUserUuid = trim((string)($_GET['filter']['user_id'] ?? ''));

        $db = App::db();
        $params = [];
        $where = '1=1';

        if ($filterTeamUuid !== '') {
            $where .= ' AND t.uuid = ?';
            $params[] = $filterTeamUuid;
        }
        if ($filterProjectUuid !== '') {
            $where .= ' AND p.uuid = ?';
            $params[] = $filterProjectUuid;
        }
        if ($filterEnvUuid !== '') {
            $where .= ' AND e.uuid = ?';
            $params[] = $filterEnvUuid;
        }
        if ($filterAppUuid !== '') {
            $where .= ' AND a.uuid = ?';
            $params[] = $filterAppUuid;
        }
        if ($filterNodeUuid !== '') {
            $where .= ' AND n.uuid = ?';
            $params[] = $filterNodeUuid;
        }
        if ($filterUserUuid !== '') {
            $where .= ' AND u.uuid = ?';
            $params[] = $filterUserUuid;
        }

        $rows = $db->fetchAll(
            "SELECT a.uuid, a.name, a.status, e.uuid AS environment_uuid, p.uuid AS project_uuid, t.uuid AS team_uuid, n.uuid AS node_uuid, u.uuid AS owner_uuid\n" .
            "FROM applications a\n" .
            "JOIN environments e ON e.id = a.environment_id\n" .
            "JOIN projects p ON p.id = e.project_id\n" .
            "JOIN teams t ON t.id = p.team_id\n" .
            "LEFT JOIN nodes n ON n.id = a.node_id\n" .
            "LEFT JOIN users u ON u.id = a.user_id\n" .
            "WHERE {$where}\n" .
            "ORDER BY a.id ASC",
            $params
        );

        $data = array_map(function($r) {
            $uuid = (string)($r['uuid'] ?? '');
            return [
                'id' => $uuid,
                'uuid' => $uuid,
                'name' => (string)($r['name'] ?? ''),
                'status' => (string)($r['status'] ?? ''),
                'team_id' => (string)($r['team_uuid'] ?? ''),
                'project_id' => (string)($r['project_uuid'] ?? ''),
                'environment_id' => (string)($r['environment_uuid'] ?? ''),
                'node_id' => $r['node_uuid'] ? (string)$r['node_uuid'] : null,
                'owner_user_id' => $r['owner_uuid'] ? (string)$r['owner_uuid'] : null,
            ];
        }, $rows);

        $this->ok(['data' => $data]);
    }

    public function show(string $application_id): void
    {
        $key = $this->requirePlatformScope('applications:read');
        if (!$key) return;

        $app = Application::findByUuid($application_id);
        if (!$app) {
            $this->v2Error('not_found', 'Application not found', 404);
            return;
        }

        $env = $app->environment();
        $project = $env?->project();
        $team = $project ? Team::find((int)$project->team_id) : null;
        if (!$env || !$project || !$team) {
            $this->v2Error('not_found', 'Application not found', 404);
            return;
        }

        if (!$this->requirePlatformConstraints($key, [
            'team_id' => (string)$team->uuid,
            'project_id' => (string)$project->uuid,
            'environment_id' => (string)$env->uuid,
            'application_id' => (string)$app->uuid,
            'node_id' => $app->node()?->uuid ? (string)$app->node()?->uuid : null,
        ])) return;

        $ownerUuid = null;
        if ($app->user_id) {
            $u = User::find((int)$app->user_id);
            $ownerUuid = $u?->uuid ? (string)$u->uuid : null;
        }

        $this->ok([
            'data' => [
                'id' => (string)$app->uuid,
                'uuid' => (string)$app->uuid,
                'name' => (string)$app->name,
                'description' => $app->description,
                'status' => (string)$app->status,
                'team_id' => (string)$team->uuid,
                'project_id' => (string)$project->uuid,
                'environment_id' => (string)$env->uuid,
                'node_id' => $app->node()?->uuid ? (string)$app->node()?->uuid : null,
                'owner_user_id' => $ownerUuid,
                'build_pack' => (string)($app->build_pack ?? ''),
                'git_repository' => $app->git_repository,
                'git_branch' => $app->git_branch,
                'template_slug' => $app->template_slug,
                'cpu_limit' => (string)($app->cpu_limit ?? ''),
                'memory_limit' => (string)($app->memory_limit ?? ''),
            ],
        ]);
    }

    /**
     * POST /api/v2/platform/applications
     *
     * Creates an application (no deploy). Use deployments endpoint to deploy.
     */
    public function store(): void
    {
        $key = $this->requirePlatformScope('applications:write');
        if (!$key) return;

        $data = $this->all();

        $environmentUuid = trim((string)($data['environment_id'] ?? $data['environment_uuid'] ?? ''));
        $nodeUuid = trim((string)($data['node_id'] ?? $data['node_uuid'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));

        if ($environmentUuid === '') {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'environment_id']);
            return;
        }
        if ($nodeUuid === '') {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'node_id']);
            return;
        }
        if ($name === '') {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'name']);
            return;
        }

        $environment = Environment::findByUuid($environmentUuid);
        if (!$environment) {
            $this->v2Error('not_found', 'Environment not found', 404);
            return;
        }
        $project = $environment->project();
        $team = $project ? Team::find((int)$project->team_id) : null;
        if (!$project || !$team) {
            $this->v2Error('not_found', 'Environment not found', 404);
            return;
        }

        $node = Node::findByUuid($nodeUuid);
        if (!$node) {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'node_id']);
            return;
        }

        if (!$this->requirePlatformConstraints($key, [
            'team_id' => (string)$team->uuid,
            'project_id' => (string)$project->uuid,
            'environment_id' => (string)$environment->uuid,
            'node_id' => (string)$node->uuid,
        ])) return;

        $ownerUuid = trim((string)($data['owner_user_id'] ?? $data['owner_user_uuid'] ?? $data['user_id'] ?? ''));
        $ownerId = null;
        if ($ownerUuid !== '') {
            $owner = User::findByUuid($ownerUuid);
            if (!$owner) {
                $this->v2Error('validation_error', 'Owner user not found', 422, ['field' => 'owner_user_id']);
                return;
            }
            $ownerId = (int)$owner->id;
        }

        $cpuLimitInputRaw = trim((string)($data['cpu_limit'] ?? ''));
        $memoryLimitInputRaw = trim((string)($data['memory_limit'] ?? ''));

        $cpuLimitForContainer = $cpuLimitInputRaw !== '' ? $cpuLimitInputRaw : '1';
        $memoryLimitForContainer = $memoryLimitInputRaw !== '' ? $memoryLimitInputRaw : '512m';

        $cpuMillicores = -1;
        $ramMb = -1;
        try {
            if ($cpuLimitInputRaw !== '') {
                $cpuMillicores = ResourceHierarchy::parseCpuMillicores($cpuLimitInputRaw);
            }
            if ($memoryLimitInputRaw !== '') {
                $ramMb = ResourceHierarchy::parseDockerMemoryToMb($memoryLimitInputRaw);
            }
        } catch (\Throwable $e) {
            $this->v2Error('validation_error', $e->getMessage(), 422);
            return;
        }

        $storageMbLimit = ResourceHierarchy::parseMb((string)($data['storage_mb_limit'] ?? '-1'));
        $bandwidthLimit = ResourceHierarchy::parseIntOrAuto((string)($data['bandwidth_mbps_limit'] ?? '-1'));
        $pidsLimit = ResourceHierarchy::parseIntOrAuto((string)($data['pids_limit'] ?? '-1'));

        // Validate against environment limits (fixed allocations only).
        $parent = ResourceHierarchy::effectiveEnvironmentLimits($environment);
        $siblings = Application::forEnvironment((int)$environment->id);

        $maps = [
            'cpu_millicores' => [],
            'ram_mb' => [],
            'storage_mb' => [],
            'bandwidth_mbps' => [],
            'pids' => [],
        ];
        foreach ($siblings as $a) {
            $maps['cpu_millicores'][(int)$a->id] = (int)$a->cpu_millicores_limit;
            $maps['ram_mb'][(int)$a->id] = (int)$a->ram_mb_limit;
            $maps['storage_mb'][(int)$a->id] = (int)$a->storage_mb_limit;
            $maps['bandwidth_mbps'][(int)$a->id] = (int)$a->bandwidth_mbps_limit;
            $maps['pids'][(int)$a->id] = (int)$a->pids_limit;
        }

        $maps['cpu_millicores'][0] = $cpuMillicores;
        $maps['ram_mb'][0] = $ramMb;
        $maps['storage_mb'][0] = $storageMbLimit;
        $maps['bandwidth_mbps'][0] = $bandwidthLimit;
        $maps['pids'][0] = $pidsLimit;

        if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['cpu_millicores'], $maps['cpu_millicores'])
            || !ResourceAllocator::validateDoesNotOverallocate((int)$parent['ram_mb'], $maps['ram_mb'])
            || !ResourceAllocator::validateDoesNotOverallocate((int)$parent['storage_mb'], $maps['storage_mb'])
            || !ResourceAllocator::validateDoesNotOverallocate((int)$parent['bandwidth_mbps'], $maps['bandwidth_mbps'])
            || !ResourceAllocator::validateDoesNotOverallocate((int)$parent['pids'], $maps['pids'])
        ) {
            $this->v2Error('validation_error', 'Resource allocations exceed the environment\'s remaining limits', 422);
            return;
        }

        $envVars = $data['environment_variables'] ?? null;
        $envVarsJson = null;
        if (is_array($envVars)) {
            $tmp = [];
            foreach ($envVars as $k => $v) {
                $kk = trim((string)$k);
                if ($kk === '') continue;
                $tmp[$kk] = (string)($v ?? '');
            }
            $envVarsJson = !empty($tmp) ? json_encode($tmp) : null;
        }

        $buildPack = trim((string)($data['build_pack'] ?? 'docker-compose'));
        if ($buildPack === '') $buildPack = 'docker-compose';

        $app = Application::create([
            'user_id' => $ownerId,
            'environment_id' => (int)$environment->id,
            'node_id' => (int)$node->id,
            'name' => $name,
            'description' => array_key_exists('description', $data) ? (string)($data['description'] ?? '') : null,
            'build_pack' => $buildPack,
            'git_repository' => array_key_exists('git_repository', $data) ? (string)($data['git_repository'] ?? '') : null,
            'git_branch' => array_key_exists('git_branch', $data) ? (string)($data['git_branch'] ?? '') : 'main',
            'environment_variables' => $envVarsJson,
            'memory_limit' => $memoryLimitForContainer,
            'cpu_limit' => $cpuLimitForContainer,
            'cpu_millicores_limit' => $cpuMillicores,
            'ram_mb_limit' => $ramMb,
            'storage_mb_limit' => $storageMbLimit,
            'port_limit' => -1,
            'bandwidth_mbps_limit' => $bandwidthLimit,
            'pids_limit' => $pidsLimit,
            'health_check_enabled' => 1,
            'health_check_path' => '/',
            'status' => 'stopped',
        ]);

        $this->ok([
            'data' => [
                'application_id' => (string)$app->uuid,
                'application' => [
                    'id' => (string)$app->uuid,
                    'uuid' => (string)$app->uuid,
                    'name' => (string)$app->name,
                    'team_id' => (string)$team->uuid,
                    'project_id' => (string)$project->uuid,
                    'environment_id' => (string)$environment->uuid,
                    'node_id' => (string)$node->uuid,
                    'owner_user_id' => $ownerUuid !== '' ? $ownerUuid : null,
                ],
            ],
        ], 201);
    }

    public function update(string $application_id): void
    {
        $key = $this->requirePlatformScope('applications:write');
        if (!$key) return;

        $app = Application::findByUuid($application_id);
        if (!$app) {
            $this->v2Error('not_found', 'Application not found', 404);
            return;
        }

        $env = $app->environment();
        $project = $env?->project();
        $team = $project ? Team::find((int)$project->team_id) : null;
        if (!$env || !$project || !$team) {
            $this->v2Error('not_found', 'Application not found', 404);
            return;
        }

        if (!$this->requirePlatformConstraints($key, [
            'team_id' => (string)$team->uuid,
            'project_id' => (string)$project->uuid,
            'environment_id' => (string)$env->uuid,
            'application_id' => (string)$app->uuid,
            'node_id' => $app->node()?->uuid ? (string)$app->node()?->uuid : null,
        ])) return;

        $data = $this->all();
        $update = [];

        foreach (['name','description','git_repository','git_branch','build_pack','memory_limit','cpu_limit'] as $k) {
            if (array_key_exists($k, $data)) {
                $update[$k] = $data[$k];
            }
        }

        if (array_key_exists('owner_user_id', $data) || array_key_exists('user_id', $data)) {
            $ownerUuid = trim((string)($data['owner_user_id'] ?? $data['user_id'] ?? ''));
            if ($ownerUuid === '') {
                $update['user_id'] = null;
            } else {
                $owner = User::findByUuid($ownerUuid);
                if (!$owner) {
                    $this->v2Error('validation_error', 'Owner user not found', 422, ['field' => 'owner_user_id']);
                    return;
                }
                $update['user_id'] = (int)$owner->id;
            }
        }

        if (array_key_exists('environment_variables', $data) && is_array($data['environment_variables'])) {
            $tmp = [];
            foreach ($data['environment_variables'] as $k => $v) {
                $kk = trim((string)$k);
                if ($kk === '') continue;
                $tmp[$kk] = (string)($v ?? '');
            }
            $update['environment_variables'] = !empty($tmp) ? json_encode($tmp) : null;
        }

        if (empty($update)) {
            $this->ok(['data' => ['updated' => false]]);
            return;
        }

        $app->update($update);
        $this->ok(['data' => ['updated' => true]]);
    }
}
