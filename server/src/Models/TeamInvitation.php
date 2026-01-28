<?php

namespace Chap\Models;

use Chap\App;

class TeamInvitation extends BaseModel
{
    protected static string $table = 'team_invitations';

    protected static array $fillable = [
        'team_id',
        'inviter_user_id',
        'invitee_user_id',
        'email',
        'token_hash',
        'status',
        'base_role_slug',
        'custom_role_ids',
        'accepted_by_user_id',
        'accepted_at',
        'declined_at',
        'revoked_at',
        'expires_at',
    ];

    public int $team_id;
    public int $inviter_user_id;
    public ?int $invitee_user_id = null;
    public string $email = '';
    public string $token_hash = '';

    public string $status = 'pending';

    public string $base_role_slug = 'member';
    public ?string $custom_role_ids = null; // JSON

    public ?int $accepted_by_user_id = null;
    public ?string $accepted_at = null;
    public ?string $declined_at = null;
    public ?string $revoked_at = null;

    public ?string $expires_at = null;

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return self::findBy('token_hash', self::hashToken($token));
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(): bool
    {
        if (empty($this->expires_at)) {
            return false;
        }
        $ts = strtotime((string)$this->expires_at);
        return $ts !== false && $ts <= time();
    }

    /**
     * Returns decoded custom role IDs (ints).
     *
     * @return array<int>
     */
    public function customRoleIds(): array
    {
        if (empty($this->custom_role_ids)) {
            return [];
        }
        $decoded = json_decode((string)$this->custom_role_ids, true);
        if (!is_array($decoded)) {
            return [];
        }
        $ids = [];
        foreach ($decoded as $id) {
            $ids[] = (int)$id;
        }
        return array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));
    }

    public static function pendingForTeamAndEmail(int $teamId, string $email): ?self
    {
        $db = App::db();
        $row = $db->fetch(
            "SELECT * FROM team_invitations WHERE team_id = ? AND email = ? AND status = 'pending' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY id DESC LIMIT 1",
            [$teamId, $email]
        );
        return $row ? self::fromArray($row) : null;
    }

    /**
     * List pending invites for a team.
     *
     * @return array<int, TeamInvitation>
     */
    public static function pendingForTeam(int $teamId): array
    {
        $db = App::db();
        $rows = $db->fetchAll(
            "SELECT * FROM team_invitations WHERE team_id = ? AND status = 'pending' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY created_at DESC",
            [$teamId]
        );
        return array_map(fn($r) => self::fromArray($r), $rows);
    }
}
