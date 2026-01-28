<?php

namespace Chap\Controllers\ApiV2\Platform;

use Chap\Models\Application;
use Chap\Models\Environment;
use Chap\Models\Node;
use Chap\Models\PortAllocation;
use Chap\Models\Team;
use Chap\Models\Template;
use Chap\Models\User;
use Chap\Services\DeploymentService;
use Chap\Services\DynamicEnv;
use Chap\Services\PortAllocator;
use Chap\Services\ResourceAllocator;
use Chap\Services\ResourceHierarchy;
use Chap\Services\TemplateRegistry;

class TemplatesController extends BasePlatformController
{
    public function index(): void
    {
        $this->requirePlatformScope('templates:read') ?? null;
        if (!$this->platformKey()) return;

        TemplateRegistry::syncToDatabase();
        $templates = Template::where('is_active', true);

        $data = array_map(function(Template $t) {
            return [
                'slug' => (string)$t->slug,
                'name' => (string)$t->name,
                'description' => $t->description,
                'category' => $t->category,
                'icon' => $t->icon,
                'documentation' => $t->documentation,
                'source_url' => $t->source_url,
                'version' => $t->version,
                'is_official' => (bool)$t->is_official,
                'is_active' => (bool)$t->is_active,
            ];
        }, $templates);

        $this->ok(['data' => $data]);
    }

    public function show(string $slug): void
    {
        $key = $this->requirePlatformScope('templates:read');
        if (!$key) return;

        TemplateRegistry::syncToDatabase();
        $t = Template::findBySlug($slug);
        if (!$t) {
            $this->v2Error('not_found', 'Template not found', 404);
            return;
        }

        $this->ok([
            'data' => [
                'slug' => (string)$t->slug,
                'name' => (string)$t->name,
                'description' => $t->description,
                'category' => $t->category,
                'icon' => $t->icon,
                'documentation' => $t->documentation,
                'source_url' => $t->source_url,
                'version' => $t->version,
                'is_official' => (bool)$t->is_official,
                'is_active' => (bool)$t->is_active,
                'default_environment_variables' => $t->getDefaultEnvironmentVariables(),
                'required_environment_variables' => $t->getRequiredEnvironmentVariables(),
                'ports' => $t->getPorts(),
                'volumes' => $t->getVolumes(),
            ],
        ]);
    }

    public function sync(): void
    {
        $this->requirePlatformScope('templates:write') ?? null;
        if (!$this->platformKey()) return;

        TemplateRegistry::syncToDatabase();
        $this->ok(['data' => ['synced' => true]]);
    }

    /**
     * POST /api/v2/platform/templates/{slug}/deploy
     * Creates an application from a template, and (optionally) queues an initial deployment.
     *
     * Body: { environment_id, node_id, name?, environment_variables?, deploy?: boolean }
     */
    public function deploy(string $slug): void
    {
        $key = $this->requirePlatformScope('templates:deploy');
        if (!$key) return;

        TemplateRegistry::syncToDatabase();
        $template = Template::findBySlug($slug);
        if (!$template || !(bool)$template->is_active) {
            $this->v2Error('not_found', 'Template not found', 404);
            return;
        }

        $data = $this->all();
        $shouldDeploy = !array_key_exists('deploy', $data) || (bool)$data['deploy'] === true;

        $environmentUuid = trim((string)($data['environment_id'] ?? $data['environment_uuid'] ?? ''));
        $nodeUuid = trim((string)($data['node_id'] ?? $data['node_uuid'] ?? ''));
        $name = trim((string)($data['name'] ?? $template->name));
        if ($name === '') $name = (string)$template->name;

        $environment = $environmentUuid !== '' ? Environment::findByUuid($environmentUuid) : null;
        if (!$environment) {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'environment_id']);
            return;
        }
        $project = $environment->project();
        $team = $project ? Team::find((int)$project->team_id) : null;
        if (!$project || !$team) {
            $this->v2Error('not_found', 'Environment not found', 404);
            return;
        }

        $node = $nodeUuid !== '' ? Node::findByUuid($nodeUuid) : null;
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

        // Resource limits (optional). Derive dynamic env ctx values from effective container limits.
        $cpuLimitInputRaw = trim((string)($data['cpu_limit'] ?? ''));
        $memoryLimitInputRaw = trim((string)($data['memory_limit'] ?? ''));

        $cpuLimitForContainer = $cpuLimitInputRaw !== '' ? $cpuLimitInputRaw : '1';
        $memoryLimitForContainer = $memoryLimitInputRaw !== '' ? $memoryLimitInputRaw : '512m';

        $cpuMillicores = -1;
        $ramMb = -1;
        $cpuForCtx = null;
        $ramMbForCtx = null;
        try {
            if ($cpuLimitInputRaw !== '') {
                $cpuMillicores = ResourceHierarchy::parseCpuMillicores($cpuLimitInputRaw);
            }
            if ($memoryLimitInputRaw !== '') {
                $ramMb = ResourceHierarchy::parseDockerMemoryToMb($memoryLimitInputRaw);
            }

            $cpuForCtx = ResourceHierarchy::cpuToCoresString(ResourceHierarchy::parseCpuMillicores($cpuLimitForContainer));
            $ramMbForCtx = ResourceHierarchy::parseDockerMemoryToMb($memoryLimitForContainer);
        } catch (\Throwable $e) {
            $this->v2Error('validation_error', $e->getMessage(), 422);
            return;
        }

        // Merge template defaults + provided env vars.
        $envVars = $template->getDefaultEnvironmentVariables();
        $provided = $data['environment_variables'] ?? null;
        if (is_array($provided)) {
            foreach ($provided as $k => $v) {
                $kk = trim((string)$k);
                if ($kk === '') continue;
                $envVars[$kk] = (string)($v ?? '');
            }
        }
        foreach ($template->getRequiredEnvironmentVariables() as $k) {
            $kk = trim((string)$k);
            if ($kk === '') continue;
            if (!array_key_exists($kk, $envVars)) {
                $envVars[$kk] = '';
            }
        }

        $reservationUuid = trim((string)($data['port_reservation_uuid'] ?? ''));
        $reservedPorts = [];
        $autoReservedPorts = false;

        $portsConfig = $template->getPorts();
        $requiredPortCount = is_array($portsConfig) ? (int)($portsConfig['required_count'] ?? 0) : 0;

        if ($reservationUuid === '' && $requiredPortCount > 0) {
            $reservationUuid = uuid();
            $autoReservedPorts = true;
            try {
                for ($i = 0; $i < $requiredPortCount; $i++) {
                    PortAllocator::allocateForReservation($reservationUuid, (int)$node->id, null);
                }
            } catch (\Throwable $e) {
                PortAllocator::releaseReservation($reservationUuid, (int)$node->id);
                $this->v2Error('validation_error', $e->getMessage(), 422);
                return;
            }
        }
        if ($reservationUuid !== '') {
            $reservedPorts = PortAllocation::portsForReservation($reservationUuid, (int)$node->id);
        }

        if ($requiredPortCount > 0 && count($reservedPorts) < $requiredPortCount) {
            if ($autoReservedPorts && $reservationUuid !== '') {
                PortAllocator::releaseReservation($reservationUuid, (int)$node->id);
            }
            $this->v2Error('validation_error', 'Not enough reserved ports for this template', 422, [
                'field' => 'port_reservation_uuid',
                'required_count' => $requiredPortCount,
                'reserved_count' => count($reservedPorts),
            ]);
            return;
        }

        $ctx = [
            'name' => (string)$name,
            'node' => (string)$node->name,
            'repo' => '',
            'repo_brach' => 'main',
            'repo_branch' => 'main',
            'cpu' => $cpuForCtx,
            'ram' => $ramMbForCtx,
        ];
        $dynErrors = DynamicEnv::validate($envVars, $reservedPorts, $ctx);
        if (!empty($dynErrors)) {
            if ($autoReservedPorts && $reservationUuid !== '') {
                PortAllocator::releaseReservation($reservationUuid, (int)$node->id);
            }
            $this->v2Error('validation_error', 'One or more environment variables reference unavailable dynamic values', 422, [
                'field' => 'environment_variables',
            ]);
            return;
        }

        $storageMbLimit = ResourceHierarchy::parseMb((string)($data['storage_mb_limit'] ?? '-1'));
        $bandwidthLimit = ResourceHierarchy::parseIntOrAuto((string)($data['bandwidth_mbps_limit'] ?? '-1'));
        $pidsLimit = ResourceHierarchy::parseIntOrAuto((string)($data['pids_limit'] ?? '-1'));

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
            if ($autoReservedPorts && $reservationUuid !== '') {
                PortAllocator::releaseReservation($reservationUuid, (int)$node->id);
            }
            $this->v2Error('validation_error', 'Resource allocations exceed the environment\'s remaining limits', 422);
            return;
        }

        $app = Application::create([
            'user_id' => $ownerId,
            'environment_id' => (int)$environment->id,
            'node_id' => (int)$node->id,
            'name' => $name,
            'description' => $template->description,
            'build_pack' => 'docker-compose',
            'git_repository' => null,
            'git_branch' => 'main',
            'environment_variables' => !empty($envVars) ? json_encode($envVars) : null,
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
            'template_slug' => (string)$template->slug,
            'template_version' => $template->version,
            'template_docker_compose' => (string)$template->docker_compose,
            'template_extra_files' => !empty($template->extra_files) ? (is_string($template->extra_files) ? $template->extra_files : json_encode($template->extra_files)) : null,
        ]);

        if ($reservationUuid !== '' && $app->node_id) {
            PortAllocator::attachReservationToApplication($reservationUuid, (int)$app->node_id, (int)$app->id);
        }

        $allocatedPorts = PortAllocation::portsForApplication((int)$app->id);

        if (!$shouldDeploy) {
            $this->ok([
                'data' => [
                    'application' => [
                        'id' => (string)$app->uuid,
                        'uuid' => (string)$app->uuid,
                        'name' => (string)$app->name,
                        'environment_id' => (string)($environment->uuid ?? ''),
                        'project_id' => (string)($project->uuid ?? ''),
                        'team_id' => (string)($team->uuid ?? ''),
                        'node_id' => (string)($node->uuid ?? ''),
                        'template_slug' => (string)$template->slug,
                        'ports' => $allocatedPorts,
                    ],
                    'deployment' => null,
                ],
            ], 201);
            return;
        }

        try {
            $deployment = DeploymentService::create($app, null, [
                'triggered_by' => 'template',
                'triggered_by_name' => 'platform',
            ]);
        } catch (\Throwable $e) {
            $this->v2Error('validation_error', $e->getMessage(), 422);
            return;
        }

        $this->ok([
            'data' => [
                'application' => [
                    'id' => (string)$app->uuid,
                    'uuid' => (string)$app->uuid,
                    'name' => (string)$app->name,
                    'environment_id' => (string)($environment->uuid ?? ''),
                    'project_id' => (string)($project->uuid ?? ''),
                    'team_id' => (string)($team->uuid ?? ''),
                    'node_id' => (string)($node->uuid ?? ''),
                    'template_slug' => (string)$template->slug,
                    'ports' => $allocatedPorts,
                ],
                'deployment' => [
                    'id' => (string)$deployment->uuid,
                    'uuid' => (string)$deployment->uuid,
                    'status' => (string)$deployment->status,
                    'commit_sha' => $deployment->git_commit_sha ?? $deployment->commit_sha,
                ],
            ],
        ], 201);
    }
}
