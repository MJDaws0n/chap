<?php
/**
 * Admin Users Index
 */
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-header-title">Users</h1>
                <p class="page-header-description">Create and manage user accounts</p>
            </div>
            <div class="page-header-actions">
                <form method="POST" action="/admin/users/register-page/toggle" class="inline-block">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <?php $enabled = (bool)($registrationEnabled ?? true); ?>
                    <button type="submit" class="btn btn-secondary">
                        <?= $enabled ? 'Disable Register Page' : 'Enable Register Page' ?>
                    </button>
                </form>
                <a href="/admin/users/create" class="btn btn-primary">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    New User
                </a>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-container">
            <table class="table table-clickable">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Admin</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($users ?? []) as $u): ?>
                        <tr onclick="window.location='/admin/users/<?= (int)$u->id ?>/edit'">
                            <td>
                                <div class="min-w-0">
                                    <p class="font-medium text-primary truncate"><?= e($u->displayName()) ?></p>
                                    <p class="text-sm text-tertiary truncate"><?= e($u->username) ?></p>
                                </div>
                            </td>
                            <td class="text-secondary"><?= e($u->email) ?></td>
                            <td>
                                <?php if (!empty($u->is_admin)): ?>
                                    <span class="badge badge-success">Yes</span>
                                <?php else: ?>
                                    <span class="badge badge-neutral">No</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-secondary"><?= !empty($u->created_at) ? e(time_ago($u->created_at)) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($pagination) && ($pagination['last_page'] ?? 1) > 1): ?>
            <div class="card-body flex items-center justify-between">
                <div class="text-sm text-secondary">
                    Page <?= (int)($pagination['page'] ?? 1) ?> of <?= (int)($pagination['last_page'] ?? 1) ?>
                </div>
                <div class="flex gap-2">
                    <?php $page = (int)($pagination['page'] ?? 1); ?>
                    <?php if ($page > 1): ?>
                        <a class="btn btn-ghost btn-sm" href="/admin/users?page=<?= $page - 1 ?>">Previous</a>
                    <?php endif; ?>
                    <?php if ($page < (int)($pagination['last_page'] ?? 1)): ?>
                        <a class="btn btn-ghost btn-sm" href="/admin/users?page=<?= $page + 1 ?>">Next</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
