#!/usr/bin/env php
<?php
/**
 * Database Seeder
 */

require __DIR__ . '/../vendor/autoload.php';

use Chap\App;
use Chap\Database\Connection;
use Chap\Config;
use Chap\Auth\AuthManager;
use Chap\Services\TemplateRegistry;

// Load configuration
Config::load();

// Boot the application (needed for models/services using App::db())
$app = new App();
$app->boot();

echo "Seeding Chap Database...\n\n";

try {
    $db = new Connection();
    
    // Check if already seeded
    $users = $db->fetch("SELECT COUNT(*) as count FROM users");
    if ($users && $users['count'] > 0) {
        echo "Database already seeded.\n";
        exit(0);
    }
    
    // Create admin user
    echo "Creating admin user (Max / MJDawson)...\n";
    
    $uuid = uuid();
    $passwordHash = AuthManager::hashPassword('password');
    
    $userId = $db->insert('users', [
        'uuid' => $uuid,
        'email' => 'max@chap.dev',
        'username' => 'MJDawson',
        'password_hash' => $passwordHash,
        'name' => 'Max',
        'is_admin' => true,
        'email_verified_at' => date('Y-m-d H:i:s'),
    ]);
    
    echo "Created user: MJDawson (email: max@chap.dev)\n";
    echo "Default password: password\n\n";
    
    // Create personal team
    echo "Creating personal team...\n";
    
    $teamId = $db->insert('teams', [
        'uuid' => uuid(),
        'name' => "MJDawson's Team",
        'description' => 'Personal team',
        'personal_team' => true,
    ]);
    
    $db->insert('team_user', [
        'team_id' => $teamId,
        'user_id' => $userId,
        'role' => 'owner',
    ]);
    
    echo "Created team: MJDawson's Team\n\n";
    
    // Seed templates from the filesystem templates directory (official) and storage templates (user uploaded)
    echo "Syncing application templates from disk...\n";
    $result = TemplateRegistry::syncToDatabase();
    echo "Scanned: {$result['scanned']}, Upserted: {$result['upserted']}" . (isset($result['deactivated']) ? ", Deactivated: {$result['deactivated']}" : '') . "\n";
    
    echo "\nSeeding complete!\n";
    
} catch (Exception $e) {
    echo "Seeding failed: " . $e->getMessage() . "\n";
    exit(1);
}
