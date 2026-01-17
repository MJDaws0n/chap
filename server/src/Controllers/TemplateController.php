<?php

namespace Chap\Controllers;

use Chap\Auth\AuthManager;
use Chap\Models\Application;
use Chap\Models\Environment;
use Chap\Models\Node;
use Chap\Models\Project;
use Chap\Models\Template;
use Chap\Services\NodeAccess;
use Chap\Services\TemplateRegistry;

/**
 * Template Controller
 */
class TemplateController extends BaseController
{
    /**
     * List all templates
     */
    public function index(): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('templates', 'read', (int) $team->id);

        TemplateRegistry::syncToDatabase();

        $templates = Template::where('is_active', true);

        usort($templates, function($a, $b) {
            $ao = !empty($a->is_official) ? 0 : 1;
            $bo = !empty($b->is_official) ? 0 : 1;
            if ($ao !== $bo) return $ao <=> $bo;

            $ac = strtolower((string)($a->category ?? ''));
            $bc = strtolower((string)($b->category ?? ''));
            if ($ac !== $bc) return $ac <=> $bc;

            return strcasecmp((string)$a->name, (string)$b->name);
        });

        $byCategory = [];
        foreach ($templates as $t) {
            $cat = trim((string)($t->category ?? ''));
            if ($cat === '') {
                $cat = 'Other';
            }
            if (!isset($byCategory[$cat])) {
                $byCategory[$cat] = [];
            }
            $byCategory[$cat][] = $t;
        }
        
        $this->view('templates/index', [
            'title' => 'Application Templates',
            'currentPage' => 'templates',
            'templates' => $templates,
            'templatesByCategory' => $byCategory,
        ]);
    }

    /**
     * Show template details
     */
    public function show(string $slug): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('templates', 'read', (int) $team->id);

        TemplateRegistry::syncToDatabase();

        $template = Template::findBySlug($slug);
        
        if (!$template) {
            flash('error', 'Template not found');
            redirect('/templates');
            return;
        }

        $projects = Project::forTeam((int)$team->id);
        usort($projects, fn($a, $b) => strcasecmp((string)$a->name, (string)$b->name));

        $environments = [];
        foreach ($projects as $p) {
            foreach ($p->environments() as $env) {
                $environments[] = $env;
            }
        }

        $nodes = Node::onlineForTeam((int)$team->id);

        // Build initial env text (KEY=VALUE lines) using the same shape as the application create UI.
        $envVars = $template->getDefaultEnvironmentVariables();
        $required = $template->getRequiredEnvironmentVariables();
        foreach ($required as $k) {
            $key = trim((string)$k);
            if ($key === '') continue;
            if (!array_key_exists($key, $envVars)) {
                $envVars[$key] = '';
            }
        }
        $lines = [];
        foreach ($envVars as $k => $v) {
            $key = trim((string)$k);
            if ($key === '') continue;
            $lines[] = $key . '=' . (string)($v ?? '');
        }
        $initialEnvB64 = base64_encode(implode("\n", $lines));

        $this->view('templates/show', [
            'title' => $template->name,
            'currentPage' => 'templates',
            'template' => $template,
            'projects' => $projects,
            'environments' => $environments,
            'nodes' => $nodes,
            'initialEnvB64' => $initialEnvB64,
        ]);
    }

    /**
     * Deploy a template into an environment (wizard endpoint)
     */
    public function deploy(string $slug): void
    {
        $team = $this->currentTeam();

        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/templates/' . urlencode($slug));
            return;
        }

        TemplateRegistry::syncToDatabase();
        $template = Template::findBySlug($slug);
        if (!$template) {
            flash('error', 'Template not found');
            $this->redirect('/templates');
            return;
        }

        $envUuid = trim((string)$this->input('environment_uuid', ''));
        $environment = $envUuid !== '' ? Environment::findByUuid($envUuid) : null;
        if (!$environment) {
            flash('error', 'Environment not found');
            $this->redirect('/templates/' . urlencode($slug));
            return;
        }

        $project = $environment->project();
        if (!$project || !$this->canAccessTeamId((int)$project->team_id)) {
            flash('error', 'Environment not found');
            $this->redirect('/templates/' . urlencode($slug));
            return;
        }

        // Optional sanity check: if the UI posted a project_uuid, ensure it matches the selected environment.
        $projectUuid = trim((string)$this->input('project_uuid', ''));
        if ($projectUuid !== '' && isset($project->uuid) && (string)$project->uuid !== $projectUuid) {
            flash('error', 'Environment does not belong to selected project');
            $this->redirect('/templates/' . urlencode($slug));
            return;
        }

        $this->requireTeamPermission('applications', 'write', (int)$project->team_id);

        $nodeUuid = trim((string)$this->input('node_uuid', ''));
        $node = $nodeUuid !== '' ? Node::findByUuid($nodeUuid) : null;
        if (!$node) {
            flash('error', 'Node selection is required');
            $this->redirect('/templates/' . urlencode($slug));
            return;
        }

        $allowedNodeIds = $this->user ? NodeAccess::allowedNodeIds($this->user, $team, $project, $environment) : [];
        if (!in_array((int)$node->id, $allowedNodeIds, true)) {
            flash('error', 'You do not have access to this node');
            $this->redirect('/templates/' . urlencode($slug));
            return;
        }

        $name = trim((string)$this->input('name', $template->name));
        if ($name === '') {
            $name = $template->name;
        }

        // Merge template env defaults + required keys + user overrides
        $envVars = $template->getDefaultEnvironmentVariables();
        $required = $template->getRequiredEnvironmentVariables();
        foreach ($required as $k) {
            $key = trim((string)$k);
            if ($key === '') continue;
            if (!array_key_exists($key, $envVars)) {
                $envVars[$key] = '';
            }
        }

        $postedEnv = (string)$this->input('environment_variables', '');
        if (trim($postedEnv) !== '') {
            $lines = explode("\n", $postedEnv);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                if ($k === '') continue;
                $envVars[$k] = trim($v);
            }
        }

        $application = Application::create([
            'environment_id' => $environment->id,
            'node_id' => $node->id,
            'name' => $name,
            'description' => $template->description,
            'build_pack' => 'docker-compose',
            'git_repository' => null,
            'git_branch' => 'main',
            'environment_variables' => !empty($envVars) ? json_encode($envVars) : null,
            'memory_limit' => '512m',
            'cpu_limit' => '1',
            'cpu_millicores_limit' => -1,
            'ram_mb_limit' => -1,
            'storage_mb_limit' => -1,
            'port_limit' => -1,
            'bandwidth_mbps_limit' => -1,
            'pids_limit' => -1,
            'health_check_enabled' => 1,
            'health_check_path' => '/',
            'template_slug' => $template->slug,
            'template_version' => $template->version,
            'template_docker_compose' => $template->docker_compose,
            'template_extra_files' => !empty($template->extra_files) ? (is_string($template->extra_files) ? $template->extra_files : json_encode($template->extra_files)) : null,
        ]);

        flash('success', 'Application created from template');
        $this->redirect('/applications/' . $application->uuid);
    }
}
