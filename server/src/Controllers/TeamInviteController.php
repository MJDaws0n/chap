<?php

namespace Chap\Controllers;

use Chap\App;
use Chap\Auth\TeamPermissionService;
use Chap\Models\Team;
use Chap\Models\TeamInvitation;
use Chap\Models\User;

class TeamInviteController extends BaseController
{
    /**
     * Public invite landing page.
     */
    public function show(string $token): void
    {
        $invite = TeamInvitation::findByToken($token);

        if (!$invite) {
            $this->view('team_invites/show', [
                'title' => 'Team invitation',
                'invite' => null,
                'team' => null,
                'status' => 'invalid',
            ]);
            return;
        }

        $team = Team::find((int)$invite->team_id);
        if (!$team) {
            $this->view('team_invites/show', [
                'title' => 'Team invitation',
                'invite' => $invite,
                'team' => null,
                'status' => 'invalid',
            ]);
            return;
        }

        $status = $invite->status;
        if ($invite->isExpired() && $status === 'pending') {
            $status = 'expired';
        }

        $this->view('team_invites/show', [
            'title' => 'Team invitation',
            'invite' => $invite,
            'team' => $team,
            'status' => $status,
            'token' => $token,
        ]);
    }

    /**
     * Accept an invite (requires auth).
     */
    public function accept(string $token): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/team-invites/' . urlencode($token));
            return;
        }

        $user = $this->user;
        if (!$user) {
            flash('error', 'Please log in to accept the invitation');
            $this->redirect('/team-invites/' . urlencode($token));
            return;
        }

        $invite = TeamInvitation::findByToken($token);
        if (!$invite) {
            flash('error', 'Invitation not found');
            $this->redirect('/team-invites/' . urlencode($token));
            return;
        }

        if (!$invite->isPending() || $invite->isExpired()) {
            flash('error', 'This invitation is no longer valid');
            $this->redirect('/team-invites/' . urlencode($token));
            return;
        }

        if (strcasecmp((string)$invite->email, (string)$user->email) !== 0 && (int)($invite->invitee_user_id ?? 0) !== (int)$user->id) {
            flash('error', 'This invitation was sent to a different email address');
            $this->redirect('/team-invites/' . urlencode($token));
            return;
        }

        $team = Team::find((int)$invite->team_id);
        if (!$team) {
            flash('error', 'Team not found');
            $this->redirect('/team-invites/' . urlencode($token));
            return;
        }

        $db = App::db();
        $db->beginTransaction();
        try {
            if (!$team->hasMember((int)$user->id)) {
                $legacyRole = ($invite->base_role_slug === 'admin') ? 'admin' : 'member';
                $team->addMember((int)$user->id, $legacyRole);
            }

            if (!$team->hasMember((int)$user->id)) {
                throw new \RuntimeException('Unable to join team');
            }

            // Resolve and apply roles (safe subset; no actor checks during acceptance).
            $baseRole = $db->fetch(
                "SELECT id FROM team_roles WHERE team_id = ? AND slug = ? LIMIT 1",
                [(int)$team->id, (string)$invite->base_role_slug]
            );
            if (!$baseRole) {
                throw new \RuntimeException('Invitation role is no longer available');
            }

            $roleIds = array_merge([(int)$baseRole['id']], $invite->customRoleIds());
            TeamPermissionService::applyUserRolesFromInvitation((int)$team->id, (int)$user->id, $roleIds);

            $db->update('team_invitations', [
                'status' => 'accepted',
                'accepted_by_user_id' => (int)$user->id,
                'accepted_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [(int)$invite->id]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            flash('error', $e->getMessage());
            $this->redirect('/team-invites/' . urlencode($token));
            return;
        }

        flash('success', 'Invitation accepted. You have joined the team.');
        $this->redirect('/teams/' . (int)$team->id);
    }

    /**
     * Decline an invite (token-based).
     */
    public function decline(string $token): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/team-invites/' . urlencode($token));
            return;
        }

        $invite = TeamInvitation::findByToken($token);
        if (!$invite) {
            flash('error', 'Invitation not found');
            $this->redirect('/team-invites/' . urlencode($token));
            return;
        }

        if ($invite->status !== 'pending') {
            flash('success', 'Invitation updated');
            $this->redirect('/team-invites/' . urlencode($token));
            return;
        }

        $db = App::db();
        $db->update('team_invitations', [
            'status' => 'declined',
            'declined_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [(int)$invite->id]);

        flash('success', 'Invitation declined');
        $this->redirect('/team-invites/' . urlencode($token));
    }

    /**
     * Revoke a pending invite (team member management).
     */
    public function revoke(int $teamId, int $inviteId): void
    {
        $team = Team::find($teamId);
        if (!$team) {
            flash('error', 'Team not found');
            $this->redirect('/teams');
            return;
        }

        $actorId = (int)($this->user?->id ?? 0);
        if (!admin_view_all() && !TeamPermissionService::can((int)$team->id, $actorId, 'team.members', 'execute')) {
            flash('error', 'Permission denied');
            $this->redirect('/teams/' . (int)$team->id);
            return;
        }

        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/teams/' . (int)$team->id);
            return;
        }

        $db = App::db();
        $row = $db->fetch(
            "SELECT * FROM team_invitations WHERE id = ? AND team_id = ? LIMIT 1",
            [$inviteId, (int)$team->id]
        );
        if (!$row) {
            flash('error', 'Invitation not found');
            $this->redirect('/teams/' . (int)$team->id);
            return;
        }

        $invite = TeamInvitation::fromArray($row);
        if ($invite->status !== 'pending') {
            flash('success', 'Invitation updated');
            $this->redirect('/teams/' . (int)$team->id);
            return;
        }

        $db->update('team_invitations', [
            'status' => 'revoked',
            'revoked_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [(int)$invite->id]);

        flash('success', 'Invitation revoked');
        $this->redirect('/teams/' . (int)$team->id);
    }
}
