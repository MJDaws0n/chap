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
        'name', 'slug', 'description', 'documentation_url', 'logo_url',
        'category', 'tags', 'compose_content', 'environment_schema',
        'min_version', 'default_port', 'is_official'
    ];

    public string $name = '';
    public string $slug = '';
    public ?string $description = null;
    public ?string $documentation_url = null;
    public ?string $logo_url = null;
    public ?string $category = null;
    public ?string $tags = null;
    public string $compose_content = '';
    public ?string $environment_schema = null;
    public ?string $min_version = null;
    public ?int $default_port = null;
    public bool $is_official = false;

    /**
     * Get tags as array
     */
    public function getTags(): array
    {
        if (!$this->tags) {
            return [];
        }
        return json_decode($this->tags, true) ?: [];
    }

    /**
     * Get environment schema as array
     */
    public function getEnvironmentSchema(): array
    {
        if (!$this->environment_schema) {
            return [];
        }
        return json_decode($this->environment_schema, true) ?: [];
    }

    /**
     * Find by slug
     */
    public static function findBySlug(string $slug): ?self
    {
        return self::findBy('slug', $slug);
    }

    /**
     * Get templates by category
     */
    public static function byCategory(string $category): array
    {
        return self::where('category', $category);
    }

    /**
     * Get all categories
     */
    public static function categories(): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT DISTINCT category FROM templates WHERE category IS NOT NULL ORDER BY category"
        );
        
        return array_column($results, 'category');
    }

    /**
     * Search templates
     */
    public static function search(string $query): array
    {
        $db = App::db();
        $searchTerm = "%{$query}%";
        
        $results = $db->fetchAll(
            "SELECT * FROM templates 
             WHERE name LIKE ? OR description LIKE ? OR tags LIKE ?
             ORDER BY is_official DESC, name",
            [$searchTerm, $searchTerm, $searchTerm]
        );
        
        return array_map(fn($data) => self::fromArray($data), $results);
    }

    /**
     * Get official templates
     */
    public static function official(): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT * FROM templates WHERE is_official = 1 ORDER BY name"
        );
        
        return array_map(fn($data) => self::fromArray($data), $results);
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
}
