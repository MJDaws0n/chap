#!/usr/bin/env php
<?php
/**
 * Cleanup Orphaned Port Allocations
 *
 * Removes port allocations that can permanently block ports:
 * - allocations whose application_id points to a deleted application
 * - allocations whose node_id points to a deleted node
 * - expired (or missing expiry) reservations
 */

require __DIR__ . '/../vendor/autoload.php';

use Chap\Config;
use Chap\App;
use Chap\Services\PortAllocator;

Config::load();
$app = new App();
$app->boot();

try {
    $clearAllReservations = getenv('CHAP_CLEANUP_CLEAR_ALL_RESERVATIONS');
    $clearAllReservations = $clearAllReservations !== false && (string)$clearAllReservations === '1';

    $res = PortAllocator::cleanupOrphanedAllocations($clearAllReservations);

    $expired = (int)($res['expired_reservations'] ?? 0);
    $orphanApps = (int)($res['orphaned_app_allocations'] ?? 0);
    $orphanNodes = (int)($res['orphaned_node_allocations'] ?? 0);
    $total = (int)($res['total'] ?? ($expired + $orphanApps + $orphanNodes));

    $mode = $clearAllReservations ? 'all_reservations' : 'expired_only';
    echo "Deleted {$total} port allocation(s) (mode={$mode}): expired_reservations={$expired}, orphaned_app_allocations={$orphanApps}, orphaned_node_allocations={$orphanNodes}.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Cleanup failed: " . $e->getMessage() . "\n");
    exit(1);
}
