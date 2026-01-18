<?php

namespace Chap\Services\ChapScript;

use Chap\Models\Application;
use Chap\Models\ChapScriptRun;

/**
 * Pre-deploy hook for template-based applications.
 *
 * If a template has a ChapScribe script, we run it before queuing a deployment.
 * If the script requires user input, we create a ChapScriptRun in `waiting` state
 * and return a prompt payload to the caller.
 */
final class ChapScriptPreDeploy
{
    /**
     * @param array<string,mixed> $context
     * @return array{status:'none'|'waiting'|'completed'|'stopped', prompt?:array<string,mixed>, run?:ChapScriptRun, message?:string, env?:array<string,string>}
     */
    public static function run(Application $application, array $context = []): array
    {
        $slug = trim((string)($application->template_slug ?? ''));
        if ($slug === '') {
            return ['status' => 'none'];
        }

        $loaded = ChapScriptTemplateLoader::loadForTemplateSlug($slug);
        if (!$loaded) {
            return ['status' => 'none'];
        }

        $envVars = $application->getEnvironmentVariables();

        $res = ChapScriptRunner::run($loaded['script'], [], $envVars);
        if ($res['status'] === 'waiting') {
            $run = ChapScriptRun::create([
                'application_id' => (int)$application->id,
                'template_slug' => $slug,
                'status' => 'waiting',
                'state_json' => json_encode($res['state']),
                'prompt_json' => json_encode($res['prompt'] ?? null),
                'context_json' => json_encode($context),
                'user_id' => isset($context['user_id']) ? (int)$context['user_id'] : null,
            ]);

            return ['status' => 'waiting', 'prompt' => $res['prompt'] ?? null, 'run' => $run];
        }

        if ($res['status'] === 'stopped') {
            return ['status' => 'stopped', 'message' => (string)($res['message'] ?? 'Stopped')];
        }

        // completed
        if (!empty($res['env']) && $res['env'] !== $envVars) {
            $application->update(['environment_variables' => json_encode($res['env'])]);
        }

        return ['status' => 'completed', 'env' => $res['env'] ?? $envVars];
    }
}
