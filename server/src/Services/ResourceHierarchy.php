<?php

namespace Chap\Services;

use Chap\App;
use Chap\Models\Application;
use Chap\Models\Environment;
use Chap\Models\Project;
use Chap\Models\Team;
use Chap\Models\User;

class ResourceHierarchy
{
    /** @return array{cpu_millicores:int, ram_mb:int, storage_mb:int, ports:int, bandwidth_mbps:int, pids:int} */
    public static function userMax(User $user): array
    {
        $cpu = (int)$user->max_cpu_millicores;
        $ram = (int)$user->max_ram_mb;
        $storage = (int)$user->max_storage_mb;
        $ports = (int)$user->max_ports;
        $bandwidth = (int)$user->max_bandwidth_mbps;
        $pids = (int)$user->max_pids;

        return [
            // -1 means unlimited at the user level.
            'cpu_millicores' => $cpu === -1 ? -1 : max(0, $cpu),
            'ram_mb' => $ram === -1 ? -1 : max(0, $ram),
            'storage_mb' => $storage === -1 ? -1 : max(0, $storage),
            'ports' => $ports === -1 ? -1 : max(0, $ports),
            'bandwidth_mbps' => $bandwidth === -1 ? -1 : max(0, $bandwidth),
            'pids' => $pids === -1 ? -1 : max(0, $pids),
        ];
    }

    /** @return array{cpu_millicores:int, ram_mb:int, storage_mb:int, ports:int, bandwidth_mbps:int, pids:int} */
    public static function teamConfigured(Team $team): array
    {
        return [
            'cpu_millicores' => (int)$team->cpu_millicores_limit,
            'ram_mb' => (int)$team->ram_mb_limit,
            'storage_mb' => (int)$team->storage_mb_limit,
            'ports' => (int)$team->port_limit,
            'bandwidth_mbps' => (int)$team->bandwidth_mbps_limit,
            'pids' => (int)$team->pids_limit,
        ];
    }

    /** @return array{cpu_millicores:int, ram_mb:int, storage_mb:int, ports:int, bandwidth_mbps:int, pids:int} */
    public static function projectConfigured(Project $project): array
    {
        return [
            'cpu_millicores' => (int)$project->cpu_millicores_limit,
            'ram_mb' => (int)$project->ram_mb_limit,
            'storage_mb' => (int)$project->storage_mb_limit,
            'ports' => (int)$project->port_limit,
            'bandwidth_mbps' => (int)$project->bandwidth_mbps_limit,
            'pids' => (int)$project->pids_limit,
        ];
    }

    /** @return array{cpu_millicores:int, ram_mb:int, storage_mb:int, ports:int, bandwidth_mbps:int, pids:int} */
    public static function environmentConfigured(Environment $environment): array
    {
        return [
            'cpu_millicores' => (int)$environment->cpu_millicores_limit,
            'ram_mb' => (int)$environment->ram_mb_limit,
            'storage_mb' => (int)$environment->storage_mb_limit,
            'ports' => (int)$environment->port_limit,
            'bandwidth_mbps' => (int)$environment->bandwidth_mbps_limit,
            'pids' => (int)$environment->pids_limit,
        ];
    }

    /** @return array{cpu_millicores:int, ram_mb:int, storage_mb:int, ports:int, bandwidth_mbps:int, pids:int} */
    public static function applicationConfigured(Application $application): array
    {
        return [
            'cpu_millicores' => (int)$application->cpu_millicores_limit,
            'ram_mb' => (int)$application->ram_mb_limit,
            'storage_mb' => (int)$application->storage_mb_limit,
            'ports' => (int)$application->port_limit,
            'bandwidth_mbps' => (int)$application->bandwidth_mbps_limit,
            'pids' => (int)$application->pids_limit,
        ];
    }

    /** @return Team[] */
    public static function teamsOwnedByUser(int $userId): array
    {
        $db = App::db();
        $rows = $db->fetchAll(
            "SELECT t.* FROM teams t JOIN team_user tu ON tu.team_id = t.id WHERE tu.user_id = ? AND tu.role = 'owner' ORDER BY t.name",
            [$userId]
        );
        return array_map(fn($r) => Team::fromArray($r), $rows);
    }

    /** @return array{cpu_millicores:int, ram_mb:int, storage_mb:int, ports:int, bandwidth_mbps:int, pids:int} */
    public static function effectiveTeamLimits(Team $team): array
    {
        $owner = $team->owner();
        if (!$owner) {
            // Should not happen; fall back to a safe baseline.
            return [
                'cpu_millicores' => 0,
                'ram_mb' => 0,
                'storage_mb' => 0,
                'ports' => 0,
                'bandwidth_mbps' => 0,
                'pids' => 0,
            ];
        }

        $parent = self::userMax($owner);
        $siblings = self::teamsOwnedByUser((int)$owner->id);

        return self::effectiveForChild(
            $parent,
            $team->id,
            array_map(fn($t) => ['id' => (int)$t->id, 'limits' => self::teamConfigured($t)], $siblings)
        );
    }

    /** @return array{cpu_millicores:int, ram_mb:int, storage_mb:int, ports:int, bandwidth_mbps:int, pids:int} */
    public static function effectiveProjectLimits(Project $project): array
    {
        $team = $project->team();
        if (!$team) {
            return self::zero();
        }

        $parent = self::effectiveTeamLimits($team);
        $siblings = Project::forTeam((int)$team->id);

        return self::effectiveForChild(
            $parent,
            $project->id,
            array_map(fn($p) => ['id' => (int)$p->id, 'limits' => self::projectConfigured($p)], $siblings)
        );
    }

    /** @return array{cpu_millicores:int, ram_mb:int, storage_mb:int, ports:int, bandwidth_mbps:int, pids:int} */
    public static function effectiveEnvironmentLimits(Environment $environment): array
    {
        $project = $environment->project();
        if (!$project) {
            return self::zero();
        }

        $parent = self::effectiveProjectLimits($project);
        $siblings = Environment::forProject((int)$project->id);

        return self::effectiveForChild(
            $parent,
            $environment->id,
            array_map(fn($e) => ['id' => (int)$e->id, 'limits' => self::environmentConfigured($e)], $siblings)
        );
    }

    /** @return array{cpu_millicores:int, ram_mb:int, storage_mb:int, ports:int, bandwidth_mbps:int, pids:int} */
    public static function effectiveApplicationLimits(Application $application): array
    {
        $environment = $application->environment();
        if (!$environment) {
            return self::zero();
        }

        $parent = self::effectiveEnvironmentLimits($environment);
        $siblings = Application::forEnvironment((int)$environment->id);

        return self::effectiveForChild(
            $parent,
            $application->id,
            array_map(fn($a) => ['id' => (int)$a->id, 'limits' => self::applicationConfigured($a)], $siblings)
        );
    }

    /**
     * @param array{cpu_millicores:int, ram_mb:int, storage_mb:int, ports:int, bandwidth_mbps:int, pids:int} $parent
     * @param int $childId
     * @param array<int,array{id:int,limits:array}> $siblings
     *
     * @return array{cpu_millicores:int, ram_mb:int, storage_mb:int, ports:int, bandwidth_mbps:int, pids:int}
     */
    private static function effectiveForChild(array $parent, int $childId, array $siblings): array
    {
        $resources = array_keys($parent);
        $effective = [];

        foreach ($resources as $resource) {
            $map = [];
            foreach ($siblings as $s) {
                $map[(int)$s['id']] = (int)($s['limits'][$resource] ?? -1);
            }

            $alloc = ResourceAllocator::allocateInt((int)$parent[$resource], $map);
            $effective[$resource] = (int)($alloc['effectiveByChildId'][(int)$childId] ?? 0);
        }

        /** @var array{cpu_millicores:int, ram_mb:int, storage_mb:int, ports:int, bandwidth_mbps:int, pids:int} */
        return $effective;
    }

    /** @return array{cpu_millicores:int, ram_mb:int, storage_mb:int, ports:int, bandwidth_mbps:int, pids:int} */
    private static function zero(): array
    {
        return [
            'cpu_millicores' => 0,
            'ram_mb' => 0,
            'storage_mb' => 0,
            'ports' => 0,
            'bandwidth_mbps' => 0,
            'pids' => 0,
        ];
    }

    /**
     * Parse CPU input from UI.
     * Accepts "-1" or a numeric core value like "0.5", "2".
     */
    public static function parseCpuMillicores(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        if (!is_numeric($value)) {
            return -1;
        }
        $v = (float)$value;
        if ($v <= 0) {
            return -1;
        }
        return (int)round($v * 1000);
    }

    /**
     * Parse RAM/storage input from UI as MB.
     * Accepts -1 or a plain integer MB.
     */
    public static function parseMb(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        if (!is_numeric($value)) {
            return -1;
        }
        $v = (int)$value;
        return $v > 0 ? $v : -1;
    }

    /**
     * Parse a Docker-style memory string to MB.
     * Examples: "512m", "1g", "2048".
     */
    public static function parseDockerMemoryToMb(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([kmg])?b?$/i', $value, $m)) {
            $num = (float)$m[1];
            $unit = strtolower($m[2] ?? 'm');
            $mb = match ($unit) {
                'k' => (int)round($num / 1024),
                'g' => (int)round($num * 1024),
                default => (int)round($num),
            };
            return $mb > 0 ? $mb : -1;
        }

        return -1;
    }

    public static function parseIntOrAuto(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        if (!is_numeric($value)) {
            return -1;
        }
        $v = (int)$value;
        return $v >= 0 ? $v : -1;
    }

    public static function cpuToCoresString(int $millicores): string
    {
        if ($millicores <= 0) {
            return '0';
        }
        $cores = $millicores / 1000;
        // Trim trailing zeros.
        $s = rtrim(rtrim(number_format($cores, 3, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    public static function ramMbToDockerString(int $ramMb): string
    {
        if ($ramMb <= 0) {
            return '0m';
        }
        return (string)$ramMb . 'm';
    }
}
