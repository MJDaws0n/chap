<?php

namespace Chap\Controllers\ApiV2\Admin;

use Chap\Controllers\ApiV2\BaseApiV2Controller;

class MeController extends BaseApiV2Controller
{
    public function show(): void
    {
        $u = $this->user;
        $this->ok([
            'data' => [
                'id' => $u?->uuid,
                'uuid' => $u?->uuid,
                'email' => $u?->email,
                'username' => $u?->username,
                'name' => $u?->name,
                'is_admin' => (bool)($u?->is_admin ?? false),
            ],
        ]);
    }
}
