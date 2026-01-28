<?php

namespace Chap\Controllers\ApiV2\Admin;

use Chap\App;

class DeploymentsController extends \Chap\Controllers\ApiV2\BaseApiV2Controller
{
    public function index(): void
    {
        $db = App::db();
        $rows = $db->fetchAll(
            "SELECT d.uuid, d.status, d.git_commit_sha, d.git_commit_message, d.created_at, a.uuid AS application_uuid\n" .
            "FROM deployments d\n" .
            "JOIN applications a ON a.id = d.application_id\n" .
            "ORDER BY d.id DESC\n" .
            "LIMIT 200"
        );
        $data = array_map(function($r) {
            $uuid = (string)($r['uuid'] ?? '');
            return [
                'id' => $uuid,
                'uuid' => $uuid,
                'status' => (string)($r['status'] ?? ''),
                'application_id' => (string)($r['application_uuid'] ?? ''),
                'commit_sha' => $r['git_commit_sha'] ? (string)$r['git_commit_sha'] : null,
                'commit_message' => $r['git_commit_message'] ? (string)$r['git_commit_message'] : null,
                'created_at' => $r['created_at'] ? date('c', strtotime((string)$r['created_at'])) : null,
            ];
        }, $rows);
        $this->ok(['data' => $data]);
    }
}
