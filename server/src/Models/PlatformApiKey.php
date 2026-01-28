<?php

namespace Chap\Models;

use Chap\App;

/**
 * Platform API key model.
 *
 * Stores only a hash of the secret token. Not attached to an end-user.
 */
class PlatformApiKey extends BaseModel
{
    protected static string $table = 'platform_api_keys';
    protected static array $fillable = [
        'uuid',
        'name',
        'token_hash',
        'scopes',
        'constraints',
        'last_used_at',
        'expires_at',
        'revoked_at',
        'created_by_user_id',
    ];

    protected static array $hidden = ['token_hash'];

    public ?string $name = null;
    public string $token_hash = '';
    public ?string $scopes = null;      // JSON
    public ?string $constraints = null; // JSON
    public ?string $last_used_at = null;
    public ?string $expires_at = null;
    public ?string $revoked_at = null;
    public ?int $created_by_user_id = null;

    public static function findByTokenHash(string $hash): ?self
    {
        return self::findBy('token_hash', $hash);
    }

    /** @return string[] */
    public function scopesList(): array
    {
        if (!$this->scopes) return [];
        $decoded = json_decode($this->scopes, true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    /** @return array<string,mixed> */
    public function constraintsMap(): array
    {
        if (!$this->constraints) return [];
        $decoded = json_decode($this->constraints, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null && $this->revoked_at !== '';
    }

    public function isExpired(): bool
    {
        if (!$this->expires_at) return false;
        return strtotime($this->expires_at) !== false && strtotime($this->expires_at) <= time();
    }

    public function touchLastUsed(): void
    {
        $db = App::db();
        $db->update('platform_api_keys', ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [$this->id]);
        $this->last_used_at = date('Y-m-d H:i:s');
    }
}
