<?php

namespace Chap\Controllers;

use Chap\Auth\AuthManager;
use Chap\Models\Template;

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

        $templates = Template::where('is_active', true);
        
        $this->view('templates/index', [
            'title' => 'Templates',
            'currentPage' => 'templates',
            'templates' => $templates
        ]);
    }

    /**
     * Show template details
     */
    public function show(string $slug): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('templates', 'read', (int) $team->id);

        $templates = Template::where('slug', $slug);
        $template = $templates[0] ?? null;
        
        if (!$template) {
            flash('error', 'Template not found');
            redirect('/templates');
            return;
        }

        $this->view('templates/show', [
            'title' => $template->name,
            'currentPage' => 'templates',
            'template' => $template->toArray()
        ]);
    }
}
