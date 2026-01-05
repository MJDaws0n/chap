#!/usr/bin/env php
<?php
/**
 * Cleanup Orphaned Personal Teams
 *
 * Deletes `teams` rows where:
 * - `personal_team = 1`
 * - there are no rows in `team_user` referencing the team
 *
 * These can happen if a user was deleted (which cascades `team_user`),
 * leaving the `teams` row behind.
 */

require __DIR__ . '/../vendor/autoload.php';

use Chap\Database\Connection;
use Chap\Config;

Config::load();

try {
    $db = new Connection();

    $countStmt = $db->query(
        "SELECT COUNT(*) AS count\n" .
        "FROM teams t\n" .
        "LEFT JOIN team_user tu ON tu.team_id = t.id\n" .
        "WHERE t.personal_team = 1 AND tu.team_id IS NULL"
    );
    $countRow = $countStmt->fetch();
    $count = (int)($countRow['count'] ?? 0);

    if ($count === 0) {
        echo "No orphaned personal teams found.\n";
        exit(0);
    }

    $db->beginTransaction();

    $stmt = $db->query(
        "DELETE t\n" .
        "FROM teams t\n" .
        "LEFT JOIN team_user tu ON tu.team_id = t.id\n" .
        "WHERE t.personal_team = 1 AND tu.team_id IS NULL"
    );

    $deleted = $stmt->rowCount();
    $db->commit();

    echo "Deleted {$deleted} orphaned personal team(s).\n";
    exit(0);
} catch (Throwable $e) {
    try {
        if (isset($db)) {
            $db->rollback();
        }
    } catch (Throwable) {
        // ignore
    }

    fwrite(STDERR, "Cleanup failed: " . $e->getMessage() . "\n");
    exit(1);
}
