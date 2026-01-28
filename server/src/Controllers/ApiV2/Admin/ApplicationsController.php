<?php

namespace Chap\Controllers\ApiV2\Admin;

use Chap\App;

class ApplicationsController extends \Chap\Controllers\ApiV2\BaseApiV2Controller
{
    public function index(): void
    {
        $db = App::db();
        $rows = $db->fetchAll(
            "SELECT a.uuid, a.name, a.status, e.uuid AS environment_uuid, p.uuid AS project_uuid, t.uuid AS team_uuid, n.uuid AS node_uuid\n" .
            "FROM applications a\n" .
            "JOIN environments e ON e.id = a.environment_id\n" .
            "JOIN projects p ON p.id = e.project_id\n" .
            "JOIN teams t ON t.id = p.team_id\n" .
            "LEFT JOIN nodes n ON n.id = a.node_id\n" .
            "ORDER BY a.id ASC"
        );
        $data = array_map(function($r) {
            $uuid = (string)($r['uuid'] ?? '');
            return [
                'id' => $uuid,
                'uuid' => $uuid,
                'name' => (string)($r['name'] ?? ''),
                'status' => (string)($r['status'] ?? ''),
                'team_id' => (string)($r['team_uuid'] ?? ''),
                'project_id' => (string)($r['project_uuid'] ?? ''),
                'environment_id' => (string)($r['environment_uuid'] ?? ''),
                'node_id' => $r['node_uuid'] ? (string)$r['node_uuid'] : null,
            ];
        }, $rows);
        $this->ok(['data' => $data]);
    }
}
