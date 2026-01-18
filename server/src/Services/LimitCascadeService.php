<?php

namespace Chap\Services;

use Chap\App;
use Chap\Models\Application;
use Chap\Models\Environment;
use Chap\Models\Project;
use Chap\Models\Team;
use Chap\Models\User;

class LimitCascadeService
{
    /** @var string[] */
    private const RESOURCES = ['cpu_millicores', 'ram_mb', 'storage_mb', 'ports', 'bandwidth_mbps', 'pids'];

    /** @return array{cpu_millicores:int, ram_mb:int, storage_mb:int, ports:int, bandwidth_mbps:int, pids:int} */
    private static function toResourceArray(array $a): array
    {
        return [
            'cpu_millicores' => (int)($a['cpu_millicores'] ?? 0),
            'ram_mb' => (int)($a['ram_mb'] ?? 0),
            'storage_mb' => (int)($a['storage_mb'] ?? 0),
            'ports' => (int)($a['ports'] ?? 0),
            'bandwidth_mbps' => (int)($a['bandwidth_mbps'] ?? 0),
            'pids' => (int)($a['pids'] ?? 0),
        ];
    }

    private static function isReduction(int $old, int $new): bool
    {
        $oldV = $old === -1 ? PHP_INT_MAX : $old;
        $newV = $new === -1 ? PHP_INT_MAX : $new;
        return $newV < $oldV;
    }

    /** @param array<string,int> $old @param array<string,int> $new */
    public static function anyReduction(array $old, array $new): bool
    {
        foreach (self::RESOURCES as $r) {
            if (!array_key_exists($r, $old) || !array_key_exists($r, $new)) {
                continue;
            }
            if (self::isReduction((int)$old[$r], (int)$new[$r])) {
                return true;
            }
        }
        return false;
    }

    /**
     * If allocations exceed parent, set ALL fixed children to -1 (auto-split).
     *
     * @param int $parentTotal
     * @param array<int,int> $configuredByChildId
     * @return array{new: array<int,int>, changedChildIds: int[]}
     */
    private static function autoFixMapToFit(int $parentTotal, array $configuredByChildId): array
    {
        if (ResourceAllocator::validateDoesNotOverallocate($parentTotal, $configuredByChildId)) {
            return ['new' => $configuredByChildId, 'changedChildIds' => []];
        }

        $changed = [];
        $new = $configuredByChildId;
        foreach ($configuredByChildId as $childId => $v) {
            $childId = (int)$childId;
            $vv = (int)$v;
            if ($vv >= 0) {
                $new[$childId] = -1;
                $changed[] = $childId;
            }
        }

        return ['new' => $new, 'changedChildIds' => $changed];
    }

    /**
     * @param array<string,int> $parentTotals
     * @param array<int,array<string,int>> $configuredByChildId
     * @return array{configured: array<int,array<string,int>>, effective: array<int,array<string,int>>, changedByResource: array<string,int[]>}
     */
    private static function enforceLevel(array $parentTotals, array $configuredByChildId): array
    {
        $parentTotals = self::toResourceArray($parentTotals);

        $changedByResource = [];
        $configuredOut = $configuredByChildId;

        // Fix maps if fixed allocations exceed parent.
        foreach (self::RESOURCES as $resource) {
            $map = [];
            foreach ($configuredOut as $childId => $limits) {
                $map[(int)$childId] = (int)($limits[$resource] ?? -1);
            }

            $fixed = self::autoFixMapToFit((int)$parentTotals[$resource], $map);
            $changedByResource[$resource] = $fixed['changedChildIds'];

            if (!empty($fixed['changedChildIds'])) {
                foreach ($fixed['changedChildIds'] as $childId) {
                    $configuredOut[(int)$childId][$resource] = -1;
                }
            }
        }

        // Compute effective totals for each child at this level.
        $effectiveByChildId = [];
        foreach ($configuredOut as $childId => $_) {
            $effectiveByChildId[(int)$childId] = [];
        }

        foreach (self::RESOURCES as $resource) {
            $map = [];
            foreach ($configuredOut as $childId => $limits) {
                $map[(int)$childId] = (int)($limits[$resource] ?? -1);
            }
            $alloc = ResourceAllocator::allocateInt((int)$parentTotals[$resource], $map);
            foreach ($alloc['effectiveByChildId'] as $childId => $eff) {
                $effectiveByChildId[(int)$childId][$resource] = (int)$eff;
            }
        }

        return [
            'configured' => $configuredOut,
            'effective' => $effectiveByChildId,
            'changedByResource' => $changedByResource,
        ];
    }

    /** @return int[] */
    public static function applicationIdsForEnvironment(int $environmentId): array
    {
        $db = App::db();
        $rows = $db->fetchAll('SELECT id FROM applications WHERE environment_id = ? ORDER BY id', [$environmentId]);
        return array_values(array_map(fn($r) => (int)$r['id'], $rows));
    }

    /** @return int[] */
    public static function applicationIdsForProject(int $projectId): array
    {
        $db = App::db();
        $rows = $db->fetchAll(
            'SELECT a.id FROM applications a JOIN environments e ON e.id = a.environment_id WHERE e.project_id = ? ORDER BY a.id',
            [$projectId]
        );
        return array_values(array_map(fn($r) => (int)$r['id'], $rows));
    }

    /** @return int[] */
    public static function applicationIdsForTeam(int $teamId): array
    {
        $db = App::db();
        $rows = $db->fetchAll(
            'SELECT a.id FROM applications a '
                . 'JOIN environments e ON e.id = a.environment_id '
                . 'JOIN projects p ON p.id = e.project_id '
                . 'WHERE p.team_id = ? ORDER BY a.id',
            [$teamId]
        );
        return array_values(array_map(fn($r) => (int)$r['id'], $rows));
    }

    /** @return int[] */
    public static function applicationIdsForUserOwnedTeams(int $userId): array
    {
        $teamIds = array_map(fn($t) => (int)$t->id, ResourceHierarchy::teamsOwnedByUser($userId));
        if (empty($teamIds)) {
            return [];
        }

        $db = App::db();
        $in = implode(',', array_fill(0, count($teamIds), '?'));
        $rows = $db->fetchAll(
            'SELECT a.id FROM applications a '
                . 'JOIN environments e ON e.id = a.environment_id '
                . 'JOIN projects p ON p.id = e.project_id '
                . 'WHERE p.team_id IN (' . $in . ') ORDER BY a.id',
            $teamIds
        );
        return array_values(array_map(fn($r) => (int)$r['id'], $rows));
    }

    /**
     * Enforce hierarchy under a Team (projects -> environments -> applications).
     * Updates child limit records to -1 if the parent reduction makes allocations invalid.
     *
     * @return array{updated_projects:int,updated_environments:int,updated_applications:int,changed_fields:int}
     */
    public static function enforceUnderTeam(Team $team): array
    {
        $teamTotals = ResourceHierarchy::effectiveTeamLimits($team);

        $projects = Project::forTeam((int)$team->id);
        $projectsById = [];
        foreach ($projects as $p) {
            $projectsById[(int)$p->id] = $p;
        }
        $projectConfigured = [];
        foreach ($projectsById as $pid => $p) {
            $projectConfigured[$pid] = ResourceHierarchy::projectConfigured($p);
        }

        $level = self::enforceLevel($teamTotals, $projectConfigured);
        $projectEffective = $level['effective'];

        $updatedProjects = 0;
        $changedFields = 0;

        foreach (self::RESOURCES as $resource) {
            $ids = $level['changedByResource'][$resource] ?? [];
            if (empty($ids)) {
                continue;
            }
            $field = self::resourceToField($resource);
            foreach ($ids as $projectId) {
                $p = $projectsById[(int)$projectId] ?? null;
                if ($p instanceof Project) {
                    $p->update([$field => -1]);
                    $updatedProjects++;
                    $changedFields++;
                }
            }
        }

        $updatedEnvironments = 0;
        $updatedApplications = 0;

        // Cascade: project -> environments -> applications
        foreach ($projects as $project) {
            $pid = (int)$project->id;
            $pTotals = self::toResourceArray($projectEffective[$pid] ?? []);

            $envs = Environment::forProject($pid);
            $envsById = [];
            foreach ($envs as $e) {
                $envsById[(int)$e->id] = $e;
            }
            $envConfigured = [];
            foreach ($envsById as $eid => $e) {
                $envConfigured[$eid] = ResourceHierarchy::environmentConfigured($e);
            }

            $envLevel = self::enforceLevel($pTotals, $envConfigured);
            $envEffective = $envLevel['effective'];

            foreach (self::RESOURCES as $resource) {
                $ids = $envLevel['changedByResource'][$resource] ?? [];
                if (empty($ids)) {
                    continue;
                }
                $field = self::resourceToField($resource);
                foreach ($ids as $envId) {
                    $envObj = $envsById[(int)$envId] ?? null;
                    if ($envObj instanceof Environment) {
                        $envObj->update([$field => -1]);
                        $updatedEnvironments++;
                        $changedFields++;
                    }
                }
            }

            // env -> apps
            foreach ($envs as $env) {
                $eid = (int)$env->id;
                $eTotals = self::toResourceArray($envEffective[$eid] ?? []);

                $apps = Application::forEnvironment($eid);
                if (empty($apps)) {
                    continue;
                }

                $appsById = [];
                foreach ($apps as $a) {
                    $appsById[(int)$a->id] = $a;
                }

                $appConfigured = [];
                foreach ($appsById as $aid => $a) {
                    $appConfigured[$aid] = ResourceHierarchy::applicationConfigured($a);
                }

                $appLevel = self::enforceLevel($eTotals, $appConfigured);

                foreach (self::RESOURCES as $resource) {
                    $ids = $appLevel['changedByResource'][$resource] ?? [];
                    if (empty($ids)) {
                        continue;
                    }
                    $field = self::resourceToField($resource);
                    foreach ($ids as $appId) {
                        $appObj = $appsById[(int)$appId] ?? null;
                        if ($appObj instanceof Application) {
                            $appObj->update([$field => -1]);
                            $updatedApplications++;
                            $changedFields++;
                        }
                    }
                }
            }
        }

        return [
            'updated_projects' => $updatedProjects,
            'updated_environments' => $updatedEnvironments,
            'updated_applications' => $updatedApplications,
            'changed_fields' => $changedFields,
        ];
    }

    /** @return array{updated_environments:int,updated_applications:int,changed_fields:int} */
    public static function enforceUnderProject(Project $project): array
    {
        $projectTotals = ResourceHierarchy::effectiveProjectLimits($project);

        $envs = Environment::forProject((int)$project->id);
        $envConfigured = [];
        foreach ($envs as $e) {
            $envConfigured[(int)$e->id] = ResourceHierarchy::environmentConfigured($e);
        }

        $level = self::enforceLevel($projectTotals, $envConfigured);
        $envEffective = $level['effective'];

        $updatedEnvironments = 0;
        $updatedApplications = 0;
        $changedFields = 0;

        foreach (self::RESOURCES as $resource) {
            $ids = $level['changedByResource'][$resource] ?? [];
            if (empty($ids)) {
                continue;
            }
            $field = self::resourceToField($resource);
            foreach ($ids as $envId) {
                foreach ($envs as $envObj) {
                    if ((int)$envObj->id === (int)$envId) {
                        $envObj->update([$field => -1]);
                        $updatedEnvironments++;
                        $changedFields++;
                        break;
                    }
                }
            }
        }

        foreach ($envs as $env) {
            $eid = (int)$env->id;
            $eTotals = self::toResourceArray($envEffective[$eid] ?? []);

            $apps = Application::forEnvironment($eid);
            if (empty($apps)) {
                continue;
            }

            $appConfigured = [];
            foreach ($apps as $a) {
                $appConfigured[(int)$a->id] = ResourceHierarchy::applicationConfigured($a);
            }

            $appLevel = self::enforceLevel($eTotals, $appConfigured);
            foreach (self::RESOURCES as $resource) {
                $ids = $appLevel['changedByResource'][$resource] ?? [];
                if (empty($ids)) {
                    continue;
                }
                $field = self::resourceToField($resource);
                foreach ($ids as $appId) {
                    foreach ($apps as $appObj) {
                        if ((int)$appObj->id === (int)$appId) {
                            $appObj->update([$field => -1]);
                            $updatedApplications++;
                            $changedFields++;
                            break;
                        }
                    }
                }
            }
        }

        return [
            'updated_environments' => $updatedEnvironments,
            'updated_applications' => $updatedApplications,
            'changed_fields' => $changedFields,
        ];
    }

    /** @return array{updated_applications:int,changed_fields:int} */
    public static function enforceUnderEnvironment(Environment $environment): array
    {
        $envTotals = ResourceHierarchy::effectiveEnvironmentLimits($environment);
        $apps = Application::forEnvironment((int)$environment->id);
        if (empty($apps)) {
            return ['updated_applications' => 0, 'changed_fields' => 0];
        }

        $appConfigured = [];
        foreach ($apps as $a) {
            $appConfigured[(int)$a->id] = ResourceHierarchy::applicationConfigured($a);
        }

        $level = self::enforceLevel($envTotals, $appConfigured);

        $updatedApplications = 0;
        $changedFields = 0;
        foreach (self::RESOURCES as $resource) {
            $ids = $level['changedByResource'][$resource] ?? [];
            if (empty($ids)) {
                continue;
            }
            $field = self::resourceToField($resource);
            foreach ($ids as $appId) {
                foreach ($apps as $appObj) {
                    if ((int)$appObj->id === (int)$appId) {
                        $appObj->update([$field => -1]);
                        $updatedApplications++;
                        $changedFields++;
                        break;
                    }
                }
            }
        }

        return ['updated_applications' => $updatedApplications, 'changed_fields' => $changedFields];
    }

    /** @return array{updated_teams:int,updated_projects:int,updated_environments:int,updated_applications:int,changed_fields:int} */
    public static function enforceUnderUser(User $user): array
    {
        $userTotals = ResourceHierarchy::userMax($user);

        $teams = ResourceHierarchy::teamsOwnedByUser((int)$user->id);
        if (empty($teams)) {
            return [
                'updated_teams' => 0,
                'updated_projects' => 0,
                'updated_environments' => 0,
                'updated_applications' => 0,
                'changed_fields' => 0,
            ];
        }

        $teamConfigured = [];
        foreach ($teams as $t) {
            $teamConfigured[(int)$t->id] = ResourceHierarchy::teamConfigured($t);
        }

        $teamLevel = self::enforceLevel($userTotals, $teamConfigured);
        $teamEffective = $teamLevel['effective'];

        $updatedTeams = 0;
        $updatedProjects = 0;
        $updatedEnvironments = 0;
        $updatedApplications = 0;
        $changedFields = 0;

        foreach (self::RESOURCES as $resource) {
            $ids = $teamLevel['changedByResource'][$resource] ?? [];
            if (empty($ids)) {
                continue;
            }
            $field = self::resourceToField($resource);
            foreach ($ids as $teamId) {
                foreach ($teams as $teamObj) {
                    if ((int)$teamObj->id === (int)$teamId) {
                        $teamObj->update([$field => -1]);
                        $updatedTeams++;
                        $changedFields++;
                        break;
                    }
                }
            }
        }

        foreach ($teams as $team) {
            $tid = (int)$team->id;
            $tTotals = self::toResourceArray($teamEffective[$tid] ?? []);

            $projects = Project::forTeam($tid);
            $projectConfigured = [];
            foreach ($projects as $p) {
                $projectConfigured[(int)$p->id] = ResourceHierarchy::projectConfigured($p);
            }

            $projectLevel = self::enforceLevel($tTotals, $projectConfigured);
            $projectEffective = $projectLevel['effective'];

            foreach (self::RESOURCES as $resource) {
                $ids = $projectLevel['changedByResource'][$resource] ?? [];
                if (empty($ids)) {
                    continue;
                }
                $field = self::resourceToField($resource);
                foreach ($ids as $projectId) {
                    foreach ($projects as $projectObj) {
                        if ((int)$projectObj->id === (int)$projectId) {
                            $projectObj->update([$field => -1]);
                            $updatedProjects++;
                            $changedFields++;
                            break;
                        }
                    }
                }
            }

            foreach ($projects as $project) {
                $pid = (int)$project->id;
                $pTotals = self::toResourceArray($projectEffective[$pid] ?? []);

                $envs = Environment::forProject($pid);
                $envConfigured = [];
                foreach ($envs as $e) {
                    $envConfigured[(int)$e->id] = ResourceHierarchy::environmentConfigured($e);
                }

                $envLevel = self::enforceLevel($pTotals, $envConfigured);
                $envEffective = $envLevel['effective'];

                foreach (self::RESOURCES as $resource) {
                    $ids = $envLevel['changedByResource'][$resource] ?? [];
                    if (empty($ids)) {
                        continue;
                    }
                    $field = self::resourceToField($resource);
                    foreach ($ids as $envId) {
                        foreach ($envs as $envObj) {
                            if ((int)$envObj->id === (int)$envId) {
                                $envObj->update([$field => -1]);
                                $updatedEnvironments++;
                                $changedFields++;
                                break;
                            }
                        }
                    }
                }

                foreach ($envs as $env) {
                    $eid = (int)$env->id;
                    $eTotals = self::toResourceArray($envEffective[$eid] ?? []);

                    $apps = Application::forEnvironment($eid);
                    if (empty($apps)) {
                        continue;
                    }
                    $appConfigured = [];
                    foreach ($apps as $a) {
                        $appConfigured[(int)$a->id] = ResourceHierarchy::applicationConfigured($a);
                    }

                    $appLevel = self::enforceLevel($eTotals, $appConfigured);
                    foreach (self::RESOURCES as $resource) {
                        $ids = $appLevel['changedByResource'][$resource] ?? [];
                        if (empty($ids)) {
                            continue;
                        }
                        $field = self::resourceToField($resource);
                        foreach ($ids as $appId) {
                            foreach ($apps as $appObj) {
                                if ((int)$appObj->id === (int)$appId) {
                                    $appObj->update([$field => -1]);
                                    $updatedApplications++;
                                    $changedFields++;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        return [
            'updated_teams' => $updatedTeams,
            'updated_projects' => $updatedProjects,
            'updated_environments' => $updatedEnvironments,
            'updated_applications' => $updatedApplications,
            'changed_fields' => $changedFields,
        ];
    }

    private static function resourceToField(string $resource): string
    {
        return match ($resource) {
            'cpu_millicores' => 'cpu_millicores_limit',
            'ram_mb' => 'ram_mb_limit',
            'storage_mb' => 'storage_mb_limit',
            'ports' => 'port_limit',
            'bandwidth_mbps' => 'bandwidth_mbps_limit',
            'pids' => 'pids_limit',
            default => throw new \InvalidArgumentException('Unknown resource: ' . $resource),
        };
    }

    /**
     * Redeploy applications best-effort.
     *
     * @param int[] $applicationIds
     */
    public static function redeployApplications(array $applicationIds, ?User $actor, string $triggeredBy): array
    {
        $started = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($applicationIds as $appId) {
            $app = Application::find((int)$appId);
            if (!$app) {
                $skipped++;
                continue;
            }

            if (empty($app->node_id)) {
                $skipped++;
                continue;
            }

            $status = (string)($app->status ?? '');
            if (!in_array($status, ['running', 'restarting'], true)) {
                $skipped++;
                continue;
            }

            try {
                DeploymentService::create($app, null, [
                    'triggered_by' => $triggeredBy,
                    'triggered_by_name' => $actor?->displayName(),
                    'reason' => 'resource_limits_reduced',
                ]);
                $started++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        return ['started' => $started, 'skipped' => $skipped, 'failed' => $failed];
    }
}
