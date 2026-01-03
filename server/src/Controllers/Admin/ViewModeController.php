<?php

namespace Chap\Controllers\Admin;

use Chap\Controllers\BaseController;

class ViewModeController extends BaseController
{
    public function update(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/dashboard');
        }

        $mode = $this->input('mode', 'personal');
        if (!in_array($mode, ['personal', 'all'], true)) {
            $mode = 'personal';
        }

        $_SESSION['admin_view_mode'] = $mode;

        flash('success', $mode === 'all' ? 'Switched to view all mode' : 'Switched to personal mode');
        $this->redirect($_SERVER['HTTP_REFERER'] ?? '/dashboard');
    }
}
