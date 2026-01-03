<?php

namespace Chap\Controllers\Admin;

use Chap\Controllers\BaseController;

class AdminController extends BaseController
{
    public function index(): void
    {
        $this->redirect('/admin/users');
    }
}
