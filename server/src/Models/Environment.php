<?php

namespace Chap\Models;

use Chap\App;

/**
 * Environment Model
 */
class Environment extends BaseModel
{
    protected static string $table = 'environments';
    protected static array $fillable = [
        'project_id', 'name', 'description',
        'cpu_millicores_limit', 'ram_mb_limit', 'storage_mb_limit',
        'port_limit', 'bandwidth_mbps_limit', 'pids_limit',
        'allowed_node_ids'
    ];

    public int $project_id;
    public string $name = '';
    public ?string $description = null;

    // Resource limits (configured)
    public int $cpu_millicores_limit = -1;
    public int $ram_mb_limit = -1;
    public int $storage_mb_limit = -1;
    public int $port_limit = -1;
    public int $bandwidth_mbps_limit = -1;
    public int $pids_limit = -1;
    public ?string $allowed_node_ids = null;

    /**
     * Get project
     */
    public function project(): ?Project
    {
        return Project::find($this->project_id);
    }

    /**
     * Get applications
     */
    public function applications(): array
    {
        return Application::where('environment_id', $this->id);
    }

    /**
     * Get environments for project
     */
    public static function forProject(int $projectId): array
    {
        return self::where('project_id', $projectId);
    }

    /**
     * Get application count
     */
    public function applicationCount(): int
    {
        $db = App::db();
        $result = $db->fetch(
            "SELECT COUNT(*) as count FROM applications WHERE environment_id = ?",
            [$this->id]
        );
        
        return $result['count'] ?? 0;
    }
}
