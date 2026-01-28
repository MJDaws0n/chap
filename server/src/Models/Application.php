<?php

namespace Chap\Models;

use Chap\App;
use Chap\WebSocket\Server as WebSocketServer;
use Chap\Services\DeploymentService;
use Chap\Services\DynamicEnv;
use Chap\Services\ResourceHierarchy;
use Chap\Services\PortAllocator;
use Chap\Services\TemplateRegistry;
use Chap\Models\PortAllocation;

/**
 * Application Model
 */
class Application extends BaseModel
{
    protected static string $table = 'applications';
    protected static array $fillable = [
        'user_id',
        'environment_id', 'node_id', 'name', 'description',
        'git_repository', 'git_branch', 'git_commit_sha',
        'build_pack', 'dockerfile_path', 'docker_compose_path', 'build_context',
        'template_slug', 'template_version', 'template_docker_compose', 'template_extra_files',
        'port', 'domains', 'environment_variables', 'build_args',
        'memory_limit', 'cpu_limit',
        'cpu_millicores_limit', 'ram_mb_limit', 'storage_mb_limit',
        'port_limit', 'bandwidth_mbps_limit', 'pids_limit',
        'allowed_node_ids',
        'health_check_enabled', 'health_check_path', 'health_check_interval',
        'notification_settings',
        'status'
    ];

    public ?int $user_id = null;
    public int $environment_id;
    public ?int $node_id = null;
    public string $name = '';
    public ?string $description = null;

    // Git configuration
    public ?string $git_repository = null;
    public string $git_branch = 'main';
    public ?string $git_commit_sha = null;

    // Build configuration
    public string $build_pack = 'docker-compose';
    public string $dockerfile_path = 'Dockerfile';
    public string $docker_compose_path = 'docker-compose.yml';
    public string $build_context = '.';

    // Template configuration (optional)
    public ?string $template_slug = null;
    public ?string $template_version = null;
    public ?string $template_docker_compose = null;
    public mixed $template_extra_files = null;

    // Runtime configuration
    public ?int $port = null;
    public ?string $domains = null;
    public ?string $environment_variables = null;
    public ?string $build_args = null;

    // Resource limits
    public string $memory_limit = '512m';
    public string $cpu_limit = '1';

    // Configured limits used by resource hierarchy (-1 auto-split)
    public int $cpu_millicores_limit = -1;
    public int $ram_mb_limit = -1;
    public int $storage_mb_limit = -1;
    public int $port_limit = -1;
    public int $bandwidth_mbps_limit = -1;
    public int $pids_limit = -1;
    public ?string $allowed_node_ids = null;

    // Health check
    public bool $health_check_enabled = true;
    public string $health_check_path = '/';
    public int $health_check_interval = 30;

    // Notification settings
    public ?string $notification_settings = null;

    // State
    public string $status = 'stopped';

    /**
     * Get environment
     */
    public function environment(): ?Environment
    {
        return Environment::find($this->environment_id);
    }

    /**
     * Get node
     */
    public function node(): ?Node
    {
        return $this->node_id ? Node::find($this->node_id) : null;
    }

    /**
     * Get deployments
     */
    public function deployments(): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT * FROM deployments WHERE application_id = ? ORDER BY created_at DESC",
            [$this->id]
        );
        
        return array_map(fn($data) => Deployment::fromArray($data), $results);
    }

    /**
     * Get latest deployment
     */
    public function latestDeployment(): ?Deployment
    {
        $db = App::db();
        $data = $db->fetch(
            "SELECT * FROM deployments WHERE application_id = ? ORDER BY created_at DESC LIMIT 1",
            [$this->id]
        );
        
        return $data ? Deployment::fromArray($data) : null;
    }

    /**
     * Get current running deployment
     */
    public function currentDeployment(): ?Deployment
    {
        $db = App::db();
        $data = $db->fetch(
            "SELECT * FROM deployments WHERE application_id = ? AND status = 'running' ORDER BY created_at DESC LIMIT 1",
            [$this->id]
        );
        
        return $data ? Deployment::fromArray($data) : null;
    }

    /**
     * Get environment variables as array
     */
    public function getEnvironmentVariables(): array
    {
        if (!$this->environment_variables) {
            return [];
        }
        return json_decode($this->environment_variables, true) ?: [];
    }

    /**
     * Set environment variables
     */
    public function setEnvironmentVariables(array $vars): void
    {
        $this->environment_variables = json_encode($vars);
    }

    /**
     * Get build args as array
     */
    public function getBuildArgs(): array
    {
        if (!$this->build_args) {
            return [];
        }
        return json_decode($this->build_args, true) ?: [];
    }

    /**
     * @return array<string,string>
     */
    public function getTemplateExtraFiles(): array
    {
        $value = $this->template_extra_files;
        if ($value === null || $value === '') {
            // Back-compat: older applications may not have template_extra_files stored.
            // Fall back to the current Template record (which is sourced from templates/<slug>/files/*).
            $slug = trim((string)($this->template_slug ?? ''));
            if ($slug === '') {
                return [];
            }

            try {
                TemplateRegistry::syncToDatabase();
            } catch (\Throwable $e) {
                // ignore
            }

            $template = Template::findBySlug($slug);
            if (!$template) {
                return [];
            }

            $files = $template->getExtraFiles();
            if (empty($files)) {
                return [];
            }

            // Best-effort persist so future deploys don't need the fallback.
            try {
                $this->update(['template_extra_files' => json_encode($files)]);
            } catch (\Throwable $e) {
                // ignore
            }

            return $files;
        }
        if (is_array($value)) {
            /** @var array<string,string> */
            return $value;
        }
        if (!is_string($value)) {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get domains as array
     */
    public function getDomains(): array
    {
        if (!$this->domains) {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $this->domains)));
    }

    /** @return int[] */
    public function allocatedPorts(): array
    {
        return PortAllocation::portsForApplication((int)$this->id);
    }

    /**
     * Check if application is running
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Check if application is deploying
     */
    public function isDeploying(): bool
    {
        return $this->status === 'deploying';
    }

    /**
     * Get applications for environment
     */
    public static function forEnvironment(int $environmentId): array
    {
        return self::where('environment_id', $environmentId);
    }

    /**
     * Get status badge color
     */
    public function statusColor(): string
    {
        return match($this->status) {
            'running' => 'green',
            'deploying', 'starting' => 'yellow',
            'stopped' => 'gray',
            'error' => 'red',
            default => 'gray'
        };
    }

    /**
     * Delete application and its containers
     */
    public function delete(): bool
    {
        // Best-effort: release any allocated ports in DB (some installs may not have
        // the FK cascade on port_allocations.application_id).
        try {
            if (!empty($this->id)) {
                PortAllocator::releaseForApplication((int)$this->id);
            }
        } catch (\Throwable) {
            // ignore
        }

        // Send delete command to node to remove containers
        if ($this->node_id) {
            $node = Node::find($this->node_id);
            if ($node) {
                $deploymentIds = [];
                try {
                    foreach ($this->deployments() as $d) {
                        if (!empty($d->id)) {
                            $deploymentIds[] = (int)$d->id;
                        }
                    }
                } catch (\Throwable) {
                    // ignore
                }

                $taskId = bin2hex(random_bytes(16));
                $task = [
                    'type' => 'application:delete',
                    'payload' => [
                        'task_id' => $taskId,
                        'application_uuid' => $this->uuid,
                        'application_id' => $this->uuid,
                        'deployment_ids' => $deploymentIds,
                    ],
                ];
                
                // Store task in database for WebSocket polling
                $db = App::db();
                $db->query(
                    "INSERT INTO deployment_tasks (node_id, task_data, created_at, task_type) VALUES (?, ?, NOW(), ?)",
                    [$node->id, json_encode($task), $task['type']]
                );
                
                // Also try to send immediately if WebSocket is available
                try {
                    WebSocketServer::sendToNode($node->id, $task);
                } catch (\Throwable $e) {
                    // WebSocket might not be available, that's okay - will be picked up by polling
                }
            }
        }

        // Delete from database
        return parent::delete();
    }

    /**
     * Convert to deployment payload
     */
    public function toDeployPayload(): array
    {
        // Resolve effective limits (including -1 auto-split) at deploy time.
        $effective = ResourceHierarchy::effectiveApplicationLimits($this);

        $nodeName = '';
        if (!empty($this->node_id)) {
            $node = Node::find((int)$this->node_id);
            if ($node) {
                $nodeName = (string)$node->name;
            }
        }

        $ramMb = (int)($effective['ram_mb'] ?? -1);
        if ($ramMb === -1) {
            $ramMb = ResourceHierarchy::parseDockerMemoryToMb((string)($this->memory_limit ?? ''));
        }

        $cpuMillicores = (int)($effective['cpu_millicores'] ?? -1);
        if ($cpuMillicores === -1) {
            $cpuMillicores = ResourceHierarchy::parseCpuMillicores((string)($this->cpu_limit ?? ''));
        }
        $cpuCores = $cpuMillicores === -1 ? -1 : ResourceHierarchy::cpuToCoresString($cpuMillicores);

        $dynContext = [
            'name' => (string)$this->name,
            'node' => $nodeName,
            'repo' => (string)($this->git_repository ?? ''),
            // User-requested key (typo) and a correct alias
            'repo_brach' => (string)($this->git_branch ?? ''),
            'repo_branch' => (string)($this->git_branch ?? ''),
            'cpu' => $cpuCores,
            // RAM in MB as a plain number (no suffix)
            'ram' => $ramMb,
        ];

        $allocatedPorts = $this->allocatedPorts();

        $envVars = $this->getEnvironmentVariables();
        $resolved = DynamicEnv::resolve($envVars, $allocatedPorts, $dynContext);
        if (!empty($resolved['errors'])) {
            $details = [];
            foreach ($resolved['errors'] as $k => $msg) {
                $details[] = $k . ': ' . $msg;
            }
            throw new \RuntimeException('Environment variable validation failed: ' . implode(' | ', $details));
        }

        $payload = [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'git_repository' => $this->git_repository,
            'git_branch' => $this->git_branch,
            'build_pack' => 'docker-compose',
            'environment_variables' => $resolved['resolved'],
            'build_args' => $this->getBuildArgs(),
            'port' => $this->port,
            'allocated_ports' => $allocatedPorts,
            'health_check_enabled' => $this->health_check_enabled,
            'health_check_path' => $this->health_check_path,
            'health_check_interval' => $this->health_check_interval,
        ];

        // Template-based deploy: provide docker_compose + extra_files directly to the node.
        if (!empty($this->template_docker_compose)) {
            $payload['docker_compose'] = (string)$this->template_docker_compose;
            $payload['template_slug'] = $this->template_slug;
            $payload['template_version'] = $this->template_version;

            $extra = $this->getTemplateExtraFiles();
            if (!empty($extra)) {
                $payload['extra_files'] = $extra;
            }
        }

        // If limits are unlimited, omit them so the node falls back to CHAP_MAX_* defaults.
        if ((int)$effective['ram_mb'] !== -1) {
            $payload['memory_limit'] = ResourceHierarchy::ramMbToDockerString((int)$effective['ram_mb']);
        }
        if ((int)$effective['cpu_millicores'] !== -1) {
            $payload['cpu_limit'] = ResourceHierarchy::cpuToCoresString((int)$effective['cpu_millicores']);
        }

        return $payload;
    }
}
