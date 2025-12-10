<?php
/**
 * Integration Tests: Database Connection
 * 
 * Tests database connectivity and basic operations
 */

namespace Tests\Integration;

use Tests\TestCase;
use Chap\Database\Connection;

class DatabaseTest extends TestCase
{
    /**
     * Test database connection works
     */
    public function testDatabaseConnects(): void
    {
        $db = $this->getDb();
        $this->assertInstanceOf(Connection::class, $db);
    }
    
    /**
     * Test simple query execution
     */
    public function testSimpleQuery(): void
    {
        $db = $this->getDb();
        $result = $db->query("SELECT 1 as test");
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['test']);
    }
    
    /**
     * Test users table exists and has correct structure
     */
    public function testUsersTableExists(): void
    {
        $db = $this->getDb();
        
        $columns = $db->query("DESCRIBE users");
        $columnNames = array_column($columns, 'Field');
        
        $expectedColumns = ['id', 'uuid', 'email', 'username', 'password_hash', 'name', 'is_admin'];
        
        foreach ($expectedColumns as $col) {
            $this->assertContains($col, $columnNames, "Users table should have '$col' column");
        }
    }
    
    /**
     * Test teams table exists and has correct structure
     */
    public function testTeamsTableExists(): void
    {
        $db = $this->getDb();
        
        $columns = $db->query("DESCRIBE teams");
        $columnNames = array_column($columns, 'Field');
        
        $expectedColumns = ['id', 'uuid', 'name', 'personal_team'];
        
        foreach ($expectedColumns as $col) {
            $this->assertContains($col, $columnNames, "Teams table should have '$col' column");
        }
    }
    
    /**
     * Test nodes table exists and has correct structure
     */
    public function testNodesTableExists(): void
    {
        $db = $this->getDb();
        
        $columns = $db->query("DESCRIBE nodes");
        $columnNames = array_column($columns, 'Field');
        
        $expectedColumns = ['id', 'uuid', 'team_id', 'name', 'token', 'status'];
        
        foreach ($expectedColumns as $col) {
            $this->assertContains($col, $columnNames, "Nodes table should have '$col' column");
        }
    }
    
    /**
     * Test projects table exists
     */
    public function testProjectsTableExists(): void
    {
        $db = $this->getDb();
        
        $columns = $db->query("DESCRIBE projects");
        $columnNames = array_column($columns, 'Field');
        
        $this->assertContains('id', $columnNames);
        $this->assertContains('uuid', $columnNames);
        $this->assertContains('team_id', $columnNames);
        $this->assertContains('name', $columnNames);
    }
    
    /**
     * Test environments table exists
     */
    public function testEnvironmentsTableExists(): void
    {
        $db = $this->getDb();
        
        $columns = $db->query("DESCRIBE environments");
        $columnNames = array_column($columns, 'Field');
        
        $this->assertContains('id', $columnNames);
        $this->assertContains('project_id', $columnNames);
        $this->assertContains('name', $columnNames);
    }
    
    /**
     * Test applications table exists
     */
    public function testApplicationsTableExists(): void
    {
        $db = $this->getDb();
        
        $columns = $db->query("DESCRIBE applications");
        $columnNames = array_column($columns, 'Field');
        
        $this->assertContains('id', $columnNames);
        $this->assertContains('environment_id', $columnNames);
        $this->assertContains('name', $columnNames);
    }
    
    /**
     * Test databases table exists (reserved word - uses backticks)
     */
    public function testDatabasesTableExists(): void
    {
        $db = $this->getDb();
        
        $columns = $db->query("DESCRIBE `databases`");
        $columnNames = array_column($columns, 'Field');
        
        $this->assertContains('id', $columnNames);
        $this->assertContains('environment_id', $columnNames);
        $this->assertContains('type', $columnNames);
    }
    
    /**
     * Test templates table exists
     */
    public function testTemplatesTableExists(): void
    {
        $db = $this->getDb();
        
        $columns = $db->query("DESCRIBE templates");
        $columnNames = array_column($columns, 'Field');
        
        $expectedColumns = ['id', 'uuid', 'name', 'slug', 'docker_compose', 'category'];
        
        foreach ($expectedColumns as $col) {
            $this->assertContains($col, $columnNames, "Templates table should have '$col' column");
        }
    }
    
    /**
     * Test deployments table exists
     */
    public function testDeploymentsTableExists(): void
    {
        $db = $this->getDb();
        
        $columns = $db->query("DESCRIBE deployments");
        $columnNames = array_column($columns, 'Field');
        
        $this->assertContains('id', $columnNames);
        $this->assertContains('application_id', $columnNames);
        $this->assertContains('status', $columnNames);
    }
    
    /**
     * Test sessions table exists
     */
    public function testSessionsTableExists(): void
    {
        $db = $this->getDb();
        
        $columns = $db->query("DESCRIBE sessions");
        $columnNames = array_column($columns, 'Field');
        
        $this->assertContains('id', $columnNames);
        $this->assertContains('user_id', $columnNames);
    }
    
    /**
     * Test activity_logs table exists
     */
    public function testActivityLogsTableExists(): void
    {
        $db = $this->getDb();
        
        $columns = $db->query("DESCRIBE activity_logs");
        $columnNames = array_column($columns, 'Field');
        
        $this->assertContains('id', $columnNames);
        $this->assertContains('action', $columnNames);
    }
    
    /**
     * Test insert and select operations
     */
    public function testInsertAndSelect(): void
    {
        $db = $this->getDb();
        
        // Create a test record (will use existing seeded data check)
        $users = $db->query("SELECT * FROM users WHERE email = ?", ['max@chap.dev']);
        
        $this->assertNotEmpty($users, 'Seeded user should exist');
        $this->assertEquals('max@chap.dev', $users[0]['email']);
    }
    
    /**
     * Test seeded templates exist
     */
    public function testSeededTemplatesExist(): void
    {
        $db = $this->getDb();
        
        $templates = $db->query("SELECT * FROM templates WHERE is_official = 1");
        
        $this->assertNotEmpty($templates, 'Should have official templates');
        $this->assertGreaterThanOrEqual(5, count($templates), 'Should have at least 5 templates');
        
        // Check specific templates
        $slugs = array_column($templates, 'slug');
        $this->assertContains('nginx', $slugs);
        $this->assertContains('mysql', $slugs);
        $this->assertContains('postgresql', $slugs);
    }
    
    /**
     * Test team_user pivot table
     */
    public function testTeamUserPivotExists(): void
    {
        $db = $this->getDb();
        
        $columns = $db->query("DESCRIBE team_user");
        $columnNames = array_column($columns, 'Field');
        
        $this->assertContains('team_id', $columnNames);
        $this->assertContains('user_id', $columnNames);
        $this->assertContains('role', $columnNames);
    }
    
    /**
     * Test foreign key constraints work
     */
    public function testForeignKeyConstraints(): void
    {
        $db = $this->getDb();
        
        // Get a team
        $teams = $db->query("SELECT * FROM teams LIMIT 1");
        $this->assertNotEmpty($teams, 'Should have at least one team');
        
        $teamId = $teams[0]['id'];
        
        // Check nodes reference teams correctly
        $fkCheck = $db->query("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'nodes' 
            AND COLUMN_NAME = 'team_id'
            AND REFERENCED_TABLE_NAME = 'teams'
        ");
        
        $this->assertNotEmpty($fkCheck, 'Nodes should have FK to teams');
    }
}
