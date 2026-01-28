<?php

namespace Chap\Controllers\ApiV2\Admin;

use Chap\Models\Node;

class NodesController extends \Chap\Controllers\ApiV2\BaseApiV2Controller
{
    public function index(): void
    {
        $nodes = Node::all();
        $this->ok(['data' => array_map(fn($n) => $n->toArray(), $nodes)]);
    }
}
