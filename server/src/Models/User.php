<?php

namespace Chap\Models;

use Chap\App;

/**
 * User Model
 */
class User extends BaseModel
{
    protected static string $table = 'users';
    protected static array $fillable = [
        'email', 'username', 'password_hash', 'name', 'avatar_url',
        'github_id', 'github_token', 'is_admin', 'email_verified_at',
        'two_factor_secret', 'two_factor_enabled'
    ];
    protected static array $hidden = ['password_hash', 'github_token', 'two_factor_secret'];

    public string $email = '';
    public string $username = '';
    public string $password_hash = '';
    public ?string $name = null;
    public ?string $avatar_url = null;
    public ?string $github_id = null;
    public ?string $github_token = null;
    public bool $is_admin = false;
    public ?string $email_verified_at = null;
    public ?string $two_factor_secret = null;
    public bool $two_factor_enabled = false;

    /**
     * Find user by email
     */
    public static function findByEmail(string $email): ?self
    {
        return self::findBy('email', $email);
    }

    /**
     * Verify password against stored hash
     */
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password_hash);
    }

    /**
     * Find user by username
     */
    public static function findByUsername(string $username): ?self
    {
        return self::findBy('username', $username);
    }

    /**
     * Find user by GitHub ID
     */
    public static function findByGitHubId(string $githubId): ?self
    {
        return self::findBy('github_id', $githubId);
    }

    /**
     * Get user's teams
     */
    public function teams(): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT t.*, tu.role FROM teams t 
             JOIN team_user tu ON t.id = tu.team_id 
             WHERE tu.user_id = ?
             ORDER BY t.name",
            [$this->id]
        );
        
        return array_map(fn($data) => Team::fromArray($data), $results);
    }

    /**
     * Get user's personal team
     */
    public function personalTeam(): ?Team
    {
        $db = App::db();
        $data = $db->fetch(
            "SELECT t.* FROM teams t 
             JOIN team_user tu ON t.id = tu.team_id 
             WHERE tu.user_id = ? AND t.personal_team = 1
             LIMIT 1",
            [$this->id]
        );
        
        return $data ? Team::fromArray($data) : null;
    }

    /**
     * Get user's current team (from session)
     */
    public function currentTeam(): ?Team
    {
        $teamId = $_SESSION['current_team_id'] ?? null;
        
        if ($teamId) {
            $team = Team::find($teamId);
            if ($team && $this->belongsToTeam($team)) {
                return $team;
            }
        }
        
        return $this->personalTeam();
    }

    /**
     * Switch current team
     */
    public function switchTeam(Team $team): bool
    {
        if (!$this->belongsToTeam($team)) {
            return false;
        }
        
        $_SESSION['current_team_id'] = $team->id;
        return true;
    }

    /**
     * Check if user belongs to team
     */
    public function belongsToTeam(Team $team): bool
    {
        $db = App::db();
        $result = $db->fetch(
            "SELECT 1 FROM team_user WHERE team_id = ? AND user_id = ?",
            [$team->id, $this->id]
        );
        
        return $result !== null;
    }

    /**
     * Get role in team
     */
    public function teamRole(Team $team): ?string
    {
        $db = App::db();
        $result = $db->fetch(
            "SELECT role FROM team_user WHERE team_id = ? AND user_id = ?",
            [$team->id, $this->id]
        );
        
        return $result['role'] ?? null;
    }

    /**
     * Check if user is team owner
     */
    public function isTeamOwner(Team $team): bool
    {
        return $this->teamRole($team) === 'owner';
    }

    /**
     * Check if user is team admin
     */
    public function isTeamAdmin(Team $team): bool
    {
        $role = $this->teamRole($team);
        return in_array($role, ['owner', 'admin']);
    }

    /**
     * Create personal team for user
     */
    public function createPersonalTeam(): Team
    {
        $db = App::db();
        
        $teamId = $db->insert('teams', [
            'uuid' => uuid(),
            'name' => $this->username . "'s Team",
            'description' => 'Personal team',
            'personal_team' => true,
        ]);
        
        $db->insert('team_user', [
            'team_id' => $teamId,
            'user_id' => $this->id,
            'role' => 'owner',
        ]);
        
        return Team::find($teamId);
    }

    /**
     * Get display name
     */
    public function displayName(): string
    {
        return $this->name ?: $this->username;
    }

    /**
     * Check if email is verified
     */
    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Get avatar URL (with fallback)
     */
    public function getAvatarUrl(): string
    {
        if ($this->avatar_url) {
            return $this->avatar_url;
        }
        
        // Gravatar fallback
        $hash = md5(strtolower(trim($this->email)));
        return "https://www.gravatar.com/avatar/{$hash}?d=mp&s=200";
    }
}
