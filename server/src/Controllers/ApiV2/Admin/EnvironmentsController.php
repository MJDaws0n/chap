<?php

namespace Chap\Controllers\ApiV2\Admin;

use Chap\App;

class EnvironmentsController extends \Chap\Controllers\ApiV2\BaseApiV2Controller
{
    public function index(): void
    {
        $db = App::db();
        $rows = $db->fetchAll(
            "SELECT e.uuid, e.name, p.uuid AS project_uuid, t.uuid AS team_uuid\n" .
            "FROM environments e\n" .
            "JOIN projects p ON p.id = e.project_id\n" .
            "JOIN teams t ON t.id = p.team_id\n" .
            "ORDER BY e.id ASC"
        );
        $data = array_map(function($r) {
            return [
                'id' => (string)($r['uuid'] ?? ''),
                'uuid' => (string)($r['uuid'] ?? ''),
                'name' => (string)($r['name'] ?? ''),
                'project_id' => (string)($r['project_uuid'] ?? ''),
                'team_id' => (string)($r['team_uuid'] ?? ''),
            ];
        }, $rows);
        $this->ok(['data' => $data]);
    }
}
