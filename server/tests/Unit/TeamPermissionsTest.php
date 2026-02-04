<?php
/**
 * Unit Tests: TeamPermissions
 * 
 * Tests for the TeamPermissions definitions
 */

namespace Tests\Unit;

use Tests\TestCase;
use Chap\Auth\TeamPermissions;

class TeamPermissionsTest extends TestCase
{
    /**
     * Test that all required permission keys are defined
     */
    public function testAllPermissionKeysDefined(): void
    {
        $required = [
            'team.settings',
            'team.members',
            'team.roles',
            'projects',
            'environments',
            'applications',
            'applications.resources',
            'files',
            'volumes',
            'volume_files',
            'deployments',
            'logs',
            'exec',
            'templates',
            'git_sources',
            'activity',
        ];
        
        foreach ($required as $key) {
            $this->assertContains(
                $key, 
                TeamPermissions::KEYS, 
                "Permission key '{$key}' should be defined in KEYS"
            );
        }
    }
    
    /**
     * Test that exec permission key exists (for bash execution feature)
     */
    public function testExecPermissionExists(): void
    {
        $this->assertContains('exec', TeamPermissions::KEYS);
    }
    
    /**
     * Test that UI metadata exists for all permission keys
     */
    public function testUIMetadataExistsForAllKeys(): void
    {
        foreach (TeamPermissions::KEYS as $key) {
            $this->assertArrayHasKey(
                $key, 
                TeamPermissions::UI, 
                "UI metadata should exist for permission key '{$key}'"
            );
        }
    }
    
    /**
     * Test that exec permission has write action (required for bash execution)
     */
    public function testExecPermissionHasWriteAction(): void
    {
        $this->assertArrayHasKey('exec', TeamPermissions::UI);
        $this->assertArrayHasKey('actions', TeamPermissions::UI['exec']);
        $this->assertContains('write', TeamPermissions::UI['exec']['actions']);
    }
    
    /**
     * Test that UI metadata has required structure
     */
    public function testUIMetadataStructure(): void
    {
        foreach (TeamPermissions::UI as $key => $meta) {
            $this->assertArrayHasKey(
                'label', 
                $meta, 
                "UI metadata for '{$key}' should have 'label'"
            );
            $this->assertArrayHasKey(
                'actions', 
                $meta, 
                "UI metadata for '{$key}' should have 'actions'"
            );
            $this->assertIsArray(
                $meta['actions'], 
                "UI metadata 'actions' for '{$key}' should be an array"
            );
            $this->assertNotEmpty(
                $meta['actions'], 
                "UI metadata 'actions' for '{$key}' should not be empty"
            );
            
            // All actions should be valid
            foreach ($meta['actions'] as $action) {
                $this->assertContains(
                    $action, 
                    ['read', 'write', 'execute'],
                    "Action '{$action}' for '{$key}' should be read, write, or execute"
                );
            }
        }
    }
    
    /**
     * Test built-in role levels are properly ordered
     */
    public function testBuiltinRoleLevelsOrdering(): void
    {
        $levels = TeamPermissions::BUILTIN_LEVELS;
        
        $this->assertGreaterThan($levels['admin'], $levels['owner']);
        $this->assertGreaterThan($levels['manager'], $levels['admin']);
        $this->assertGreaterThan($levels['member'], $levels['manager']);
        $this->assertGreaterThan($levels['read_only_member'], $levels['member']);
    }
    
    /**
     * Test reserved slugs match reserved names count
     */
    public function testReservedSlugsAndNamesMatch(): void
    {
        $this->assertCount(
            count(TeamPermissions::RESERVED_SLUGS),
            TeamPermissions::RESERVED_NAMES,
            "RESERVED_SLUGS and RESERVED_NAMES should have the same count"
        );
    }
}
