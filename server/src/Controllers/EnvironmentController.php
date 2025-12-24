<?php

namespace Chap\Controllers;

use Chap\Models\Environment;
use Chap\Models\Project;

/**
 * Environment Controller
 */
class EnvironmentController extends BaseController
{
    /**
     * Create environment for project
     */
    public function store(string $projectUuid): void
    {
        $team = $this->currentTeam();
        $project = Project::findByUuid($projectUuid);

        if (!$project || $project->team_id !== $team->id) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Project not found'], 404);
            } else {
                flash('error', 'Project not found');
                $this->redirect('/projects');
            }
        }

        $data = $this->all();

        if (empty($data['name'])) {
            if ($this->isApiRequest()) {
                $this->json(['errors' => ['name' => 'Name is required']], 422);
            } else {
                flash('error', 'Environment name is required');
                $this->redirect('/projects/' . $projectUuid);
            }
        }

        $environment = Environment::create([
            'project_id' => $project->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        if ($this->isApiRequest()) {
            $this->json(['environment' => $environment->toArray()], 201);
        } else {
            flash('success', 'Environment created');
            $this->redirect('/environments/' . $environment->uuid);
        }
    }

    /**
     * Show environment
     */
    public function show(string $uuid): void
    {
        $team = $this->currentTeam();
        $environment = Environment::findByUuid($uuid);

        if (!$environment) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        $project = $environment->project();
        if (!$project || $project->team_id !== $team->id) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        $applications = $environment->applications();

        if ($this->isApiRequest()) {
            $this->json([
                'environment' => $environment->toArray(),
                'applications' => array_map(fn($a) => $a->toArray(), $applications),
            ]);
        } else {
            $this->view('environments/show', [
                'title' => $environment->name,
                'environment' => $environment,
                'project' => $project,
                'applications' => $applications,
            ]);
        }
    }

    /**
     * Show edit environment form
     */
    public function edit(string $uuid): void
    {
        $team = $this->currentTeam();
        $environment = Environment::findByUuid($uuid);

        if (!$environment) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        $project = $environment->project();
        if (!$project || $project->team_id !== $team->id) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        if ($this->isApiRequest()) {
            $this->json([
                'environment' => $environment->toArray(),
                'project' => $project->toArray(),
            ]);
        } else {
            $this->view('environments/edit', [
                'title' => 'Edit Environment',
                'environment' => $environment,
                'project' => $project,
            ]);
        }
    }

    /**
     * Update environment
     */
    public function update(string $uuid): void
    {
        $team = $this->currentTeam();
        $environment = Environment::findByUuid($uuid);

        if (!$environment) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        $project = $environment->project();
        if (!$project || $project->team_id !== $team->id) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/environments/' . $uuid . '/edit');
        }

        $data = $this->all();

        $environment->update([
            'name' => $data['name'] ?? $environment->name,
            'description' => $data['description'] ?? $environment->description,
        ]);

        if ($this->isApiRequest()) {
            $this->json(['environment' => $environment->toArray()]);
        } else {
            flash('success', 'Environment updated');
            $this->redirect('/environments/' . $uuid);
        }
    }

    /**
     * Delete environment
     */
    public function destroy(string $uuid): void
    {
        $team = $this->currentTeam();
        $environment = Environment::findByUuid($uuid);

        if (!$environment) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        $project = $environment->project();
        if (!$project || $project->team_id !== $team->id) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/environments/' . $uuid);
        }

        $projectUuid = $project->uuid;
        $environment->delete();

        if ($this->isApiRequest()) {
            $this->json(['message' => 'Environment deleted']);
        } else {
            flash('success', 'Environment deleted');
            $this->redirect('/projects/' . $projectUuid);
        }
    }

    /**
     * Show create environment form
     */
    public function create(string $projectUuid): void
    {
        $team = $this->currentTeam();
        $project = Project::findByUuid($projectUuid);

        if (!$project || $project->team_id !== $team->id) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Project not found'], 404);
            } else {
                flash('error', 'Project not found');
                $this->redirect('/projects');
            }
            return;
        }

        $this->view('environments/create', [
            'title' => 'Create Environment',
            'project' => $project,
        ]);
    }
}
