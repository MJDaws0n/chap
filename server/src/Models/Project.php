<?php

namespace Chap\Models;

use Chap\App;

/**
 * Project Model
 */
class Project extends BaseModel
{
    protected static string $table = 'projects';
    protected static array $fillable = [
        'team_id', 'name', 'description',
        'cpu_millicores_limit', 'ram_mb_limit', 'storage_mb_limit',
        'port_limit', 'bandwidth_mbps_limit', 'pids_limit',
        'allowed_node_ids'
    ];

    public int $team_id;
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

    /**
     * Project members (explicit membership)
     */
    public function members(): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT u.*, pu.role FROM users u
             JOIN project_user pu ON u.id = pu.user_id
             WHERE pu.project_id = ?
             ORDER BY pu.role, u.name",
            [$this->id]
        );

        return array_map(function($data) {
            $user = User::fromArray($data);
            $user->project_role = $data['role'] ?? null;
            return $user;
        }, $results);
    }

    public function hasMember(User|int $user): bool
    {
        $db = App::db();
        $userId = $user instanceof User ? $user->id : $user;
        $result = $db->fetch(
            "SELECT 1 FROM project_user WHERE project_id = ? AND user_id = ?",
            [$this->id, $userId]
        );
        return $result !== null;
    }

    public function addMember(User|int $user, string $role = 'member'): bool
    {
        $db = App::db();
        $userId = $user instanceof User ? $user->id : $user;

        try {
            $db->insert('project_user', [
                'project_id' => $this->id,
                'user_id' => $userId,
                'role' => $role,
            ]);
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    public function removeMember(User|int $user): bool
    {
        $db = App::db();
        $userId = $user instanceof User ? $user->id : $user;
        return $db->delete('project_user', 'project_id = ? AND user_id = ?', [$this->id, $userId]) > 0;
    }

    public function updateMemberRole(User|int $user, string $role): bool
    {
        $db = App::db();
        $userId = $user instanceof User ? $user->id : $user;
        return $db->update('project_user', ['role' => $role], 'project_id = ? AND user_id = ?', [$this->id, $userId]) > 0;
    }

}
