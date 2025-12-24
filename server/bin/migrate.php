#!/usr/bin/env php
<?php
/**
 * Database Migration Runner
 */

require __DIR__ . '/../vendor/autoload.php';

use Chap\Database\Connection;
use Chap\Database\Migrator;
use Chap\Config;

// Load configuration
Config::load();

echo "Running Chap Database Migrations...\n\n";

try {
    $db = new Connection();

    $migrationDir = __DIR__ . '/../migrations';
    $count = Migrator::migrate($db, $migrationDir);

    if ($count === 0) {
        echo "Nothing to migrate.\n";
    } else {
        echo "\nMigrated {$count} migration(s).\n";
    }
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

