<?php

namespace Chap\Models;

use Chap\App;

/**
 * Database Model
 * 
 * Represents a managed database service (MySQL, PostgreSQL, Redis, etc.)
 */
class Database extends BaseModel
{
    protected static string $table = 'databases';
    protected static array $fillable = [
        'environment_id', 'node_id', 'name', 'description',
        'type', 'version',
        'internal_host', 'internal_port', 'public_port',
        'root_password', 'database_name', 'username', 'password',
        'cpu_limit', 'memory_limit', 'storage_limit',
        'volumes', 'status', 'container_id'
    ];
    protected static array $hidden = ['root_password', 'password'];

    public int $environment_id;
    public ?int $node_id = null;
    public string $name = '';
    public ?string $description = null;
    public string $type = 'mysql';
    public ?string $version = null;
    
    public ?string $internal_host = null;
    public ?int $internal_port = null;
    public ?int $public_port = null;
    public ?string $root_password = null;
    public ?string $database_name = null;
    public ?string $username = null;
    public ?string $password = null;
    
    public ?string $cpu_limit = null;
    public ?string $memory_limit = null;
    public ?string $storage_limit = null;
    
    public ?string $volumes = null;
    public string $status = 'draft';
    public ?string $container_id = null;

    /**
     * Get environment
     */
    public function environment(): ?Environment
    {
        return Environment::find($this->environment_id);
    }

    /**
     * Get node
     */
    public function node(): ?Node
    {
        return $this->node_id ? Node::find($this->node_id) : null;
    }

    /**
     * Get volumes as array
     */
    public function getVolumes(): array
    {
        if (!$this->volumes) {
            return [];
        }
        return json_decode($this->volumes, true) ?: [];
    }

    /**
     * Check if database is running
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Get databases for environment
     */
    public static function forEnvironment(int $environmentId): array
    {
        return self::where('environment_id', $environmentId);
    }

    /**
     * Get connection string
     */
    public function connectionString(): ?string
    {
        if (!$this->isRunning() || !$this->internal_host || !$this->internal_port) {
            return null;
        }

        return match($this->type) {
            'mysql', 'mariadb' => sprintf(
                'mysql://%s:%s@%s:%d/%s',
                $this->username ?? 'root',
                $this->password ?? $this->root_password,
                $this->internal_host,
                $this->internal_port,
                $this->database_name ?? ''
            ),
            'postgresql' => sprintf(
                'postgresql://%s:%s@%s:%d/%s',
                $this->username ?? 'postgres',
                $this->password ?? '',
                $this->internal_host,
                $this->internal_port,
                $this->database_name ?? 'postgres'
            ),
            'redis' => sprintf(
                'redis://%s:%d',
                $this->internal_host,
                $this->internal_port
            ),
            'mongodb' => sprintf(
                'mongodb://%s:%s@%s:%d',
                $this->username ?? 'root',
                $this->root_password ?? '',
                $this->internal_host,
                $this->internal_port
            ),
            default => null
        };
    }

    /**
     * Get default port for database type
     */
    public static function defaultPort(string $type): int
    {
        return match($type) {
            'mysql', 'mariadb' => 3306,
            'postgresql' => 5432,
            'mongodb' => 27017,
            'redis' => 6379,
            'memcached' => 11211,
            default => 0
        };
    }
}
