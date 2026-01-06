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
        $team = $this->currentTeam();
        $this->requireTeamPermission('services', 'read', (int) $team->id);
        $this->success(['services' => []]);
    }

    public function store(): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('services', 'write', (int) $team->id);
        $this->error('Not implemented', 501);
    }

    public function show(string $id): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('services', 'read', (int) $team->id);
        $this->notFound('Service not found');
    }

    public function update(string $id): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('services', 'write', (int) $team->id);
        $this->error('Not implemented', 501);
    }

    public function destroy(string $id): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('services', 'write', (int) $team->id);
        $this->error('Not implemented', 501);
    }
}
