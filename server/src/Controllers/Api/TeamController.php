<?php

namespace Chap\Controllers\Api;

use Chap\Models\Team;

/**
 * API Team Controller
 */
class TeamController extends BaseApiController
{
    /**
     * List teams
     */
    public function index(): void
    {
        $teams = $this->user->teams();
        $this->success([
            'teams' => array_map(fn($t) => $t->toArray(), $teams),
        ]);
    }

    /**
     * Create team
     */
    public function store(): void
    {
        $data = $this->all();

        if (empty($data['name'])) {
            $this->validationError(['name' => 'Name is required']);
            return;
        }

        $team = Team::create([
            'uuid' => uuid(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'personal_team' => false,
        ]);

        // Add user as owner
        \Chap\App::db()->insert('team_user', [
            'team_id' => $team->id,
            'user_id' => $this->user->id,
            'role' => 'owner',
        ]);

        // Ensure built-in roles exist for this team.
        \Chap\Auth\TeamRoleSeeder::ensureBuiltins((int) $team->id);

        $this->success(['team' => $team->toArray()], 201);
    }

    /**
     * Show team
     */
    public function show(string $id): void
    {
        $team = Team::findByUuid($id);

        if (!$team || (!$this->user->belongsToTeam($team) && !admin_view_all())) {
            $this->notFound('Team not found');
            return;
        }

        $this->success(['team' => $team->toArray()]);
    }

    /**
     * Update team
     */
    public function update(string $id): void
    {
        $team = Team::findByUuid($id);

        if (!$team || (!$this->user->belongsToTeam($team) && !admin_view_all())) {
            $this->notFound('Team not found');
            return;
        }

        $this->requireTeamPermission('team.settings', 'write', (int) $team->id);

        $data = $this->all();
        
        $team->update([
            'name' => $data['name'] ?? $team->name,
            'description' => $data['description'] ?? $team->description,
        ]);

        $this->success(['team' => $team->toArray()]);
    }

    /**
     * Delete team
     */
    public function destroy(string $id): void
    {
        $team = Team::findByUuid($id);

        if (!$team || (!$this->user->belongsToTeam($team) && !admin_view_all())) {
            $this->notFound('Team not found');
            return;
        }

        if (!$this->user->isTeamOwner($team) && !admin_view_all()) {
            $this->forbidden('Only the team owner can delete the team');
            return;
        }

        if ($team->personal_team) {
            $this->error('Cannot delete personal team', 400);
            return;
        }

        $team->delete();

        $this->success(['message' => 'Team deleted']);
    }
}
