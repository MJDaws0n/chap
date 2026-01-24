<?php

namespace Chap\Services;

use Chap\App;
use Chap\Models\Node;
use Chap\Models\Team;

class NodeMonitorService
{
    /**
     * Check for nodes that have not checked in for the threshold and notify teams.
     *
     * @return array{checked:int,notified:int}
     */
    public static function notifyDownNodes(int $thresholdSeconds = 300): array
    {
        $db = App::db();
        $rows = $db->fetchAll("SELECT * FROM nodes WHERE last_seen_at IS NOT NULL");

        $checked = 0;
        $notified = 0;
        $now = time();

        foreach ($rows as $row) {
            $checked++;
            $node = Node::fromArray($row);

            $lastSeen = $node->last_seen_at ? strtotime($node->last_seen_at) : null;
            if (!$lastSeen) {
                continue;
            }

            $offlineSeconds = $now - $lastSeen;
            if ($offlineSeconds < $thresholdSeconds) {
                continue;
            }

            $settings = self::decodeSettings($node->settings);
            $alert = $settings['notifications']['node_down'] ?? null;
            $alertLastSeen = is_array($alert) ? ($alert['last_seen_at'] ?? null) : null;
            if ($alertLastSeen && $alertLastSeen === $node->last_seen_at) {
                continue;
            }

            $apps = self::applicationsForNode((int)$node->id);
            if (empty($apps)) {
                $settings['notifications']['node_down'] = [
                    'last_seen_at' => $node->last_seen_at,
                    'alerted_at' => date('c'),
                ];
                NotificationService::updateNodeSettings($node, $settings);
                $notified++;
                continue;
            }

            $appsByTeam = [];
            foreach ($apps as $app) {
                $teamId = (int)($app['team_id'] ?? 0);
                if ($teamId <= 0) {
                    continue;
                }
                if (!isset($appsByTeam[$teamId])) {
                    $appsByTeam[$teamId] = [];
                }
                $appsByTeam[$teamId][] = $app;
            }

            foreach ($appsByTeam as $teamId => $teamApps) {
                $team = Team::find((int)$teamId);
                if (!$team) {
                    continue;
                }
                $members = $team->members();
                NotificationService::notifyNodeDown($team, $members, $node, $teamApps, $offlineSeconds);
            }

            $settings['notifications']['node_down'] = [
                'last_seen_at' => $node->last_seen_at,
                'alerted_at' => date('c'),
            ];
            NotificationService::updateNodeSettings($node, $settings);
            $notified++;
        }

        return ['checked' => $checked, 'notified' => $notified];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function applicationsForNode(int $nodeId): array
    {
        $db = App::db();
        return $db->fetchAll(
            "SELECT a.id, a.uuid, a.name, e.id AS environment_id, e.name AS environment_name, p.id AS project_id, p.name AS project_name, t.id AS team_id, t.name AS team_name\n             FROM applications a\n             JOIN environments e ON a.environment_id = e.id\n             JOIN projects p ON e.project_id = p.id\n             JOIN teams t ON p.team_id = t.id\n             WHERE a.node_id = ?",
            [$nodeId]
        );
    }

    private static function decodeSettings(?string $raw): array
    {
        if (!$raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
