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
        $team = $this->currentTeam();
        $this->requireTeamPermission('databases', 'read', (int) $team->id);
        $this->success(['databases' => []]);
    }

    public function store(): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('databases', 'write', (int) $team->id);
        $this->error('Not implemented', 501);
    }

    public function show(string $id): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('databases', 'read', (int) $team->id);
        $this->notFound('Database not found');
    }

    public function update(string $id): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('databases', 'write', (int) $team->id);
        $this->error('Not implemented', 501);
    }

    public function destroy(string $id): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('databases', 'write', (int) $team->id);
        $this->error('Not implemented', 501);
    }
}
