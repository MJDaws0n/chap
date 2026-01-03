<?php

namespace Chap\Controllers\Admin;

use Chap\Controllers\BaseController;
use Chap\App;
use Chap\Models\User;

class ActivityController extends BaseController
{
    public function index(): void
    {
        $db = App::db();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $userId = (int)($_GET['user_id'] ?? 0);
        $userFilter = $userId > 0 ? $userId : null;

        $where = '';
        $params = [];
        if ($userFilter) {
            $where = 'WHERE al.user_id = ?';
            $params[] = $userFilter;
        }

        $totalRow = $db->fetch(
            "SELECT COUNT(*) as count FROM activity_logs al {$where}",
            $params
        );
        $total = (int)($totalRow['count'] ?? 0);

        $rows = $db->fetchAll(
            "SELECT al.*, u.email as user_email, u.username as user_username, u.name as user_name,
                    t.name as team_name
             FROM activity_logs al
             LEFT JOIN users u ON u.id = al.user_id
             LEFT JOIN teams t ON t.id = al.team_id
             {$where}
             ORDER BY al.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        $users = User::paginate(1, 500)['data'];

        $this->view('admin/activity/index', [
            'title' => 'Activity Logs',
            'currentPage' => 'admin-activity',
            'rows' => $rows,
            'users' => $users,
            'selectedUserId' => $userFilter,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'hasMore' => $offset + $perPage < $total,
        ]);
    }
}
