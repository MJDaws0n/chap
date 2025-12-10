<?php

namespace Chap\Models;

use Chap\App;

/**
 * Base Model Class
 */
abstract class BaseModel
{
    protected static string $table = '';
    protected static string $primaryKey = 'id';
    protected static array $fillable = [];
    protected static array $hidden = ['password_hash'];
    
    public int $id;
    public string $uuid;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * Create model from array
     */
    public static function fromArray(array $data): static
    {
        $model = new static();
        
        foreach ($data as $key => $value) {
            if (property_exists($model, $key)) {
                $model->$key = $value;
            }
        }
        
        return $model;
    }

    /**
     * Find by ID
     */
    public static function find(int $id): ?static
    {
        $db = App::db();
        $data = $db->fetch(
            "SELECT * FROM " . static::$table . " WHERE " . static::$primaryKey . " = ? LIMIT 1",
            [$id]
        );
        
        return $data ? static::fromArray($data) : null;
    }

    /**
     * Find by UUID
     */
    public static function findByUuid(string $uuid): ?static
    {
        $db = App::db();
        $data = $db->fetch(
            "SELECT * FROM " . static::$table . " WHERE uuid = ? LIMIT 1",
            [$uuid]
        );
        
        return $data ? static::fromArray($data) : null;
    }

    /**
     * Find by column
     */
    public static function findBy(string $column, mixed $value): ?static
    {
        $db = App::db();
        $data = $db->fetch(
            "SELECT * FROM " . static::$table . " WHERE {$column} = ? LIMIT 1",
            [$value]
        );
        
        return $data ? static::fromArray($data) : null;
    }

    /**
     * Get all records
     */
    public static function all(): array
    {
        $db = App::db();
        $results = $db->fetchAll("SELECT * FROM " . static::$table);
        
        return array_map(fn($data) => static::fromArray($data), $results);
    }

    /**
     * Get records with where clause
     */
    public static function where(string $column, mixed $value): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT * FROM " . static::$table . " WHERE {$column} = ?",
            [$value]
        );
        
        return array_map(fn($data) => static::fromArray($data), $results);
    }

    /**
     * Create new record
     */
    public static function create(array $data): static
    {
        $db = App::db();
        
        // Generate UUID if not provided
        if (!isset($data['uuid'])) {
            $data['uuid'] = uuid();
        }
        
        // Filter to fillable fields
        $filtered = array_intersect_key($data, array_flip(static::$fillable));
        $filtered['uuid'] = $data['uuid'];
        
        $id = $db->insert(static::$table, $filtered);
        
        return static::find($id);
    }

    /**
     * Update record
     */
    public function update(array $data): bool
    {
        $db = App::db();
        
        // Filter to fillable fields
        $filtered = array_intersect_key($data, array_flip(static::$fillable));
        
        if (empty($filtered)) {
            return false;
        }
        
        $db->update(static::$table, $filtered, static::$primaryKey . ' = ?', [$this->id]);
        
        // Update local properties
        foreach ($filtered as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
        
        return true;
    }

    /**
     * Delete record
     */
    public function delete(): bool
    {
        $db = App::db();
        return $db->delete(static::$table, static::$primaryKey . ' = ?', [$this->id]) > 0;
    }

    /**
     * Save record (insert or update)
     */
    public function save(): bool
    {
        if (isset($this->id) && $this->id > 0) {
            return $this->update($this->toArray());
        } else {
            $created = static::create($this->toArray());
            $this->id = $created->id;
            $this->uuid = $created->uuid;
            return true;
        }
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        $data = [];
        
        foreach (get_object_vars($this) as $key => $value) {
            if (!in_array($key, static::$hidden)) {
                $data[$key] = $value;
            }
        }
        
        return $data;
    }

    /**
     * Convert to JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }

    /**
     * Count records
     */
    public static function count(?string $where = null, array $params = []): int
    {
        $db = App::db();
        $sql = "SELECT COUNT(*) as count FROM " . static::$table;
        
        if ($where) {
            $sql .= " WHERE {$where}";
        }
        
        $result = $db->fetch($sql, $params);
        return $result['count'] ?? 0;
    }

    /**
     * Paginate records
     */
    public static function paginate(int $page = 1, int $perPage = 15, ?string $where = null, array $params = []): array
    {
        $db = App::db();
        $offset = ($page - 1) * $perPage;
        
        $sql = "SELECT * FROM " . static::$table;
        if ($where) {
            $sql .= " WHERE {$where}";
        }
        $sql .= " ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}";
        
        $results = $db->fetchAll($sql, $params);
        $total = static::count($where, $params);
        
        return [
            'data' => array_map(fn($data) => static::fromArray($data), $results),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => ceil($total / $perPage),
        ];
    }
}
