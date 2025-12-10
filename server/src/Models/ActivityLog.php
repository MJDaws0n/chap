<?php

namespace Chap\Models;

use Chap\App;

/**
 * Activity Log Model
 */
class ActivityLog extends BaseModel
{
    protected static string $table = 'activity_logs';
    protected static array $fillable = [
        'user_id', 'team_id', 'subject_type', 'subject_id',
        'action', 'properties', 'ip_address', 'user_agent'
    ];

    public ?int $user_id = null;
    public ?int $team_id = null;
    public ?string $subject_type = null;
    public ?int $subject_id = null;
    public string $action = '';
    public ?string $properties = null;
    public ?string $ip_address = null;
    public ?string $user_agent = null;

    /**
     * Get user
     */
    public function user(): ?User
    {
        return $this->user_id ? User::find($this->user_id) : null;
    }

    /**
     * Get team
     */
    public function team(): ?Team
    {
        return $this->team_id ? Team::find($this->team_id) : null;
    }

    /**
     * Get properties as array
     */
    public function getProperties(): array
    {
        if (!$this->properties) {
            return [];
        }
        return json_decode($this->properties, true) ?: [];
    }

    /**
     * Log an action
     */
    public static function log(
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $properties = []
    ): self {
        $user = auth();
        $team = $user?->currentTeam();
        
        return self::create([
            'user_id' => $user?->id,
            'team_id' => $team?->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'properties' => !empty($properties) ? json_encode($properties) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    /**
     * Get recent activity for team
     */
    public static function forTeam(int $teamId, int $limit = 50): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT * FROM activity_logs WHERE team_id = ? ORDER BY created_at DESC LIMIT ?",
            [$teamId, $limit]
        );
        
        return array_map(fn($data) => self::fromArray($data), $results);
    }

    /**
     * Get recent activity for user
     */
    public static function forUser(int $userId, int $limit = 50): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT ?",
            [$userId, $limit]
        );
        
        return array_map(fn($data) => self::fromArray($data), $results);
    }

    /**
     * Get description for action
     */
    public function description(): string
    {
        $props = $this->getProperties();
        
        return match($this->action) {
            'user.login' => 'Logged in',
            'user.logout' => 'Logged out',
            'user.register' => 'Account created',
            'node.created' => 'Created node ' . ($props['name'] ?? ''),
            'node.deleted' => 'Deleted node ' . ($props['name'] ?? ''),
            'node.connected' => 'Node connected ' . ($props['name'] ?? ''),
            'project.created' => 'Created project ' . ($props['name'] ?? ''),
            'project.deleted' => 'Deleted project ' . ($props['name'] ?? ''),
            'application.created' => 'Created application ' . ($props['name'] ?? ''),
            'application.deployed' => 'Deployed application ' . ($props['name'] ?? ''),
            'deployment.started' => 'Started deployment',
            'deployment.completed' => 'Deployment completed',
            'deployment.failed' => 'Deployment failed',
            default => $this->action
        };
    }
}
