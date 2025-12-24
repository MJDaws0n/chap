<?php

namespace Chap\Database;

use Exception;

class Migrator
{
    /**
     * Run all pending migrations from the given directory.
     *
     * @return int Number of migrations applied.
     */
    public static function migrate(Connection $db, string $migrationDir): int
    {
        self::ensureMigrationsTable($db);

        $completedNames = self::getCompletedMigrationNames($db);

        $files = glob(rtrim($migrationDir, '/') . '/*.php') ?: [];
        sort($files);

        $batch = self::nextBatchNumber($db);

        $count = 0;
        foreach ($files as $file) {
            $name = basename($file, '.php');

            if (in_array($name, $completedNames, true)) {
                continue;
            }

            echo "Migrating: {$name}\n";

            $migration = require $file;
            if (is_array($migration) && isset($migration['up']) && is_callable($migration['up'])) {
                $migration['up']($db);
            }

            $db->insert('migrations', [
                'migration' => $name,
                'batch' => $batch,
            ]);

            $count++;
            echo "Migrated: {$name}\n";
        }

        return $count;
    }

    /**
     * Auto-run pending migrations (intended for development).
     */
    public static function autoMigrate(Connection $db, string $migrationDir, string $lockFile): void
    {
        $env = getenv('APP_ENV') ?: '';
        $debug = getenv('APP_DEBUG') === 'true';
        if ($env !== 'development' || !$debug) {
            return;
        }

        $lockHandle = @fopen($lockFile, 'c+');
        if ($lockHandle === false) {
            // If we cannot lock (e.g. permissions), still attempt migrations once.
            self::migrate($db, $migrationDir);
            return;
        }

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                return;
            }

            self::migrate($db, $migrationDir);
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    }

    private static function ensureMigrationsTable(Connection $db): void
    {
        $db->query(
            "CREATE TABLE IF NOT EXISTS migrations (\n"
            . "  id INT PRIMARY KEY AUTO_INCREMENT,\n"
            . "  migration VARCHAR(255) NOT NULL,\n"
            . "  batch INT NOT NULL,\n"
            . "  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n"
            . ")"
        );
    }

    /** @return string[] */
    private static function getCompletedMigrationNames(Connection $db): array
    {
        $completed = $db->fetchAll("SELECT migration FROM migrations") ?: [];
        return array_values(array_filter(array_column($completed, 'migration')));
    }

    private static function nextBatchNumber(Connection $db): int
    {
        $result = $db->fetch("SELECT MAX(batch) as max_batch FROM migrations");
        return (int) (($result['max_batch'] ?? 0) + 1);
    }
}
