<?php

namespace Chap\Controllers\ApiV2\Admin;

use Chap\Models\Template;
use Chap\Services\TemplateRegistry;

class TemplatesController extends \Chap\Controllers\ApiV2\BaseApiV2Controller
{
    public function index(): void
    {
        TemplateRegistry::syncToDatabase();
        $templates = Template::where('is_active', true);
        $data = array_map(fn($t) => [
            'slug' => (string)$t->slug,
            'name' => (string)$t->name,
            'description' => $t->description,
            'category' => $t->category,
            'version' => $t->version,
            'is_official' => (bool)$t->is_official,
            'is_active' => (bool)$t->is_active,
        ], $templates);
        $this->ok(['data' => $data]);
    }

    public function sync(): void
    {
        $result = TemplateRegistry::syncToDatabase();
        $this->ok(['data' => $result]);
    }
}
