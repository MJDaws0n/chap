<?php

namespace Chap\Models;

use Chap\App;

class NodePortRange extends BaseModel
{
    protected static string $table = 'node_port_ranges';

    protected static array $fillable = [
        'node_id', 'start_port', 'end_port',
    ];

    public int $node_id;
    public int $start_port;
    public int $end_port;

    /** @return self[] */
    public static function forNode(int $nodeId): array
    {
        $db = App::db();
        $rows = $db->fetchAll(
            'SELECT * FROM node_port_ranges WHERE node_id = ? ORDER BY start_port ASC, end_port ASC',
            [$nodeId]
        );
        return array_map(fn($r) => self::fromArray($r), $rows);
    }

    /** @param array{start_port:int,end_port:int}[] $ranges */
    public static function replaceForNode(int $nodeId, array $ranges): void
    {
        $db = App::db();
        $db->query('DELETE FROM node_port_ranges WHERE node_id = ?', [$nodeId]);
        foreach ($ranges as $r) {
            self::create([
                'node_id' => $nodeId,
                'start_port' => (int)$r['start_port'],
                'end_port' => (int)$r['end_port'],
            ]);
        }
    }
}
