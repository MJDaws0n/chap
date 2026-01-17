#!/usr/bin/env php
<?php
/**
 * Cleanup Orphaned Application Containers
 *
 * Best-effort: finds container rows whose application was deleted (application_id IS NULL),
 * extracts the application UUID from the container name (chap-<uuid>...), and queues an
 * `application:delete` task to the owning node.
 */

require __DIR__ . '/../vendor/autoload.php';

use Chap\Config;
use Chap\App;
use Chap\Services\ApplicationCleanupService;

Config::load();
$app = new App();
$app->boot();

$recentMinutes = (int)(getenv('CHAP_CLEANUP_RECENT_MINUTES') ?: 30);
$recentMinutes = max(1, min(1440, $recentMinutes));

try {
    $res = ApplicationCleanupService::queueDeletesForOrphanedContainers($recentMinutes);
    $queued = (int)($res['queued'] ?? 0);
    $skipped = (int)($res['skipped'] ?? 0);
    echo "Queued {$queued} orphan application delete(s). Skipped {$skipped}.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Cleanup failed: " . $e->getMessage() . "\n");
    exit(1);
}
