<?php

namespace Chap\Models;

use Chap\App;

/**
 * Service Model
 * 
 * Represents a one-click service deployed from a template
 */
class Service extends BaseModel
{
    protected static string $table = 'services';
    protected static array $fillable = [
        'environment_id', 'node_id', 'name', 'description',
        'template_id', 'docker_compose', 'environment_variables',
        'volumes', 'ports', 'status'
    ];

    public int $environment_id;
    public ?int $node_id = null;
    public string $name = '';
    public ?string $description = null;
    public ?int $template_id = null;
    public ?string $docker_compose = null;
    public ?string $environment_variables = null;
    public ?string $volumes = null;
    public ?string $ports = null;
    public string $status = 'draft';

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
     * Get template
     */
    public function template(): ?Template
    {
        return $this->template_id ? Template::find($this->template_id) : null;
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
     * Get volumes as array
     */
    public function getVolumes(): array
    {
        if (!$this->volumes) {
            return [];
        }
        return json_decode($this->volumes, true) ?: [];
    }

    /**
     * Get ports as array
     */
    public function getPorts(): array
    {
        if (!$this->ports) {
            return [];
        }
        return json_decode($this->ports, true) ?: [];
    }

    /**
     * Check if service is running
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Get services for environment
     */
    public static function forEnvironment(int $environmentId): array
    {
        return self::where('environment_id', $environmentId);
    }
}
