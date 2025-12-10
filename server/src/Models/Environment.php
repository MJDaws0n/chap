<?php

namespace Chap\Models;

use Chap\App;

/**
 * Environment Model
 */
class Environment extends BaseModel
{
    protected static string $table = 'environments';
    protected static array $fillable = ['project_id', 'name', 'description'];

    public int $project_id;
    public string $name = '';
    public ?string $description = null;

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
