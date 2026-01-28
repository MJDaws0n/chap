<?php

namespace Chap\Controllers\ApiV2\Admin;

use Chap\App;

class ProjectsController extends \Chap\Controllers\ApiV2\BaseApiV2Controller
{
    public function index(): void
    {
        $db = App::db();
        $rows = $db->fetchAll("SELECT * FROM projects ORDER BY id ASC");
        $data = array_map(fn($r) => \Chap\Models\Project::fromArray($r)->toArray(), $rows);
        $this->ok(['data' => $data]);
    }
}
