<?php

namespace Chap\Models;

use Chap\App;

/**
 * GitSource Model
 * 
 * Represents a connection to a Git provider (GitHub, GitLab, Bitbucket)
 */
class GitSource extends BaseModel
{
    protected static string $table = 'git_sources';
    protected static array $fillable = [
        'team_id', 'name', 'type', 'base_url', 'api_url',
        'auth_method',
        'is_oauth', 'oauth_token',
        'github_app_id', 'github_app_installation_id', 'github_app_private_key',
        'deploy_key_public', 'deploy_key_private',
        'is_active'
    ];
    protected static array $hidden = ['oauth_token', 'deploy_key_private', 'github_app_private_key'];

    public int $team_id;
    public string $name = '';
    public string $type = 'github';
    public ?string $base_url = null;
    public ?string $api_url = null;

    // oauth | deploy_key | github_app
    public ?string $auth_method = null;
    public bool $is_oauth = false;
    public ?string $oauth_token = null;

    public ?int $github_app_id = null;
    public ?int $github_app_installation_id = null;
    public ?string $github_app_private_key = null;

    public ?string $deploy_key_public = null;
    public ?string $deploy_key_private = null;
    public bool $is_active = true;

    /**
     * Get team
     */
    public function team(): ?Team
    {
        return Team::find($this->team_id);
    }

    /**
     * Get git sources for team
     */
    public static function forTeam(int $teamId): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT * FROM git_sources WHERE team_id = ? AND is_active = 1 ORDER BY name",
            [$teamId]
        );
        
        return array_map(fn($data) => self::fromArray($data), $results);
    }

    /**
     * Get GitHub App git sources for team
     */
    public static function githubAppsForTeam(int $teamId): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT * FROM git_sources WHERE team_id = ? AND is_active = 1 AND type = 'github' AND auth_method = 'github_app' ORDER BY name",
            [$teamId]
        );

        return array_map(fn($data) => self::fromArray($data), $results);
    }

    /**
     * Infer auth_method for older rows
     */
    public function inferredAuthMethod(): string
    {
        if ($this->auth_method) {
            return $this->auth_method;
        }

        if ($this->github_app_id && $this->github_app_installation_id && $this->github_app_private_key) {
            return 'github_app';
        }

        if ($this->is_oauth && $this->oauth_token) {
            return 'oauth';
        }

        if ($this->deploy_key_private) {
            return 'deploy_key';
        }

        return 'oauth';
    }

    /**
     * Get default URLs for provider
     */
    public static function getProviderDefaults(string $type): array
    {
        return match($type) {
            'github' => [
                'base_url' => 'https://github.com',
                'api_url' => 'https://api.github.com',
            ],
            'gitlab' => [
                'base_url' => 'https://gitlab.com',
                'api_url' => 'https://gitlab.com/api/v4',
            ],
            'bitbucket' => [
                'base_url' => 'https://bitbucket.org',
                'api_url' => 'https://api.bitbucket.org/2.0',
            ],
            default => [
                'base_url' => null,
                'api_url' => null,
            ]
        };
    }

    /**
     * Get display name for type
     */
    public function providerName(): string
    {
        return match($this->type) {
            'github' => 'GitHub',
            'gitlab' => 'GitLab',
            'bitbucket' => 'Bitbucket',
            'custom' => 'Custom Git',
            default => ucfirst($this->type)
        };
    }

    /**
     * Generate deploy key pair
     */
    public function generateDeployKey(): void
    {
        // Generate ED25519 key pair
        $keyResource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 4096,
        ]);

        openssl_pkey_export($keyResource, $privateKey);
        $publicKey = openssl_pkey_get_details($keyResource)['key'];

        $this->deploy_key_private = $privateKey;
        $this->deploy_key_public = $publicKey;
    }
}
