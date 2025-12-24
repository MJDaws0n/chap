<?php

namespace Chap\Controllers\Api;

/**
 * API Service Controller
 * 
 * Stub implementation - TODO: implement fully
 */
class ServiceController extends BaseApiController
{
    public function index(): void
    {
        $this->success(['services' => []]);
    }

    public function store(): void
    {
        $this->error('Not implemented', 501);
    }

    public function show(string $id): void
    {
        $this->notFound('Service not found');
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
