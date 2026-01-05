<?php

namespace Chap\Controllers;

use Chap\App;
use Chap\Models\Project;
use Chap\Models\Node;
use Chap\Models\Application;
use Chap\Models\ActivityLog;
use Chap\Models\Deployment;

/**
 * Dashboard Controller
 */
class DashboardController extends BaseController
{
    /**
     * Show dashboard
     */
    public function index(): void
    {
        $team = $this->currentTeam();

        // Get stats
        $projects = admin_view_all() ? Project::all() : Project::forTeam($team->id);
        $nodes = Node::all();
        $onlineNodes = array_filter($nodes, fn($n) => $n->isOnline());

        // Count applications and deployments
        $applicationCount = 0;
        $runningCount = 0;
        foreach ($projects as $project) {
            foreach ($project->environments() as $env) {
                $apps = $env->applications();
                $applicationCount += count($apps);
                $runningCount += count(array_filter($apps, fn($a) => $a->isRunning()));
            }
        }

        // Get deployment count
        $deploymentCount = $this->getDeploymentCount(admin_view_all() ? null : $team->id);

        // Get recent deployments
        $recentDeployments = $this->getRecentDeployments(admin_view_all() ? null : $team->id);

        // Get recent activity
        $activity = admin_view_all() ? $this->getRecentActivityAll(10) : ActivityLog::forTeam($team->id, 10);

        $this->view('dashboard/index', [
            'title' => 'Dashboard',
            'stats' => [
                'projects' => count($projects),
                'applications' => $applicationCount,
                'nodes' => count($nodes),
                'deployments' => $deploymentCount,
            ],
            'recentDeployments' => $recentDeployments,
            'activity' => $activity,
            'nodes' => $nodes,
        ]);
    }

    /**
     * Get deployment count for team
     */
    private function getDeploymentCount(?int $teamId): int
    {
        $db = App::db();
        if ($teamId === null) {
            $result = $db->fetch(
                "SELECT COUNT(*) as count FROM deployments",
                []
            );
        } else {
            $result = $db->fetch(
                "SELECT COUNT(*) as count FROM deployments d
                 JOIN applications a ON d.application_id = a.id
                 JOIN environments e ON a.environment_id = e.id
                 JOIN projects p ON e.project_id = p.id
                 WHERE p.team_id = ?",
                [$teamId]
            );
        }
        return $result['count'] ?? 0;
    }

    /**
     * Get recent deployments for team
     */
    private function getRecentDeployments(?int $teamId, int $limit = 5): array
    {
        $db = App::db();
        if ($teamId === null) {
            $results = $db->fetchAll(
                "SELECT d.*, a.name as application_name FROM deployments d
                 JOIN applications a ON d.application_id = a.id
                 ORDER BY d.created_at DESC
                 LIMIT ?",
                [$limit]
            );
        } else {
            $results = $db->fetchAll(
                "SELECT d.*, a.name as application_name FROM deployments d
                 JOIN applications a ON d.application_id = a.id
                 JOIN environments e ON a.environment_id = e.id
                 JOIN projects p ON e.project_id = p.id
                 WHERE p.team_id = ?
                 ORDER BY d.created_at DESC
                 LIMIT ?",
                [$teamId, $limit]
            );
        }
        return $results;
    }

    private function getRecentActivityAll(int $limit = 10): array
    {
        $db = App::db();
        $rows = $db->fetchAll(
            "SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT ?",
            [$limit]
        );
        return array_map(fn($data) => ActivityLog::fromArray($data), $rows);
    }

    /**
     * Switch team
     */
    public function switchTeam(string $uuid): void
    {
        $team = \Chap\Models\Team::findByUuid($uuid);

        if (!$team || !$this->user->belongsToTeam($team)) {
            flash('error', 'Team not found');
            $this->redirect('/dashboard');
        }

        $this->user->switchTeam($team);
        flash('success', 'Switched to ' . $team->name);
        $this->redirect('/dashboard');
    }
}
