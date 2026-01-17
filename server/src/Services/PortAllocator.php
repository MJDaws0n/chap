<?php

namespace Chap\Services;

use Chap\App;
use Chap\Models\Node;
use Chap\Models\NodePortRange;
use Chap\Models\PortAllocation;

final class PortAllocator
{
    /**
     * @param string[] $entries
     * @return array{ranges: array<int,array{start_port:int,end_port:int}>, errors: string[]}
     */
    public static function parseRanges(array $entries): array
    {
        $ranges = [];
        $errors = [];

        foreach ($entries as $raw) {
            $raw = trim((string)$raw);
            if ($raw === '') {
                continue;
            }

            if (preg_match('/^(\d+)\s*[-–]\s*(\d+)$/u', $raw, $m)) {
                $start = (int)$m[1];
                $end = (int)$m[2];
            } elseif (preg_match('/^(\d+)$/', $raw, $m)) {
                $start = (int)$m[1];
                $end = (int)$m[1];
            } else {
                $errors[] = "Invalid port range entry: {$raw}";
                continue;
            }

            if ($start < 1 || $start > 65535 || $end < 1 || $end > 65535) {
                $errors[] = "Port range out of bounds (1-65535): {$raw}";
                continue;
            }
            if ($end < $start) {
                $errors[] = "Port range must be ascending: {$raw}";
                continue;
            }

            $ranges[] = ['start_port' => $start, 'end_port' => $end];
        }

        // Sort for stable behavior.
        usort($ranges, fn($a, $b) => ($a['start_port'] <=> $b['start_port']) ?: ($a['end_port'] <=> $b['end_port']));

        return ['ranges' => $ranges, 'errors' => $errors];
    }

    /** @return array<int,array{start_port:int,end_port:int}> */
    public static function rangesForNode(int $nodeId): array
    {
        $ranges = NodePortRange::forNode($nodeId);
        return array_map(fn($r) => ['start_port' => (int)$r->start_port, 'end_port' => (int)$r->end_port], $ranges);
    }

    /**
     * @param array<int,array{start_port:int,end_port:int}> $ranges
     */
    public static function saveRangesForNode(int $nodeId, array $ranges): void
    {
        NodePortRange::replaceForNode($nodeId, $ranges);
    }

    /**
     * Allocate a port for an existing application.
     */
    public static function allocateForApplication(int $applicationId, int $nodeId, ?callable $isPortFreeOnNode = null): int
    {
        return self::allocate($nodeId, ['application_id' => $applicationId], $isPortFreeOnNode);
    }

    /**
     * Allocate a port for a create-flow reservation.
     */
    public static function allocateForReservation(string $reservationUuid, int $nodeId, ?callable $isPortFreeOnNode = null): int
    {
        return self::allocate($nodeId, ['reservation_uuid' => $reservationUuid], $isPortFreeOnNode);
    }

    /**
     * @param array{application_id?:int,reservation_uuid?:string} $owner
     */
    private static function allocate(int $nodeId, array $owner, ?callable $isPortFreeOnNode = null): int
    {
        $db = App::db();

        $ranges = self::rangesForNode($nodeId);
        if (empty($ranges)) {
            throw new \RuntimeException('No port ranges configured for this node');
        }

        // Clean up expired reservations.
        $db->query(
            "DELETE FROM port_allocations WHERE application_id IS NULL AND reservation_uuid IS NOT NULL AND expires_at IS NOT NULL AND expires_at < NOW()"
        );

        $node = Node::find($nodeId);
        if (!$node) {
            throw new \RuntimeException('Node not found');
        }

        $minAllowed = min(array_map(fn($r) => (int)$r['start_port'], $ranges));
        $maxAllowed = max(array_map(fn($r) => (int)$r['end_port'], $ranges));

        $cursor = $node->port_cursor ? (int)$node->port_cursor : $minAllowed;
        if ($cursor < $minAllowed || $cursor > $maxAllowed) {
            $cursor = $minAllowed;
        }

        // Generate candidate ports in a rolling order.
        $candidates = self::rollingCandidates($ranges, $cursor);

        $ttlMinutes = 30;

        foreach ($candidates as $port) {
            // First reserve in DB (race-safe via unique constraint).
            try {
                $row = [
                    'node_id' => $nodeId,
                    'port' => $port,
                    'application_id' => $owner['application_id'] ?? null,
                    'reservation_uuid' => $owner['reservation_uuid'] ?? null,
                    'expires_at' => isset($owner['reservation_uuid']) ? date('Y-m-d H:i:s', time() + $ttlMinutes * 60) : null,
                ];
                $db->insert('port_allocations', $row);
            } catch (\Throwable $e) {
                // Duplicate port or similar constraint violation.
                continue;
            }

            // Optional: confirm not in use on node.
            if ($isPortFreeOnNode) {
                try {
                    $free = (bool)call_user_func($isPortFreeOnNode, $nodeId, $port);
                } catch (\Throwable $e) {
                    // If we cannot verify, fail safely.
                    $db->query('DELETE FROM port_allocations WHERE node_id = ? AND port = ? AND application_id ' . (isset($owner['application_id']) ? '= ?' : 'IS NULL') . ' AND reservation_uuid ' . (isset($owner['reservation_uuid']) ? '= ?' : 'IS NULL'),
                        array_values(array_filter([
                            $nodeId,
                            $port,
                            $owner['application_id'] ?? null,
                            $owner['reservation_uuid'] ?? null,
                        ], fn($v) => $v !== null))
                    );
                    throw new \RuntimeException('Unable to verify port availability on node');
                }

                if (!$free) {
                    $db->query('DELETE FROM port_allocations WHERE node_id = ? AND port = ?', [$nodeId, $port]);
                    continue;
                }
            }

            // Update cursor to next port.
            $nextCursor = $port + 1;
            if ($nextCursor > $maxAllowed) {
                $nextCursor = $minAllowed;
            }
            $node->update(['port_cursor' => $nextCursor]);

            return $port;
        }

        throw new \RuntimeException('No available ports in this node\'s allowed ranges');
    }

    /**
     * @param array<int,array{start_port:int,end_port:int}> $ranges
     * @return int[]
     */
    private static function rollingCandidates(array $ranges, int $cursor): array
    {
        // Build allowed list in rolling order without materializing huge sets unnecessarily.
        // We do two passes: [cursor..max] then [min..cursor-1], but only including allowed ranges.
        $sorted = $ranges;
        usort($sorted, fn($a, $b) => ($a['start_port'] <=> $b['start_port']) ?: ($a['end_port'] <=> $b['end_port']));

        $minAllowed = min(array_map(fn($r) => (int)$r['start_port'], $sorted));
        $maxAllowed = max(array_map(fn($r) => (int)$r['end_port'], $sorted));

        $out = [];

        $emit = function(int $from, int $to) use (&$out, $sorted) {
            foreach ($sorted as $r) {
                $start = max((int)$r['start_port'], $from);
                $end = min((int)$r['end_port'], $to);
                if ($end < $start) {
                    continue;
                }
                for ($p = $start; $p <= $end; $p++) {
                    $out[] = $p;
                }
            }
        };

        $emit($cursor, $maxAllowed);
        if ($cursor > $minAllowed) {
            $emit($minAllowed, $cursor - 1);
        }

        // Stable sort within the rolling windows.
        $out = array_values(array_unique($out));

        return $out;
    }

    public static function releaseForApplication(int $applicationId): void
    {
        $db = App::db();
        $db->query('DELETE FROM port_allocations WHERE application_id = ?', [$applicationId]);
    }

    /**
     * Best-effort cleanup for allocations that can permanently block ports:
     * - orphaned allocations where application_id points to a deleted application
     * - orphaned allocations where node_id points to a deleted node
     * - expired (or missing expiry) reservations
     *
     * @return array{expired_reservations:int, orphaned_app_allocations:int, orphaned_node_allocations:int, total:int}
     */
    public static function cleanupOrphanedAllocations(bool $clearAllReservations = false): array
    {
        $db = App::db();

        // Reservation rows should not block ports forever.
        // By default: only clear expired (or missing expiry). Optionally, clear all.
        $sql = $clearAllReservations
            ? 'DELETE FROM port_allocations WHERE application_id IS NULL AND reservation_uuid IS NOT NULL'
            : 'DELETE FROM port_allocations WHERE application_id IS NULL AND reservation_uuid IS NOT NULL AND (expires_at IS NULL OR expires_at < NOW())';

        $stmt = $db->query($sql);
        $expired = (int)$stmt->rowCount();

        // Orphaned allocations (app deleted but allocation row remains).
        $stmt = $db->query(
            'DELETE pa FROM port_allocations pa LEFT JOIN applications a ON a.id = pa.application_id WHERE pa.application_id IS NOT NULL AND a.id IS NULL'
        );
        $orphanApps = (int)$stmt->rowCount();

        // Orphaned allocations (node deleted but allocation row remains).
        $stmt = $db->query(
            'DELETE pa FROM port_allocations pa LEFT JOIN nodes n ON n.id = pa.node_id WHERE n.id IS NULL'
        );
        $orphanNodes = (int)$stmt->rowCount();

        return [
            'expired_reservations' => $expired,
            'orphaned_app_allocations' => $orphanApps,
            'orphaned_node_allocations' => $orphanNodes,
            'total' => $expired + $orphanApps + $orphanNodes,
        ];
    }

    public static function releasePortForApplication(int $applicationId, int $nodeId, int $port): bool
    {
        $db = App::db();
        $affected = $db->delete('port_allocations', 'application_id = ? AND node_id = ? AND port = ?', [$applicationId, $nodeId, $port]);
        return $affected > 0;
    }

    public static function releaseReservation(string $reservationUuid, int $nodeId): void
    {
        $db = App::db();
        $db->query('DELETE FROM port_allocations WHERE reservation_uuid = ? AND node_id = ? AND application_id IS NULL', [$reservationUuid, $nodeId]);
    }

    public static function attachReservationToApplication(string $reservationUuid, int $nodeId, int $applicationId): void
    {
        $db = App::db();
        $db->query(
            'UPDATE port_allocations SET application_id = ?, reservation_uuid = NULL, expires_at = NULL WHERE reservation_uuid = ? AND node_id = ? AND application_id IS NULL',
            [$applicationId, $reservationUuid, $nodeId]
        );
    }

    /**
     * @param int[] $ports
     * @param array<int,array{start_port:int,end_port:int}> $ranges
     */
    public static function validatePortsWithinRanges(array $ports, array $ranges): bool
    {
        foreach ($ports as $p) {
            $ok = false;
            foreach ($ranges as $r) {
                if ($p >= (int)$r['start_port'] && $p <= (int)$r['end_port']) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }
        return true;
    }
}
