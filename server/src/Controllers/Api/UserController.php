<?php

namespace Chap\Controllers\Api;

/**
 * API User Controller
 */
class UserController extends BaseApiController
{
    /**
     * Get current user
     */
    public function show(): void
    {
        if (!$this->user) {
            $this->unauthorized();
            return;
        }

        $this->success([
            'user' => $this->user->toArray(),
            'teams' => array_map(fn($t) => $t->toArray(), $this->user->teams()),
        ]);
    }
}
