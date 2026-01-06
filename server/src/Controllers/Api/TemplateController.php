<?php

namespace Chap\Controllers\Api;

use Chap\Models\Template;

/**
 * API Template Controller
 */
class TemplateController extends BaseApiController
{
    /**
     * List templates
     */
    public function index(): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('templates', 'read', (int) $team->id);

        $templates = Template::all();

        $this->success([
            'templates' => array_map(fn($t) => $t->toArray(), $templates),
        ]);
    }

    /**
     * Show template
     */
    public function show(string $slug): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('templates', 'read', (int) $team->id);

        $template = Template::findBySlug($slug);

        if (!$template) {
            $this->notFound('Template not found');
            return;
        }

        $this->success(['template' => $template->toArray()]);
    }
}
