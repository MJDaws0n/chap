<?php

namespace Chap\Models;

use Chap\App;

/**
 * Container Model
 */
class Container extends BaseModel
{
    protected static string $table = 'containers';
    protected static array $fillable = [
        'node_id', 'deployment_id', 'container_id', 'name', 
        'image', 'status', 'ports'
    ];

    public int $node_id;
    public ?int $deployment_id = null;
    public string $container_id = '';
    public string $name = '';
    public string $image = '';
    public string $status = 'created';
    public ?string $ports = null;

    /**
     * Get node
     */
    public function node(): ?Node
    {
        return Node::find($this->node_id);
    }

    /**
     * Get deployment
     */
    public function deployment(): ?Deployment
    {
        return $this->deployment_id ? Deployment::find($this->deployment_id) : null;
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
     * Update status
     */
    public function updateStatus(string $status): void
    {
        $db = App::db();
        $db->update('containers', ['status' => $status], 'id = ?', [$this->id]);
        $this->status = $status;
    }

    /**
     * Check if running
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Find by container ID
     */
    public static function findByContainerId(string $containerId): ?self
    {
        return self::findBy('container_id', $containerId);
    }

    /**
     * Get containers for node
     */
    public static function forNode(int $nodeId): array
    {
        return self::where('node_id', $nodeId);
    }

    /**
     * Get running containers for node
     */
    public static function runningForNode(int $nodeId): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT * FROM containers WHERE node_id = ? AND status = 'running'",
            [$nodeId]
        );
        
        return array_map(fn($data) => self::fromArray($data), $results);
    }
}
