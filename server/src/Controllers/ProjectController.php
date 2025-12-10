<?php

namespace Chap\Controllers;

use Chap\Models\Project;
use Chap\Models\ActivityLog;

/**
 * Project Controller
 */
class ProjectController extends BaseController
{
    /**
     * List projects
     */
    public function index(): void
    {
        $team = $this->currentTeam();
        $projects = Project::forTeam($team->id);

        if ($this->isApiRequest()) {
            $this->json([
                'projects' => array_map(fn($p) => $p->toArray(), $projects)
            ]);
        } else {
            $this->view('projects/index', [
                'title' => 'Projects',
                'projects' => $projects,
            ]);
        }
    }

    /**
     * Show create form
     */
    public function create(): void
    {
        $this->view('projects/create', [
            'title' => 'New Project'
        ]);
    }

    /**
     * Store new project
     */
    public function store(): void
    {
        $team = $this->currentTeam();

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/projects/create');
        }

        $data = $this->all();

        // Validate
        if (empty($data['name'])) {
            if ($this->isApiRequest()) {
                $this->json(['errors' => ['name' => 'Name is required']], 422);
            } else {
                flash('error', 'Project name is required');
                $this->redirect('/projects/create');
            }
        }

        $project = Project::create([
            'team_id' => $team->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        // Create default environment
        $project->createDefaultEnvironment();

        ActivityLog::log('project.created', 'Project', $project->id, ['name' => $project->name]);

        if ($this->isApiRequest()) {
            $this->json(['project' => $project->toArray()], 201);
        } else {
            flash('success', 'Project created');
            $this->redirect('/projects/' . $project->uuid);
        }
    }

    /**
     * Show project
     */
    public function show(string $uuid): void
    {
        $team = $this->currentTeam();
        $project = Project::findByUuid($uuid);

        if (!$project || $project->team_id !== $team->id) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Project not found'], 404);
            } else {
                flash('error', 'Project not found');
                $this->redirect('/projects');
            }
        }

        $environments = $project->environments();

        if ($this->isApiRequest()) {
            $this->json([
                'project' => $project->toArray(),
                'environments' => array_map(fn($e) => $e->toArray(), $environments),
            ]);
        } else {
            $this->view('projects/show', [
                'title' => $project->name,
                'project' => $project,
                'environments' => $environments,
            ]);
        }
    }

    /**
     * Update project
     */
    public function update(string $uuid): void
    {
        $team = $this->currentTeam();
        $project = Project::findByUuid($uuid);

        if (!$project || $project->team_id !== $team->id) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Project not found'], 404);
            } else {
                flash('error', 'Project not found');
                $this->redirect('/projects');
            }
        }

        $data = $this->all();

        $project->update([
            'name' => $data['name'] ?? $project->name,
            'description' => $data['description'] ?? $project->description,
        ]);

        if ($this->isApiRequest()) {
            $this->json(['project' => $project->toArray()]);
        } else {
            flash('success', 'Project updated');
            $this->redirect('/projects/' . $uuid);
        }
    }

    /**
     * Delete project
     */
    public function destroy(string $uuid): void
    {
        $team = $this->currentTeam();
        $project = Project::findByUuid($uuid);

        if (!$project || $project->team_id !== $team->id) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Project not found'], 404);
            } else {
                flash('error', 'Project not found');
                $this->redirect('/projects');
            }
        }

        $projectName = $project->name;
        $project->delete();

        ActivityLog::log('project.deleted', 'Project', null, ['name' => $projectName]);

        if ($this->isApiRequest()) {
            $this->json(['message' => 'Project deleted']);
        } else {
            flash('success', 'Project deleted');
            $this->redirect('/projects');
        }
    }
}
