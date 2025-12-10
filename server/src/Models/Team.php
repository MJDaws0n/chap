<?php

namespace Chap\Models;

use Chap\App;

/**
 * Team Model
 */
class Team extends BaseModel
{
    protected static string $table = 'teams';
    protected static array $fillable = ['name', 'description', 'personal_team'];

    public string $name = '';
    public ?string $description = null;
    public bool $personal_team = false;

    // Role from join (when fetched through user)
    public ?string $role = null;

    /**
     * Get team members
     */
    public function members(): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT u.*, tu.role FROM users u 
             JOIN team_user tu ON u.id = tu.user_id 
             WHERE tu.team_id = ?
             ORDER BY tu.role, u.name",
            [$this->id]
        );
        
        return array_map(function($data) {
            $user = User::fromArray($data);
            $user->role = $data['role'] ?? null;
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
        $db = App::db();
        $userId = $user instanceof User ? $user->id : $user;
        $result = $db->fetch(
            "SELECT 1 FROM team_user WHERE team_id = ? AND user_id = ? AND role IN ('owner', 'admin')",
            [$this->id, $userId]
        );
        
        return $result !== null;
    }

    /**
     * Get member's role
     */
    public function getMemberRole(User|int $user): ?string
    {
        $db = App::db();
        $userId = $user instanceof User ? $user->id : $user;
        $result = $db->fetch(
            "SELECT role FROM team_user WHERE team_id = ? AND user_id = ?",
            [$this->id, $userId]
        );
        
        return $result['role'] ?? null;
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
