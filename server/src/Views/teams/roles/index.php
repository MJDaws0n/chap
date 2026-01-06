<?php
/**
 * Team Roles Index View
 */

$teamId = (int)($team->id ?? 0);
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <nav class="breadcrumb">
                    <span class="breadcrumb-item"><a href="/teams">Teams</a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-item"><a href="/teams/<?= $teamId ?>"><?= e($team->name ?? '') ?></a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current">Roles</span>
                </nav>

                <h1 class="page-header-title">Roles</h1>
                <p class="page-header-description">Define what members can see and do in this team.</p>
            </div>

            <div class="page-header-actions">
                <?php if (!empty($canManageRoles)): ?>
                    <a class="btn btn-primary" href="/teams/<?= $teamId ?>/roles/create">Create Role</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Role list</h2>
        </div>

        <div class="card-body">
            <?php if (empty($roles)): ?>
                <div class="empty-state" style="padding: var(--space-6);">
                    <p class="empty-state-title">No roles</p>
                    <p class="empty-state-description">Create a role to start assigning granular permissions.</p>
                </div>
            <?php else: ?>
                <div class="flex flex-col gap-3">
                    <?php foreach ($roles as $r): ?>
                        <?php
                        $isBuiltin = !empty($r['is_builtin']) || !empty($r['is_locked']);
                        $roleName = (string)($r['name'] ?? '');
                        $roleSlug = (string)($r['slug'] ?? '');
                        $roleLevel = (int)($r['hierarchy_level'] ?? 0);
                        ?>
                        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3 p-3" style="border: 1px solid var(--border-muted); border-radius: var(--radius-md);">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-primary truncate"><?= e($roleName) ?></p>
                                <p class="text-xs text-tertiary">slug: <code class="code-inline"><?= e($roleSlug) ?></code> · level: <code class="code-inline"><?= e((string)$roleLevel) ?></code></p>
                                <?php if ($isBuiltin): ?>
                                    <p class="text-xs text-tertiary mt-1">Built-in role (non-editable)</p>
                                <?php endif; ?>
                            </div>

                            <div class="flex items-center gap-2 justify-end">
                                <?php if (!$isBuiltin && !empty($canManageRoles)): ?>
                                    <a class="btn btn-secondary" href="/teams/<?= $teamId ?>/roles/<?= (int)$r['id'] ?>/edit">Edit</a>
                                <?php endif; ?>

                                <?php if (!$isBuiltin && !empty($canDeleteRoles)): ?>
                                    <form action="/teams/<?= $teamId ?>/roles/<?= (int)$r['id'] ?>" method="POST">
                                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button type="submit" class="btn btn-danger-ghost">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
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
