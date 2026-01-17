<?php

namespace Chap\Services;

use Chap\App;
use Chap\Models\Application;
use Chap\Models\Environment;
use Chap\Models\Project;
use Chap\Models\Team;
use Chap\Models\Node;
use Chap\WebSocket\Server as WebSocketServer;

class ApplicationCleanupService
{
    /**
     * Stop+delete an application on its node (via task), then delete the DB row.
     * Returns true if the DB row was deleted.
     */
    public static function deleteApplication(Application $application): bool
    {
        try {
            // Best-effort: release allocated ports before deleting.
            if (!empty($application->id)) {
                PortAllocator::releaseForApplication((int)$application->id);
            }
        } catch (\Throwable) {
            // ignore
        }

        return $application->delete();
    }

    /** Delete all applications in an environment. Returns count deleted (DB). */
    public static function deleteAllForEnvironment(Environment $environment): int
    {
        $count = 0;
        foreach ($environment->applications() as $application) {
            if (self::deleteApplication($application)) {
                $count++;
            }
        }
        return $count;
    }

    /** Delete all applications in a project. Returns count deleted (DB). */
    public static function deleteAllForProject(Project $project): int
    {
        $count = 0;
        foreach ($project->environments() as $environment) {
            $count += self::deleteAllForEnvironment($environment);
        }
        return $count;
    }

    /** Delete all applications owned by a team. Returns count deleted (DB). */
    public static function deleteAllForTeam(Team $team): int
    {
        $count = 0;
        foreach (Project::forTeam((int)$team->id) as $project) {
            $count += self::deleteAllForProject($project);
        }
        return $count;
    }

    /**
     * Periodic cleanup: find containers that belong to apps that no longer exist,
     * and queue an application delete task (best-effort) to the owning node.
     *
     * Returns ['queued' => int, 'skipped' => int].
     */
    public static function queueDeletesForOrphanedContainers(int $recentMinutes = 30): array
    {
        $recentMinutes = max(1, min(1440, (int)$recentMinutes));
        $db = App::db();
        $queued = 0;
        $skipped = 0;

        // Containers get application_id set NULL when the application is deleted.
        // We key off the compose project name, which embeds the application UUID: chap-<uuid>...
        $rows = $db->fetchAll(
            "SELECT id, node_id, name, container_id, status FROM containers WHERE application_id IS NULL AND node_id IS NOT NULL AND name IS NOT NULL"
        );

        $seen = [];
        foreach ($rows as $row) {
            $nodeId = (int)($row['node_id'] ?? 0);
            $name = (string)($row['name'] ?? '');
            if ($nodeId <= 0 || $name === '') {
                $skipped++;
                continue;
            }

            if (!preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $name, $m)) {
                $skipped++;
                continue;
            }

            $appUuid = strtolower($m[0]);
            $key = $nodeId . ':' . $appUuid;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            // Avoid spamming the queue: if a delete task was queued recently, skip.
            $like = '%"application_uuid":"' . $appUuid . '"%';
            $recent = $db->fetch(
                "SELECT id FROM deployment_tasks WHERE node_id = ? AND task_type = 'application:delete' AND created_at > (NOW() - INTERVAL {$recentMinutes} MINUTE) AND task_data LIKE ? LIMIT 1",
                [$nodeId, $like]
            );
            if ($recent) {
                $skipped++;
                continue;
            }

            self::queueApplicationDeleteTask($nodeId, $appUuid);
            $queued++;
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /** Queue an application delete task directly (works even if the app row is already gone). */
    public static function queueApplicationDeleteTask(int $nodeId, string $applicationUuid): void
    {
        $applicationUuid = trim($applicationUuid);
        if ($nodeId <= 0 || $applicationUuid === '') {
            return;
        }

        $node = Node::find($nodeId);
        if (!$node) {
            return;
        }

        $taskId = bin2hex(random_bytes(16));
        $task = [
            'type' => 'application:delete',
            'payload' => [
                'task_id' => $taskId,
                'application_uuid' => $applicationUuid,
                'application_id' => $applicationUuid,
            ],
        ];

        $db = App::db();
        $db->query(
            "INSERT INTO deployment_tasks (node_id, task_data, created_at, task_type) VALUES (?, ?, NOW(), ?)",
            [$node->id, json_encode($task), $task['type']]
        );

        try {
            WebSocketServer::sendToNode($node->id, $task);
        } catch (\Throwable) {
            // ignore; picked up by polling
        }
    }
}
