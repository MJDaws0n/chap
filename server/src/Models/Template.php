<?php

namespace Chap\Models;

use Chap\App;

/**
 * Template Model
 */
class Template extends BaseModel
{
    protected static string $table = 'templates';
    protected static array $fillable = [
        'name',
        'slug',
        'description',
        'category',
        'icon',
        'docker_compose',
        'documentation',
        'source_url',
        'default_environment_variables',
        'required_environment_variables',
        'ports',
        'volumes',
        'version',
        'is_official',
        'is_active',
        'extra_files'
    ];

    public string $name = '';
    public string $slug = '';
    public ?string $description = null;
    public ?string $category = null;
    public ?string $icon = null;

    // Template configuration
    public string $docker_compose = '';
    public ?string $documentation = null;
    public mixed $default_environment_variables = null;
    public mixed $required_environment_variables = null;
    public mixed $ports = null;
    public mixed $volumes = null;

    // Optional additional files (e.g. Dockerfile, scripts) to be written into compose dir
    public mixed $extra_files = null;

    // Metadata
    public ?string $version = null;
    public ?string $source_url = null;
    public bool $is_official = false;
    public bool $is_active = true;

    /**
     * Find by slug
     */
    public static function findBySlug(string $slug): ?self
    {
        return self::findBy('slug', $slug);
    }

    /**
     * Get default environment variables as array
     */
    public function getDefaultEnvironmentVariables(): array
    {
        return self::decodeJsonValue($this->default_environment_variables);
    }

    /**
     * Get required environment variables as array
     */
    public function getRequiredEnvironmentVariables(): array
    {
        return self::decodeJsonValue($this->required_environment_variables);
    }

    /**
     * Get ports as array
     */
    public function getPorts(): array
    {
        return self::decodeJsonValue($this->ports);
    }

    /**
     * Get volumes as array
     */
    public function getVolumes(): array
    {
        return self::decodeJsonValue($this->volumes);
    }

    /**
     * Get extra files as array
     */
    public function getExtraFiles(): array
    {
        return self::decodeJsonValue($this->extra_files);
    }

    /**
     * Get all categories
     */
    public static function categories(): array
    {
        $db = App::db();
        $results = $db->fetchAll("SELECT DISTINCT category FROM templates WHERE category IS NOT NULL ORDER BY category");
        return array_values(array_filter(array_map(fn($r) => (string)($r['category'] ?? ''), $results), fn($x) => $x !== ''));
    }

    /**
     * Generate slug from name
     */
    public static function generateSlug(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * @return array<mixed>
     */
    private static function decodeJsonValue(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
