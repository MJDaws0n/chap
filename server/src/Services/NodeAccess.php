<?php

namespace Chap\Services;

use Chap\Models\Application;
use Chap\Models\Environment;
use Chap\Models\Project;
use Chap\Models\Team;
use Chap\Models\User;

class NodeAccess
{
    /**
     * Effective allowed node IDs for the current user at a given scope.
     *
    * Admin assignment list (user_node_access) is interpreted by user.node_access_mode:
    * - allow_selected: only listed nodes are allowed
    * - allow_all_except: all nodes are allowed except the listed nodes
     * Each level can optionally restrict further via allowed_node_ids.
     *
     * @return int[]
     */
    public static function allowedNodeIds(User $user, Team $team, ?Project $project = null, ?Environment $environment = null, ?Application $application = null): array
    {
        $allowed = $user->allowedNodeIdsForTeam((int)$team->id);

        $restrictSets = [];
        $teamSet = self::decodeNodeIds($team->allowed_node_ids);
        if ($teamSet !== null) {
            $restrictSets[] = $teamSet;
        }
        if ($project) {
            $projectSet = self::decodeNodeIds($project->allowed_node_ids);
            if ($projectSet !== null) {
                $restrictSets[] = $projectSet;
            }
        }
        if ($environment) {
            $envSet = self::decodeNodeIds($environment->allowed_node_ids);
            if ($envSet !== null) {
                $restrictSets[] = $envSet;
            }
        }
        if ($application) {
            $appSet = self::decodeNodeIds($application->allowed_node_ids);
            if ($appSet !== null) {
                $restrictSets[] = $appSet;
            }
        }

        foreach ($restrictSets as $set) {
            $allowed = array_values(array_intersect($allowed, $set));
        }

        $allowed = array_values(array_unique(array_map('intval', $allowed)));
        sort($allowed);
        return $allowed;
    }

    /**
     * Returns null if no restriction (inherit all), or an array of ints if restricted.
     *
     * @return int[]|null
     */
    public static function decodeNodeIds(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        $ids = array_values(array_unique(array_map('intval', $decoded)));
        sort($ids);
        return $ids;
    }

    /** @param int[] $nodeIds */
    public static function encodeNodeIds(array $nodeIds): ?string
    {
        $nodeIds = array_values(array_unique(array_map('intval', $nodeIds)));
        $nodeIds = array_values(array_filter($nodeIds, fn($v) => $v > 0));
        sort($nodeIds);
        return !empty($nodeIds) ? json_encode($nodeIds) : null;
    }
}
