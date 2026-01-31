<?php
/**
 * Integration Tests: Models
 * 
 * Tests for the Model classes
 */

namespace Tests\Integration;

use Tests\TestCase;
use Chap\Models\User;
use Chap\Models\Team;
use Chap\Models\Node;
use Chap\Models\Project;
use Chap\Models\Template;

class ModelsTest extends TestCase
{
    /**
     * Test User model can find by email
     */
    public function testUserFindByEmail(): void
    {
        $user = User::findByEmail('admin@chap.dev');
        
        $this->assertNotNull($user, 'Should find seeded user');
        $this->assertEquals('admin@chap.dev', $user->email);
        $this->assertEquals('MJDawson', $user->username);
    }
    
    /**
     * Test User model can find by UUID
     */
    public function testUserFindByUuid(): void
    {
        $user = User::findByEmail('admin@chap.dev');
        $this->assertNotNull($user);
        
        $foundByUuid = User::findByUuid($user->uuid);
        $this->assertNotNull($foundByUuid);
        $this->assertEquals($user->id, $foundByUuid->id);
    }
    
    /**
     * Test User password verification
     */
    public function testUserPasswordVerification(): void
    {
        $user = User::findByEmail('admin@chap.dev');
        $this->assertNotNull($user);
        
        // Default password is 'password'
        $this->assertTrue($user->verifyPassword('password'), 'Should verify correct password');
        $this->assertFalse($user->verifyPassword('wrong_password'), 'Should reject wrong password');
    }
    
    /**
     * Test User toArray excludes sensitive data
     */
    public function testUserToArrayExcludesSensitive(): void
    {
        $user = User::findByEmail('admin@chap.dev');
        $array = $user->toArray();
        
        $this->assertArrayNotHasKey('password_hash', $array, 'Should not expose password hash');
        $this->assertArrayHasKey('email', $array);
        $this->assertArrayHasKey('username', $array);
    }
    
    /**
     * Test Team model
     */
    public function testTeamModel(): void
    {
        $db = $this->getDb();
        $teams = $db->query("SELECT * FROM teams LIMIT 1");
        $this->assertNotEmpty($teams);
        
        $team = Team::find($teams[0]['id']);
        $this->assertNotNull($team);
        $this->assertNotEmpty($team->name);
    }
    
    /**
     * Test Template model
     */
    public function testTemplateModel(): void
    {
        $templates = Template::all();
        
        $this->assertNotEmpty($templates, 'Should have templates');
        $this->assertGreaterThanOrEqual(5, count($templates));
        
        // Check a specific template
        $nginx = Template::findBySlug('nginx');
        $this->assertNotNull($nginx);
        $this->assertEquals('Nginx', $nginx->name);
    }
    
    /**
     * Test creating a Node
     */
    public function testCreateNode(): void
    {
        // Get a team
        $db = $this->getDb();
        $teams = $db->query("SELECT * FROM teams LIMIT 1");
        $teamId = $teams[0]['id'];
        
        // Create a node
        $node = Node::create([
            'team_id' => $teamId,
            'name' => 'test-node-' . time(),
            'token' => bin2hex(random_bytes(16)),
            'status' => 'pending',
        ]);
        
        $this->assertNotNull($node);
        $this->assertNotEmpty($node->id);
        $this->assertNotEmpty($node->uuid);
        $this->assertEquals('pending', $node->status);
        
        // Clean up
        $db->query("DELETE FROM nodes WHERE id = ?", [$node->id]);
    }
    
    /**
     * Test creating a Project
     */
    public function testCreateProject(): void
    {
        $db = $this->getDb();
        $teams = $db->query("SELECT * FROM teams LIMIT 1");
        $teamId = $teams[0]['id'];
        
        $project = Project::create([
            'team_id' => $teamId,
            'name' => 'Test Project ' . time(),
            'description' => 'Test description',
        ]);
        
        $this->assertNotNull($project);
        $this->assertNotEmpty($project->uuid);
        
        // Clean up
        $db->query("DELETE FROM projects WHERE id = ?", [$project->id]);
    }
    
    /**
     * Test Node::forTeam scope
     */
    public function testNodeForTeamScope(): void
    {
        $db = $this->getDb();
        $teams = $db->query("SELECT * FROM teams LIMIT 1");
        $teamId = $teams[0]['id'];
        
        // Create a test node
        $node = Node::create([
            'team_id' => $teamId,
            'name' => 'scope-test-node',
            'token' => bin2hex(random_bytes(16)),
            'status' => 'pending',
        ]);
        
        // Test scope
        $nodes = Node::forTeam($teamId);
        $this->assertIsArray($nodes);
        
        $nodeIds = array_map(fn($n) => $n->id, $nodes);
        $this->assertContains($node->id, $nodeIds);
        
        // Clean up
        $db->query("DELETE FROM nodes WHERE id = ?", [$node->id]);
    }
    
    /**
     * Test model update
     */
    public function testModelUpdate(): void
    {
        $db = $this->getDb();
        $teams = $db->query("SELECT * FROM teams LIMIT 1");
        $teamId = $teams[0]['id'];
        
        // Create a node
        $node = Node::create([
            'team_id' => $teamId,
            'name' => 'update-test-node',
            'token' => bin2hex(random_bytes(16)),
            'status' => 'pending',
        ]);
        
        // Update it
        $node->update(['status' => 'online']);
        
        // Refetch and verify
        $updated = Node::find($node->id);
        $this->assertEquals('online', $updated->status);
        
        // Clean up
        $db->query("DELETE FROM nodes WHERE id = ?", [$node->id]);
    }
    
    /**
     * Test model delete
     */
    public function testModelDelete(): void
    {
        $db = $this->getDb();
        $teams = $db->query("SELECT * FROM teams LIMIT 1");
        $teamId = $teams[0]['id'];
        
        // Create a node
        $node = Node::create([
            'team_id' => $teamId,
            'name' => 'delete-test-node',
            'token' => bin2hex(random_bytes(16)),
            'status' => 'pending',
        ]);
        
        $nodeId = $node->id;
        
        // Delete it
        $node->delete();
        
        // Verify it's gone
        $deleted = Node::find($nodeId);
        $this->assertNull($deleted);
    }
}
