<?php

namespace Chap\Controllers\Admin;

use Chap\Controllers\BaseController;
use Chap\Models\Template;
use Chap\Services\TemplateRegistry;
use Chap\Services\TemplateZipImporter;

class TemplateController extends BaseController
{
    public function index(): void
    {
        TemplateRegistry::syncToDatabase();

        // Default to only active templates (deactivated templates are typically stale/removed from disk).
        $templates = Template::where('is_active', true);
        usort($templates, function($a, $b) {
            $ao = !empty($a->is_official) ? 0 : 1;
            $bo = !empty($b->is_official) ? 0 : 1;
            if ($ao !== $bo) return $ao <=> $bo;
            return strcasecmp((string)$a->name, (string)$b->name);
        });

        $this->view('admin/templates/index', [
            'title' => 'Templates',
            'currentPage' => 'admin-templates',
            'templates' => $templates,
        ]);
    }

    public function upload(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/admin/templates');
            return;
        }

        if (empty($_FILES['template_zip'])) {
            flash('error', 'No file uploaded');
            $this->redirect('/admin/templates');
            return;
        }

        try {
            $pkg = TemplateZipImporter::importUploadedZip($_FILES['template_zip']);
            TemplateRegistry::syncToDatabase();
            flash('success', 'Template uploaded: ' . $pkg->name());
        } catch (\Throwable $e) {
            flash('error', 'Upload failed: ' . $e->getMessage());
        }

        $this->redirect('/admin/templates');
    }
}
