<?php
/**
 * Team Invite View
 */

$team = $team ?? null;
$invite = $invite ?? null;
$status = $status ?? 'invalid';
$token = $token ?? '';
$userArr = $user ?? null;

$loggedInEmail = is_array($userArr) ? (string)($userArr['email'] ?? '') : '';
$invitedEmail = $invite ? (string)($invite->email ?? '') : '';

$canAccept = false;
if ($status === 'pending' && $invite && $loggedInEmail !== '') {
    $canAccept = (strcasecmp($invitedEmail, $loggedInEmail) === 0);
}

$baseLabels = [
    'admin' => 'Admin',
    'manager' => 'Manager',
    'member' => 'Member',
    'read_only_member' => 'Read-only Member',
];
$baseRoleLabel = $invite ? ($baseLabels[(string)$invite->base_role_slug] ?? (string)$invite->base_role_slug) : '';
?>

<div class="flex flex-col gap-6" style="max-width: 720px; margin: 0 auto;">
    <div class="page-header">
        <div>
            <h1 class="page-header-title">Team invitation</h1>
            <p class="page-header-description">Accept the invite to join the team, or ignore it if you don’t want to join.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <?php if ($status === 'invalid' || !$invite || !$team): ?>
                <div class="empty-state" style="padding: var(--space-6);">
                    <p class="empty-state-title">Invalid invitation</p>
                    <p class="empty-state-description">This invitation link is not valid.</p>
                </div>
            <?php elseif ($status === 'expired'): ?>
                <div class="empty-state" style="padding: var(--space-6);">
                    <p class="empty-state-title">Invitation expired</p>
                    <p class="empty-state-description">This invitation has expired. Ask a team admin to send a new invite.</p>
                </div>
            <?php elseif ($status === 'accepted'): ?>
                <div class="empty-state" style="padding: var(--space-6);">
                    <p class="empty-state-title">Invitation accepted</p>
                    <p class="empty-state-description">You’re already a member of <?= e((string)$team->name) ?>.</p>
                    <div class="mt-3">
                        <a class="btn btn-primary" href="/teams/<?= (int)$team->id ?>">Go to team</a>
                    </div>
                </div>
            <?php elseif ($status === 'declined'): ?>
                <div class="empty-state" style="padding: var(--space-6);">
                    <p class="empty-state-title">Invitation declined</p>
                    <p class="empty-state-description">You declined this invitation.</p>
                </div>
            <?php elseif ($status === 'revoked'): ?>
                <div class="empty-state" style="padding: var(--space-6);">
                    <p class="empty-state-title">Invitation revoked</p>
                    <p class="empty-state-description">This invitation was revoked by the team.</p>
                </div>
            <?php else: ?>
                <div class="flex flex-col gap-2">
                    <p class="text-sm text-secondary">You’ve been invited to join:</p>
                    <p class="text-lg font-semibold"><?= e((string)$team->name) ?></p>
                    <p class="text-sm text-tertiary">Invited email: <span class="code-inline"><?= e($invitedEmail) ?></span></p>
                    <p class="text-sm text-tertiary">Role: <span class="badge badge-neutral"><?= e($baseRoleLabel) ?></span></p>

                    <hr style="border: 0; border-top: 1px solid var(--border-muted); margin: var(--space-4) 0;" />

                    <?php if (empty($loggedInEmail)): ?>
                        <p class="text-sm">To accept this invitation, log in (or register) using <strong><?= e($invitedEmail) ?></strong>, then return to this page.</p>
                        <div class="flex gap-2 mt-2">
                            <a class="btn btn-primary" href="/login">Log in</a>
                            <a class="btn btn-secondary" href="/register">Create account</a>
                        </div>
                        <p class="text-xs text-tertiary mt-2">If you don’t want to join, you can safely ignore this invite.</p>
                    <?php elseif (!$canAccept): ?>
                        <p class="text-sm">You’re logged in as <strong><?= e($loggedInEmail) ?></strong>, but this invite was sent to <strong><?= e($invitedEmail) ?></strong>.</p>
                        <p class="text-xs text-tertiary">Log in with the invited email to accept.</p>
                        <div class="flex gap-2 mt-2">
                            <form action="/logout" method="POST">
                                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                <button type="submit" class="btn btn-secondary">Log out</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="flex flex-col md:flex-row gap-2 mt-2">
                            <form action="/team-invites/<?= e($token) ?>/accept" method="POST">
                                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                <button type="submit" class="btn btn-primary">Accept invitation</button>
                            </form>

                            <form action="/team-invites/<?= e($token) ?>/decline" method="POST">
                                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                <button type="submit" class="btn btn-danger-ghost">Decline</button>
                            </form>
                        </div>
                        <p class="text-xs text-tertiary mt-2">If you don’t want to join, you can ignore this invite.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.code-inline {
    font-family: var(--font-mono);
    font-size: var(--text-sm);
    background-color: var(--bg-tertiary);
    padding: var(--space-1) var(--space-2);
    border-radius: var(--radius-sm);
    word-break: break-all;
}
</style>
