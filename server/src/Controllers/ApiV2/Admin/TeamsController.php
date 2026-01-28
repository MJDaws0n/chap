<?php

namespace Chap\Controllers\ApiV2\Admin;

use Chap\Models\Team;

class TeamsController extends \Chap\Controllers\ApiV2\BaseApiV2Controller
{
    public function index(): void
    {
        $teams = Team::all();
        $this->ok(['data' => array_map(fn($t) => $t->toArray(), $teams)]);
    }
}
