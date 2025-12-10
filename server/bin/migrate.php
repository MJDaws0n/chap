#!/usr/bin/env php
<?php
/**
 * Database Migration Runner
 */

require __DIR__ . '/../vendor/autoload.php';

use Chap\Database\Connection;
use Chap\Config;

// Load configuration
Config::load();

echo "Running Chap Database Migrations...\n\n";

try {
    $db = new Connection();
    
    // Create migrations table if not exists
    $db->query("
        CREATE TABLE IF NOT EXISTS migrations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            migration VARCHAR(255) NOT NULL,
            batch INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // Get completed migrations
    $completed = $db->fetchAll("SELECT migration FROM migrations");
    $completedNames = array_column($completed, 'migration');
    
    // Get migration files
    $migrationDir = __DIR__ . '/../migrations';
    $files = glob($migrationDir . '/*.php');
    sort($files);
    
    // Get current batch
    $result = $db->fetch("SELECT MAX(batch) as max_batch FROM migrations");
    $batch = ($result['max_batch'] ?? 0) + 1;
    
    $count = 0;
    foreach ($files as $file) {
        $name = basename($file, '.php');
        
        if (in_array($name, $completedNames)) {
            continue;
        }
        
        echo "Migrating: {$name}\n";
        
        // Include and run migration
        $migration = require $file;
        
        if (is_array($migration) && isset($migration['up'])) {
            $migration['up']($db);
        }
        
        // Record migration
        $db->insert('migrations', [
            'migration' => $name,
            'batch' => $batch,
        ]);
        
        $count++;
        echo "Migrated: {$name}\n";
    }
    
    if ($count === 0) {
        echo "Nothing to migrate.\n";
    } else {
        echo "\nMigrated {$count} migration(s).\n";
    }
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
