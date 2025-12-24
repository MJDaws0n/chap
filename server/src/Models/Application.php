<?php

namespace Chap\Models;

use Chap\App;
use Chap\WebSocket\Server as WebSocketServer;
use Chap\Services\DeploymentService;

/**
 * Application Model
 */
class Application extends BaseModel
{
    protected static string $table = 'applications';
    protected static array $fillable = [
        'environment_id', 'node_id', 'name', 'description',
        'git_repository', 'git_branch', 'git_commit_sha',
        'build_pack', 'dockerfile_path', 'docker_compose_path', 'build_context',
        'port', 'domains', 'environment_variables', 'build_args',
        'memory_limit', 'cpu_limit',
        'health_check_enabled', 'health_check_path', 'health_check_interval',
        'status'
    ];

    public int $environment_id;
    public ?int $node_id = null;
    public string $name = '';
    public ?string $description = null;

    // Git configuration
    public ?string $git_repository = null;
    public string $git_branch = 'main';
    public ?string $git_commit_sha = null;

    // Build configuration
    public string $build_pack = 'dockerfile';
    public string $dockerfile_path = 'Dockerfile';
    public string $docker_compose_path = 'docker-compose.yml';
    public string $build_context = '.';

    // Runtime configuration
    public ?int $port = null;
    public ?string $domains = null;
    public ?string $environment_variables = null;
    public ?string $build_args = null;

    // Resource limits
    public string $memory_limit = '512m';
    public string $cpu_limit = '1';

    // Health check
    public bool $health_check_enabled = true;
    public string $health_check_path = '/';
    public int $health_check_interval = 30;

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
     * Get domains as array
     */
    public function getDomains(): array
    {
        if (!$this->domains) {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $this->domains)));
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
        // Send delete command to node to remove containers
        if ($this->node_id) {
            $node = Node::find($this->node_id);
            if ($node) {
                $task = [
                    'type' => 'application:delete',
                    'payload' => [
                        'application_uuid' => $this->uuid,
                        'application_id' => $this->uuid,
                        'build_pack' => $this->build_pack,
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
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'git_repository' => $this->git_repository,
            'git_branch' => $this->git_branch,
            'build_pack' => $this->build_pack,
            'dockerfile_path' => $this->dockerfile_path,
            'docker_compose_path' => $this->docker_compose_path,
            'build_context' => $this->build_context,
            'environment_variables' => $this->getEnvironmentVariables(),
            'build_args' => $this->getBuildArgs(),
            'port' => $this->port,
            'memory_limit' => $this->memory_limit,
            'cpu_limit' => $this->cpu_limit,
            'health_check_enabled' => $this->health_check_enabled,
            'health_check_path' => $this->health_check_path,
            'health_check_interval' => $this->health_check_interval,
        ];
    }
}
