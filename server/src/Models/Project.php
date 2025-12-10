<?php

namespace Chap\Models;

use Chap\App;

/**
 * Project Model
 */
class Project extends BaseModel
{
    protected static string $table = 'projects';
    protected static array $fillable = ['team_id', 'name', 'description'];

    public int $team_id;
    public string $name = '';
    public ?string $description = null;

    /**
     * Get team
     */
    public function team(): ?Team
    {
        return Team::find($this->team_id);
    }

    /**
     * Get environments
     */
    public function environments(): array
    {
        return Environment::where('project_id', $this->id);
    }

    /**
     * Create default environment
     */
    public function createDefaultEnvironment(): Environment
    {
        return Environment::create([
            'project_id' => $this->id,
            'name' => 'Production',
            'description' => 'Production environment',
        ]);
    }

    /**
     * Get projects for team
     */
    public static function forTeam(int $teamId): array
    {
        return self::where('team_id', $teamId);
    }

    /**
     * Get application count
     */
    public function applicationCount(): int
    {
        $db = App::db();
        $result = $db->fetch(
            "SELECT COUNT(*) as count FROM applications a 
             JOIN environments e ON a.environment_id = e.id 
             WHERE e.project_id = ?",
            [$this->id]
        );
        
        return $result['count'] ?? 0;
    }
}
