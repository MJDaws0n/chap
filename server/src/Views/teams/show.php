<?php
/**
 * Team Show View
 */

$canManage = !empty($isOwner) || !empty($isAdmin);
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <nav class="breadcrumb">
                    <span class="breadcrumb-item"><a href="/teams">Teams</a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current"><?= e($team->name) ?></span>
                </nav>

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-lg icon-box-blue">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="page-header-title"><?= e($team->name) ?></h1>
                        <?php if (!empty($team->description)): ?>
                            <p class="page-header-description line-clamp-2"><?= e($team->description) ?></p>
                        <?php else: ?>
                            <p class="page-header-description">No description</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="page-header-actions">
                <form action="/teams/<?= (int)$team->id ?>/switch" method="POST">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" class="btn btn-secondary">Set Current</button>
                </form>

                <?php if ($canManage): ?>
                    <a href="/teams/<?= (int)$team->id ?>/edit" class="btn btn-ghost">Edit</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Members</h2>
        </div>

        <div class="card-body">
            <?php if ($canManage): ?>
                <form action="/teams/<?= (int)$team->id ?>/members" method="POST" class="card" style="background: transparent; border: 1px solid var(--border-default);">
                    <div class="card-body">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="form-group">
                                <label class="form-label" for="account">Account</label>
                                <input class="input" type="text" id="account" name="account" placeholder="email or username" required>
                                <p class="form-hint">User must already exist.</p>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="role">Role</label>
                                <select class="select" id="role" name="role">
                                    <option value="member" selected>member</option>
                                    <option value="admin">admin</option>
                                </select>
                                <p class="form-hint">
                                    <strong>admin</strong>: manage members + team settings. <strong>member</strong>: access team resources.
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center justify-end">
                            <button type="submit" class="btn btn-primary">Add Member</button>
                        </div>
                    </div>
                </form>

                <div class="mt-4"></div>
            <?php endif; ?>

            <?php if (empty($members)): ?>
                <div class="empty-state" style="padding: var(--space-6);">
                    <p class="empty-state-title">No members</p>
                    <p class="empty-state-description">This team has no members yet.</p>
                </div>
            <?php else: ?>
                <div class="flex flex-col gap-3">
                    <?php foreach ($members as $member): ?>
                        <?php
                        $memberRole = $member->role ?? 'member';
                        $isTeamOwnerRow = ($memberRole === 'owner');
                        ?>
                        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3 p-3" style="border: 1px solid var(--border-muted); border-radius: var(--radius-md);">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-primary truncate"><?= e($member->displayName()) ?></p>
                                <p class="text-xs text-tertiary"><?= e($member->email ?? '') ?> · role: <code class="code-inline"><?= e($memberRole) ?></code></p>
                            </div>

                            <?php if ($canManage && !$isTeamOwnerRow): ?>
                                <div class="flex flex-col gap-2 w-full md:w-auto">
                                    <form action="/teams/<?= (int)$team->id ?>/members/<?= (int)$member->id ?>" method="POST" class="flex flex-col md:flex-row gap-2">
                                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="_method" value="PUT">

                                        <select class="select" name="role">
                                            <option value="member" <?= $memberRole === 'member' ? 'selected' : '' ?>>member</option>
                                            <option value="admin" <?= $memberRole === 'admin' ? 'selected' : '' ?>>admin</option>
                                        </select>

                                        <button type="submit" class="btn btn-secondary">Save</button>
                                    </form>

                                    <form action="/teams/<?= (int)$team->id ?>/members/<?= (int)$member->id ?>" method="POST" class="flex justify-end">
                                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button type="submit" class="btn btn-danger-ghost">Remove</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.code-inline {
    font-family: var(--font-mono);
    font-size: var(--text-sm);
    background-color: var(--bg-tertiary);
    padding: var(--space-1) var(--space-2);
    border-radius: var(--radius-sm);
    word-break: break-all;
}
</style>
