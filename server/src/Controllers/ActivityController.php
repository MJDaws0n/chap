<?php

namespace Chap\Controllers;

use Chap\Models\ActivityLog;

/**
 * Activity Controller
 */
class ActivityController extends BaseController
{
    /**
     * Show activity log
     */
    public function index(): void
    {
        $team = $this->currentTeam();
        
        // Get page from query string
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 50;
        
        $activity = ActivityLog::forTeam($team->id, $perPage * $page);
        
        // Slice for current page
        $offset = ($page - 1) * $perPage;
        $pageActivity = array_slice($activity, $offset, $perPage);

        $this->view('activity/index', [
            'title' => 'Activity Log',
            'activity' => $pageActivity,
            'page' => $page,
            'hasMore' => count($activity) > $offset + $perPage,
        ]);
    }
}
