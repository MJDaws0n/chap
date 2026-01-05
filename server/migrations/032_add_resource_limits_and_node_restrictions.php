<?php
/**
 * Add resource limits to users/teams/projects/environments/applications
 * and add per-level node restrictions.
 */

return [
    'up' => function($db) {
        // USERS: admin-set hard maximums
        $columns = [];
        foreach ($db->fetchAll("SHOW COLUMNS FROM users") as $row) {
            $columns[] = $row['Field'];
        }

        $addUserColumn = function(string $name, string $sql) use ($db, $columns) {
            if (!in_array($name, $columns, true)) {
                $db->query($sql);
            }
        };

        // Defaults are intentionally conservative but non-breaking.
        $addUserColumn('max_cpu_millicores', "ALTER TABLE users ADD COLUMN max_cpu_millicores INT NOT NULL DEFAULT 2000 AFTER is_admin");
        $addUserColumn('max_ram_mb', "ALTER TABLE users ADD COLUMN max_ram_mb INT NOT NULL DEFAULT 4096 AFTER max_cpu_millicores");
        $addUserColumn('max_storage_mb', "ALTER TABLE users ADD COLUMN max_storage_mb INT NOT NULL DEFAULT 20480 AFTER max_ram_mb");
        $addUserColumn('max_ports', "ALTER TABLE users ADD COLUMN max_ports INT NOT NULL DEFAULT 50 AFTER max_storage_mb");
        $addUserColumn('max_bandwidth_mbps', "ALTER TABLE users ADD COLUMN max_bandwidth_mbps INT NOT NULL DEFAULT 100 AFTER max_ports");
        $addUserColumn('max_pids', "ALTER TABLE users ADD COLUMN max_pids INT NOT NULL DEFAULT 1024 AFTER max_bandwidth_mbps");

        $addUserColumn('max_teams', "ALTER TABLE users ADD COLUMN max_teams INT NOT NULL DEFAULT 25 AFTER max_pids");
        $addUserColumn('max_projects', "ALTER TABLE users ADD COLUMN max_projects INT NOT NULL DEFAULT 50 AFTER max_teams");
        $addUserColumn('max_environments', "ALTER TABLE users ADD COLUMN max_environments INT NOT NULL DEFAULT 200 AFTER max_projects");
        $addUserColumn('max_applications', "ALTER TABLE users ADD COLUMN max_applications INT NOT NULL DEFAULT 200 AFTER max_environments");

        // Helper to add limits columns to a table
        $ensureLimitColumns = function(string $table) use ($db) {
            $columns = [];
            foreach ($db->fetchAll("SHOW COLUMNS FROM {$table}") as $row) {
                $columns[] = $row['Field'];
            }

            $add = function(string $name, string $sql) use ($db, $columns) {
                if (!in_array($name, $columns, true)) {
                    $db->query($sql);
                }
            };

            $add('cpu_millicores_limit', "ALTER TABLE {$table} ADD COLUMN cpu_millicores_limit INT NOT NULL DEFAULT -1");
            $add('ram_mb_limit', "ALTER TABLE {$table} ADD COLUMN ram_mb_limit INT NOT NULL DEFAULT -1");
            $add('storage_mb_limit', "ALTER TABLE {$table} ADD COLUMN storage_mb_limit INT NOT NULL DEFAULT -1");
            $add('port_limit', "ALTER TABLE {$table} ADD COLUMN port_limit INT NOT NULL DEFAULT -1");
            $add('bandwidth_mbps_limit', "ALTER TABLE {$table} ADD COLUMN bandwidth_mbps_limit INT NOT NULL DEFAULT -1");
            $add('pids_limit', "ALTER TABLE {$table} ADD COLUMN pids_limit INT NOT NULL DEFAULT -1");
            $add('allowed_node_ids', "ALTER TABLE {$table} ADD COLUMN allowed_node_ids JSON NULL");
        };

        $ensureLimitColumns('teams');
        $ensureLimitColumns('projects');
        $ensureLimitColumns('environments');

        // Applications: add limits columns and backfill from existing cpu_limit/memory_limit when possible
        $ensureLimitColumns('applications');

        $appColumns = [];
        foreach ($db->fetchAll("SHOW COLUMNS FROM applications") as $row) {
            $appColumns[] = $row['Field'];
        }

        // Backfill numeric limits for existing rows only if columns exist
        if (in_array('cpu_millicores_limit', $appColumns, true) && in_array('ram_mb_limit', $appColumns, true)) {
            $apps = $db->fetchAll("SELECT id, cpu_limit, memory_limit FROM applications");

            $parseCpuMillicores = function($cpu): int {
                $cpu = trim((string)$cpu);
                if ($cpu === '' || $cpu === '-1') {
                    return -1;
                }
                if (!is_numeric($cpu)) {
                    return -1;
                }
                $v = (float)$cpu;
                if ($v <= 0) {
                    return -1;
                }
                return (int)round($v * 1000);
            };

            $parseMemoryMb = function($mem): int {
                $mem = trim((string)$mem);
                if ($mem === '' || $mem === '-1') {
                    return -1;
                }
                if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([kmg])?b?$/i', $mem, $m)) {
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
            };

            foreach ($apps as $app) {
                $cpu = $parseCpuMillicores($app['cpu_limit'] ?? '');
                $ram = $parseMemoryMb($app['memory_limit'] ?? '');

                // If an app already has explicit string limits, treat them as fixed allocations.
                // Otherwise keep -1 to auto-split.
                $db->update('applications', [
                    'cpu_millicores_limit' => $cpu,
                    'ram_mb_limit' => $ram,
                ], 'id = ?', [(int)$app['id']]);
            }
        }
    },

    'down' => function($db) {
        // Best-effort down migrations.
        // MySQL doesn't support DROP COLUMN IF EXISTS reliably across versions,
        // but this project uses it elsewhere, so keep the pattern.
        $db->query("ALTER TABLE users DROP COLUMN IF EXISTS max_cpu_millicores");
        $db->query("ALTER TABLE users DROP COLUMN IF EXISTS max_ram_mb");
        $db->query("ALTER TABLE users DROP COLUMN IF EXISTS max_storage_mb");
        $db->query("ALTER TABLE users DROP COLUMN IF EXISTS max_ports");
        $db->query("ALTER TABLE users DROP COLUMN IF EXISTS max_bandwidth_mbps");
        $db->query("ALTER TABLE users DROP COLUMN IF EXISTS max_pids");
        $db->query("ALTER TABLE users DROP COLUMN IF EXISTS max_teams");
        $db->query("ALTER TABLE users DROP COLUMN IF EXISTS max_projects");
        $db->query("ALTER TABLE users DROP COLUMN IF EXISTS max_environments");
        $db->query("ALTER TABLE users DROP COLUMN IF EXISTS max_applications");

        foreach (['teams', 'projects', 'environments', 'applications'] as $table) {
            $db->query("ALTER TABLE {$table} DROP COLUMN IF EXISTS cpu_millicores_limit");
            $db->query("ALTER TABLE {$table} DROP COLUMN IF EXISTS ram_mb_limit");
            $db->query("ALTER TABLE {$table} DROP COLUMN IF EXISTS storage_mb_limit");
            $db->query("ALTER TABLE {$table} DROP COLUMN IF EXISTS port_limit");
            $db->query("ALTER TABLE {$table} DROP COLUMN IF EXISTS bandwidth_mbps_limit");
            $db->query("ALTER TABLE {$table} DROP COLUMN IF EXISTS pids_limit");
            $db->query("ALTER TABLE {$table} DROP COLUMN IF EXISTS allowed_node_ids");
        }
    }
];
