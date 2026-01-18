<?php

namespace Chap\Controllers;

use Chap\Models\Application;
use Chap\Models\ChapScriptRun;
use Chap\Services\DeploymentService;
use Chap\Services\ChapScript\ChapScriptValidationException;
use Chap\Services\ChapScript\ChapScriptRunner;
use Chap\Services\ChapScript\ChapScriptTemplateLoader;

/**
 * Handles interactive ChapScript runs for the web UI (session auth).
 */
class ChapScriptRunController extends BaseController
{
    public function respond(string $uuid): void
    {
        $this->currentTeam();

        if (!verify_csrf($this->input('_csrf_token', ''))) {
            $this->json(['error' => 'Invalid request'], 400);
            return;
        }

        $run = ChapScriptRun::findByUuid($uuid);
        if (!$run) {
            $this->json(['error' => 'Script run not found'], 404);
            return;
        }

        $application = $run->application_id ? Application::find((int)$run->application_id) : null;
        if (!$application) {
            $this->json(['error' => 'Application not found'], 404);
            return;
        }

        $environment = $application->environment();
        $project = $environment ? $environment->project() : null;
        if (!$project || !$this->canAccessTeamId((int)$project->team_id)) {
            $this->json(['error' => 'Access denied'], 403);
            return;
        }

        $this->requireTeamPermission('deployments', 'execute', (int)$project->team_id);

        if ($application->status === 'deploying') {
            $this->json(['error' => 'Application is already deploying'], 400);
            return;
        }

        if ($run->status !== 'waiting') {
            $this->json(['error' => 'Script run is not awaiting input'], 409);
            return;
        }

        $slug = trim((string)($run->template_slug ?? $application->template_slug ?? ''));
        if ($slug === '') {
            $this->json(['error' => 'No template script is associated with this run'], 422);
            return;
        }

        try {
            $loaded = ChapScriptTemplateLoader::loadForTemplateSlug($slug);
            if (!$loaded) {
                $this->json(['error' => 'Template script not found'], 404);
                return;
            }

            $response = $this->input('response', null);
            if (!is_array($response)) {
                $response = [];
            }

            $envVars = $application->getEnvironmentVariables();
            $res = ChapScriptRunner::resume($loaded['script'], $run->state(), $envVars, $response);

            // Persist env updates (if any) immediately.
            if ($res['env'] !== $envVars) {
                $application->update(['environment_variables' => json_encode($res['env'])]);
            }

            $runUpdate = [
                'state_json' => json_encode($res['state']),
                'prompt_json' => null,
            ];

            if ($res['status'] === 'waiting') {
                $runUpdate['status'] = 'waiting';
                $runUpdate['prompt_json'] = json_encode($res['prompt'] ?? null);
                $run->update($runUpdate);

                $this->json([
                    'error' => 'action_required',
                    'script_run' => ['uuid' => $run->uuid, 'status' => $runUpdate['status']],
                    'prompt' => $res['prompt'] ?? null,
                ], 409);
                return;
            }

            if ($res['status'] === 'stopped') {
                $runUpdate['status'] = 'stopped';
                $run->update($runUpdate);
                $this->json(['error' => $res['message'] ?? 'Stopped'], 422);
                return;
            }

            $runUpdate['status'] = 'completed';
            $run->update($runUpdate);

            // Start deployment once script completes.
            $ctx = $run->context();
            $deployment = DeploymentService::create($application, $ctx['commit_sha'] ?? null, [
                'triggered_by' => $ctx['triggered_by'] ?? ($this->user ? 'user' : 'manual'),
                'triggered_by_name' => $ctx['triggered_by_name'] ?? $this->user?->displayName(),
            ]);

            $this->json(['deployment' => $deployment->toArray()], 201);
        } catch (ChapScriptValidationException $e) {
            $run->update(['status' => 'error']);
            $this->json(['error' => $e->getMessage(), 'details' => $e->errors()], 422);
        } catch (\Throwable $e) {
            $run->update(['status' => 'error']);
            $this->json(['error' => $e->getMessage()], 422);
        }
    }
}
