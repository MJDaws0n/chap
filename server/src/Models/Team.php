<?php

namespace Chap\Models;

use Chap\App;
use Chap\Auth\TeamPermissionService;

/**
 * Team Model
 */
class Team extends BaseModel
{
    protected static string $table = 'teams';
    protected static array $fillable = [
        'name', 'description', 'personal_team',
        'cpu_millicores_limit', 'ram_mb_limit', 'storage_mb_limit',
        'port_limit', 'bandwidth_mbps_limit', 'pids_limit',
        'allowed_node_ids'
    ];

    public string $name = '';
    public ?string $description = null;
    public bool $personal_team = false;

    // Resource limits (configured)
    public int $cpu_millicores_limit = -1;
    public int $ram_mb_limit = -1;
    public int $storage_mb_limit = -1;
    public int $port_limit = -1;
    public int $bandwidth_mbps_limit = -1;
    public int $pids_limit = -1;
    public ?string $allowed_node_ids = null;

    // Role from join (when fetched through user)
    public ?string $role = null;

    /**
     * Get team members
     */
    public function members(): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT u.*, tu.role,
                    GROUP_CONCAT(tr.slug ORDER BY tr.hierarchy_level DESC SEPARATOR ',') AS role_slugs,
                    GROUP_CONCAT(tr.name ORDER BY tr.hierarchy_level DESC SEPARATOR ',') AS role_names
             FROM users u
             JOIN team_user tu ON u.id = tu.user_id
             LEFT JOIN team_user_roles tur ON tur.team_id = tu.team_id AND tur.user_id = u.id
             LEFT JOIN team_roles tr ON tr.id = tur.role_id
             WHERE tu.team_id = ?
             GROUP BY u.id, tu.role
             ORDER BY tu.role, u.name",
            [$this->id]
        );
        
        return array_map(function($data) {
            $user = User::fromArray($data);
            $user->role = $data['role'] ?? null;
            $slugs = isset($data['role_slugs']) && is_string($data['role_slugs']) && $data['role_slugs'] !== ''
                ? array_values(array_filter(explode(',', $data['role_slugs'])))
                : [];
            $names = isset($data['role_names']) && is_string($data['role_names']) && $data['role_names'] !== ''
                ? array_values(array_filter(explode(',', $data['role_names'])))
                : [];
            $user->team_role_slugs = $slugs;
            $user->team_role_names = $names;
            return $user;
        }, $results);
    }

    /**
     * Get team owner
     */
    public function owner(): ?User
    {
        $db = App::db();
        $data = $db->fetch(
            "SELECT u.* FROM users u 
             JOIN team_user tu ON u.id = tu.user_id 
             WHERE tu.team_id = ? AND tu.role = 'owner'
             LIMIT 1",
            [$this->id]
        );
        
        return $data ? User::fromArray($data) : null;
    }

    /**
     * Add member to team (accepts User object or user ID)
     */
    public function addMember(User|int $user, string $role = 'member'): bool
    {
        $db = App::db();
        $userId = $user instanceof User ? $user->id : $user;
        
        try {
            $db->insert('team_user', [
                'team_id' => $this->id,
                'user_id' => $userId,
                'role' => $role,
            ]);
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Remove member from team (accepts User object or user ID)
     */
    public function removeMember(User|int $user): bool
    {
        $db = App::db();
        $userId = $user instanceof User ? $user->id : $user;
        return $db->delete('team_user', 'team_id = ? AND user_id = ?', [$this->id, $userId]) > 0;
    }

    /**
     * Update member role
     */
    public function updateMemberRole(User|int $user, string $role): bool
    {
        $db = App::db();
        $userId = $user instanceof User ? $user->id : $user;
        return $db->update('team_user', ['role' => $role], 'team_id = ? AND user_id = ?', [$this->id, $userId]) > 0;
    }


    /**
     * Get nodes for team
     */
    public function nodes(): array
    {
        return Node::where('team_id', $this->id);
    }

    /**
     * Get projects for team
     */
    public function projects(): array
    {
        return Project::where('team_id', $this->id);
    }

    /**
     * Check if team has member (accepts User object or user ID)
     */
    public function hasMember(User|int $user): bool
    {
        $db = App::db();
        $userId = $user instanceof User ? $user->id : $user;
        $result = $db->fetch(
            "SELECT 1 FROM team_user WHERE team_id = ? AND user_id = ?",
            [$this->id, $userId]
        );
        
        return $result !== null;
    }

    /**
     * Check if user is owner of team
     */
    public function isOwner(User|int $user): bool
    {
        $db = App::db();
        $userId = $user instanceof User ? $user->id : $user;
        $result = $db->fetch(
            "SELECT 1 FROM team_user WHERE team_id = ? AND user_id = ? AND role = 'owner'",
            [$this->id, $userId]
        );
        
        return $result !== null;
    }

    /**
     * Check if user is admin of team
     */
    public function isAdmin(User|int $user): bool
    {
        $userId = $user instanceof User ? (int)$user->id : (int)$user;
        $highest = TeamPermissionService::highestRole((int)$this->id, $userId);
        return in_array((string)$highest['slug'], ['owner', 'admin'], true);
    }

    /**
     * Get member's role
     */
    public function getMemberRole(User|int $user): ?string
    {
        $userId = $user instanceof User ? (int)$user->id : (int)$user;
        $highest = TeamPermissionService::highestRole((int)$this->id, $userId);
        return (string)$highest['slug'];
    }

    /**
     * List roles for this team (built-in + custom).
     *
     * @return array<int, array<string, mixed>>
     */
    public function roles(): array
    {
        $db = App::db();
        return $db->fetchAll(
            "SELECT * FROM team_roles WHERE team_id = ? ORDER BY hierarchy_level DESC, name ASC",
            [$this->id]
        );
    }

    /**
     * Get member count
     */
    public function memberCount(): int
    {
        $db = App::db();
        $result = $db->fetch(
            "SELECT COUNT(*) as count FROM team_user WHERE team_id = ?",
            [$this->id]
        );
        
        return $result['count'] ?? 0;
    }
}
