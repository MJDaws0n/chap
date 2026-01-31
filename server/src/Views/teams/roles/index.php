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

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-purple icon-box-lg">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622C17.176 19.29 21 14.591 21 9c0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="page-header-title truncate">Roles</h1>
                        <p class="page-header-description truncate">Define what members can see and do in this team.</p>
                    </div>
                </div>
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
                <div class="empty-state p-6">
                    <p class="empty-state-title">No roles</p>
                    <p class="empty-state-description">Create a role to start assigning granular permissions.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Slug</th>
                                <th>Level</th>
                                <th>Type</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roles as $r): ?>
                                <?php
                                $isBuiltin = !empty($r['is_builtin']) || !empty($r['is_locked']);
                                $roleName = (string)($r['name'] ?? '');
                                $roleSlug = (string)($r['slug'] ?? '');
                                $roleLevel = (int)($r['hierarchy_level'] ?? 0);
                                ?>
                                <tr>
                                    <td>
                                        <div class="font-medium"><?= e($roleName) ?></div>
                                    </td>
                                    <td class="text-xs text-secondary"><code class="break-all"><?= e($roleSlug) ?></code></td>
                                    <td class="text-xs text-secondary"><?= e((string)$roleLevel) ?></td>
                                    <td>
                                        <?php if ($isBuiltin): ?>
                                            <span class="badge badge-neutral">Built-in</span>
                                        <?php else: ?>
                                            <span class="badge badge-success">Custom</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <div class="flex flex-wrap items-center justify-end gap-2">
                                            <?php if (!$isBuiltin && !empty($canManageRoles)): ?>
                                                <a class="btn btn-secondary btn-sm" href="/teams/<?= $teamId ?>/roles/<?= (int)$r['id'] ?>/edit">Edit</a>
                                            <?php endif; ?>

                                            <?php if (!$isBuiltin && !empty($canDeleteRoles)): ?>
                                                <form action="/teams/<?= $teamId ?>/roles/<?= (int)$r['id'] ?>" method="POST" data-delete-role-form data-role-name="<?= e($roleName) ?>">
                                                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                                    <input type="hidden" name="_method" value="DELETE">
                                                    <button type="submit" class="btn btn-danger-ghost btn-sm">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function() {
    document.querySelectorAll('form[data-delete-role-form]').forEach((form) => {
        form.addEventListener('submit', async (e) => {
            if (form.dataset.submitting === '1') return;
            e.preventDefault();

            const roleName = form.dataset.roleName || 'this role';
            const fallback = () => {
                if (confirm('Delete this role?')) {
                    form.dataset.submitting = '1';
                    form.submit();
                }
            };

            if (!window.Modal || typeof window.Modal.show !== 'function') {
                fallback();
                return;
            }

            const res = await window.Modal.show({
                type: 'danger',
                title: 'Delete role',
                message: `Delete ${roleName}? This cannot be undone.`,
                showCancel: true,
                confirmText: 'Delete',
                cancelText: 'Cancel',
                closeOnBackdrop: false
            });

            if (res && res.confirmed) {
                form.dataset.submitting = '1';
                form.submit();
            }
        });
    });
})();
</script>
