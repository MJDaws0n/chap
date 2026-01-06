<?php

namespace Chap\Models;

use Chap\App;
use Chap\Auth\TeamPermissionService;
use Chap\Auth\TeamRoleSeeder;

/**
 * User Model
 */
class User extends BaseModel
{
    protected static string $table = 'users';
    protected static array $fillable = [
        'email', 'username', 'password_hash', 'name', 'avatar_url',
        'github_id', 'github_token', 'is_admin', 'email_verified_at',
        'two_factor_secret', 'two_factor_enabled', 'current_team_id',
        'max_cpu_millicores', 'max_ram_mb', 'max_storage_mb', 'max_ports',
        'max_bandwidth_mbps', 'max_pids',
        'max_teams', 'max_projects', 'max_environments', 'max_applications',
        'node_access_mode',
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
    public ?int $current_team_id = null;
    public ?string $email_verified_at = null;
    public ?string $two_factor_secret = null;
    public bool $two_factor_enabled = false;

    // Admin-set hard maximums
    public int $max_cpu_millicores = 2000;
    public int $max_ram_mb = 4096;
    public int $max_storage_mb = 20480;
    public int $max_ports = 50;
    public int $max_bandwidth_mbps = 100;
    public int $max_pids = 1024;
    public int $max_teams = 25;
    public int $max_projects = 50;
    public int $max_environments = 200;
    public int $max_applications = 200;

    // Node access mode
    public string $node_access_mode = 'allow_selected';

    // Joined/derived fields
    public ?string $role = null;
    public ?string $project_role = null;

    // Team role metadata (hydrated by Team::members())
    public array $team_role_slugs = [];
    public array $team_role_names = [];

    /**
     * Whether this user can select any team as current team.
     *
     * This is intentionally gated behind the admin "view all" mode so that
     * site admins only bypass team membership when they explicitly enable it.
     */
    private function canViewAllTeams(): bool
    {
        return (bool)$this->is_admin && (($_SESSION['admin_view_mode'] ?? 'personal') === 'all');
    }

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
            if ($team && ($this->belongsToTeam($team) || $this->canViewAllTeams())) {
                return $team;
            }
        }

        if (!empty($this->current_team_id)) {
            $team = Team::find((int)$this->current_team_id);
            if ($team && ($this->belongsToTeam($team) || $this->canViewAllTeams())) {
                $_SESSION['current_team_id'] = $team->id;
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
        if ($this->belongsToTeam($team)) {
            $_SESSION['current_team_id'] = $team->id;
            // Best-effort persist so new sessions can restore
            $this->update(['current_team_id' => $team->id]);
            return true;
        }

        if ($this->canViewAllTeams()) {
            // In admin "view all" mode, allow selecting any team as current team,
            // but do not persist this to the DB since the admin may not be a member.
            $_SESSION['current_team_id'] = $team->id;
            return true;
        }

        return false;
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
        if (in_array($role, ['owner', 'admin'], true)) {
            return true;
        }

        $highest = TeamPermissionService::highestRole((int)$team->id, (int)$this->id);
        return in_array((string)$highest['slug'], ['owner', 'admin'], true);
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
            'personal_team' => 1,
        ]);
        
        $db->insert('team_user', [
            'team_id' => $teamId,
            'user_id' => $this->id,
            'role' => 'owner',
        ]);

        TeamRoleSeeder::ensureBuiltins((int)$teamId);
        
        return Team::find($teamId);
    }

    /**
     * Delete the user and their personal team.
     *
     * Personal teams are linked via `team_user`, so deleting a user would
     * otherwise leave an orphaned `teams` row.
     */
    public function delete(): bool
    {
        $db = App::db();

        $transactionStarted = false;
        try {
            $transactionStarted = $db->beginTransaction();
        } catch (\Throwable) {
            // If a transaction cannot be started (e.g., nested transactions),
            // proceed best-effort without it.
            $transactionStarted = false;
        }

        try {
            $personalTeam = $this->personalTeam();
            if ($personalTeam) {
                $personalTeam->delete();
            }

            $deleted = parent::delete();

            if ($transactionStarted) {
                $db->commit();
            }

            return $deleted;
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                try {
                    $db->rollback();
                } catch (\Throwable) {
                    // Ignore rollback errors; rethrow original.
                }
            }

            throw $e;
        }
    }

    /**
     * Get display name
     */
    public function displayName(): string
    {
        return $this->name ?: $this->username;
    }

    /**
     * Node IDs this user is allowed to use within a team.
     *
     * Admins in "view all" mode bypass this.
     *
     * @return int[]
     */
    public function allowedNodeIdsForTeam(int $teamId): array
    {
        // Nodes are global; $teamId is ignored but kept for backwards compatibility.
        if ($this->canViewAllTeams()) {
            return Node::allIds();
        }

        $db = App::db();
        $rows = $db->fetchAll(
            'SELECT node_id FROM user_node_access WHERE user_id = ?',
            [$this->id]
        );
        $listed = array_values(array_unique(array_map(fn($r) => (int)$r['node_id'], $rows)));

        if (($this->node_access_mode ?? 'allow_selected') === 'allow_all_except') {
            $all = Node::allIds();
            $allowed = array_values(array_diff($all, $listed));
            sort($allowed);
            return $allowed;
        }

        sort($listed);
        return $listed;
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
