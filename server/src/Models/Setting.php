<?php

namespace Chap\Models;

use Chap\App;

/**
 * Global Setting model
 */
class Setting extends BaseModel
{
    protected static string $table = 'settings';
    protected static array $fillable = ['key', 'value'];

    public string $key = '';
    public ?string $value = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $db = App::db();
        $row = $db->fetch('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1', [$key]);
        if (!$row) {
            return $default;
        }
        return $row['value'];
    }

    public static function set(string $key, mixed $value): void
    {
        $db = App::db();
        $stringValue = is_bool($value) ? ($value ? '1' : '0') : (is_null($value) ? null : (string)$value);

        $existing = $db->fetch('SELECT id FROM settings WHERE `key` = ? LIMIT 1', [$key]);
        if ($existing) {
            $db->update('settings', ['value' => $stringValue], 'id = ?', [$existing['id']]);
            return;
        }

        $db->insert('settings', [
            'uuid' => uuid(),
            'key' => $key,
            'value' => $stringValue,
        ]);
    }

    public static function getMany(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $db = App::db();
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $rows = $db->fetchAll("SELECT `key`, `value` FROM settings WHERE `key` IN ({$placeholders})", $keys);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = $row['value'];
        }

        return $result;
    }
}
