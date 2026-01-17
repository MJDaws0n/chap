<?php

namespace Tests\Integration;

use Tests\TestCase;
use Chap\App;
use Chap\Models\Node;
use Chap\Models\Project;
use Chap\Models\Environment;
use Chap\Models\Application;
use Chap\Services\ApplicationCleanupService;

class ApplicationCleanupServiceTest extends TestCase
{
    public function testDeleteAllForEnvironmentQueuesNodeDeleteTask(): void
    {
        // Boot app so models/services can use App::db().
        $app = new App();
        $app->boot();

        $db = $this->getDb();

        $teamRow = $db->fetch("SELECT * FROM teams LIMIT 1");
        $this->assertNotEmpty($teamRow, 'Expected a seeded team');
        $teamId = (int)$teamRow['id'];

        $node = Node::create([
            'team_id' => $teamId,
            'name' => 'cleanup-test-node-' . time(),
            'token' => bin2hex(random_bytes(16)),
            'status' => 'online',
        ]);

        $project = Project::create([
            'team_id' => $teamId,
            'name' => 'cleanup-test-project-' . time(),
            'description' => 'test',
        ]);

        $env = Environment::create([
            'project_id' => $project->id,
            'name' => 'cleanup-test-env',
            'description' => 'test',
        ]);

        $application = Application::create([
            'environment_id' => $env->id,
            'node_id' => $node->id,
            'name' => 'cleanup-test-app',
            'description' => 'test',
            'git_branch' => 'main',
            'build_pack' => 'dockerfile',
            'status' => 'running',
        ]);

        $deletedCount = ApplicationCleanupService::deleteAllForEnvironment($env);
        $this->assertEquals(1, $deletedCount);

        $stillThere = Application::findByUuid($application->uuid);
        $this->assertNull($stillThere, 'Application DB row should be deleted');

        $task = $db->fetch(
            "SELECT * FROM deployment_tasks WHERE node_id = ? AND task_type = 'application:delete' AND task_data LIKE ? ORDER BY id DESC LIMIT 1",
            [$node->id, '%"application_uuid":"' . $application->uuid . '"%']
        );
        $this->assertNotEmpty($task, 'Expected an application:delete task to be queued');

        // Cleanup
        $db->query("DELETE FROM deployment_tasks WHERE node_id = ? AND task_type = 'application:delete' AND task_data LIKE ?", [$node->id, '%"application_uuid":"' . $application->uuid . '"%']);
        $db->query("DELETE FROM environments WHERE id = ?", [$env->id]);
        $db->query("DELETE FROM projects WHERE id = ?", [$project->id]);
        $db->query("DELETE FROM nodes WHERE id = ?", [$node->id]);
    }
}
