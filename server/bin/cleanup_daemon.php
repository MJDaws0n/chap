#!/usr/bin/env php
<?php
/**
 * Periodic Cleanup Daemon
 *
 * Runs background cleanup every N seconds.
 */

require __DIR__ . '/../vendor/autoload.php';

use Chap\Config;
use Chap\App;
use Chap\Services\ApplicationCleanupService;
use Chap\Services\PortAllocator;
use Chap\Services\NodeMonitorService;

Config::load();
$app = new App();
$app->boot();

$interval = (int)(getenv('CHAP_CLEANUP_INTERVAL_SECONDS') ?: 900); // 15 min
$recentMinutes = (int)(getenv('CHAP_CLEANUP_RECENT_MINUTES') ?: 30);
$nodeDownSeconds = (int)(getenv('CHAP_NODE_DOWN_ALERT_SECONDS') ?: 300);

$enabled = getenv('CHAP_CLEANUP_ENABLED');
if ($enabled !== false && (string)$enabled === '0') {
    fwrite(STDOUT, "[cleanup] disabled via CHAP_CLEANUP_ENABLED=0\n");
    exit(0);
}

if ($interval < 60) {
    $interval = 60;
}

$recentMinutes = max(1, min(1440, $recentMinutes));

fwrite(STDOUT, "[cleanup] started interval={$interval}s recent={$recentMinutes}m\n");

while (true) {
    $ts = date('c');
    try {
        $res = ApplicationCleanupService::queueDeletesForOrphanedContainers($recentMinutes);
        $queued = (int)($res['queued'] ?? 0);
        $skipped = (int)($res['skipped'] ?? 0);
        $ports = PortAllocator::cleanupOrphanedAllocations();
        $portsDeleted = (int)($ports['total'] ?? 0);
        $nodeRes = NodeMonitorService::notifyDownNodes($nodeDownSeconds);
        $nodesChecked = (int)($nodeRes['checked'] ?? 0);
        $nodesNotified = (int)($nodeRes['notified'] ?? 0);
        fwrite(STDOUT, "[cleanup] {$ts} queued={$queued} skipped={$skipped} freed_ports={$portsDeleted} nodes_checked={$nodesChecked} nodes_notified={$nodesNotified}\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "[cleanup] {$ts} error=" . $e->getMessage() . "\n");
    }

    sleep($interval);
}
