<?php

namespace Chap\Models;

use Chap\App;

class PortAllocation extends BaseModel
{
    protected static string $table = 'port_allocations';

    protected static array $fillable = [
        'node_id', 'application_id', 'reservation_uuid', 'port', 'expires_at',
    ];

    public int $node_id;
    public ?int $application_id = null;
    public ?string $reservation_uuid = null;
    public int $port;
    public ?string $expires_at = null;

    /** @return int[] */
    public static function portsForApplication(int $applicationId): array
    {
        $db = App::db();
        $rows = $db->fetchAll(
            'SELECT port FROM port_allocations WHERE application_id = ? ORDER BY port ASC',
            [$applicationId]
        );
        return array_map(fn($r) => (int)$r['port'], $rows);
    }

    /** @return int[] */
    public static function portsForReservation(string $reservationUuid, int $nodeId): array
    {
        $db = App::db();
        $rows = $db->fetchAll(
            'SELECT port FROM port_allocations WHERE reservation_uuid = ? AND node_id = ? ORDER BY port ASC',
            [$reservationUuid, $nodeId]
        );
        return array_map(fn($r) => (int)$r['port'], $rows);
    }
}
