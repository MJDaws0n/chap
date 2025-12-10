<?php

namespace Chap\Controllers\Api;

/**
 * API Database Controller
 * 
 * Stub implementation - TODO: implement fully
 */
class DatabaseController extends BaseApiController
{
    public function index(): void
    {
        $this->success(['databases' => []]);
    }

    public function store(): void
    {
        $this->error('Not implemented', 501);
    }

    public function show(string $id): void
    {
        $this->notFound('Database not found');
    }

    public function update(string $id): void
    {
        $this->error('Not implemented', 501);
    }

    public function destroy(string $id): void
    {
        $this->error('Not implemented', 501);
    }
}
