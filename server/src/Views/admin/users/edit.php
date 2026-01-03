<?php
/**
 * Admin Users Edit
 */

$errors = $_SESSION['_errors'] ?? [];
unset($_SESSION['_errors']);
$old = $_SESSION['_old_input'] ?? [];
unset($_SESSION['_old_input']);

/** @var \Chap\Models\User $editUser */
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-header-title">Edit User</h1>
                <p class="page-header-description"><?= e($editUser->email) ?></p>
            </div>
            <div class="page-header-actions">
                <a href="/admin/users" class="btn btn-secondary">Back</a>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="/admin/users/<?= (int)$editUser->id ?>" class="form">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="_method" value="PUT">

                <div class="form-group">
                    <label class="form-label" for="name">Name</label>
                    <input class="input <?= !empty($errors['name']) ? 'input-error' : '' ?>" id="name" name="name" type="text"
                           value="<?= e($old['name'] ?? $editUser->name ?? '') ?>" required>
                    <?php if (!empty($errors['name'])): ?>
                        <p class="form-error"><?= e($errors['name']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input class="input <?= !empty($errors['username']) ? 'input-error' : '' ?>" id="username" name="username" type="text"
                           value="<?= e($old['username'] ?? $editUser->username ?? '') ?>" required>
                    <?php if (!empty($errors['username'])): ?>
                        <p class="form-error"><?= e($errors['username']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">Email</label>
                    <input class="input <?= !empty($errors['email']) ? 'input-error' : '' ?>" id="email" name="email" type="email"
                           value="<?= e($old['email'] ?? $editUser->email ?? '') ?>" required>
                    <?php if (!empty($errors['email'])): ?>
                        <p class="form-error"><?= e($errors['email']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">New Password (optional)</label>
                    <input class="input <?= !empty($errors['password']) ? 'input-error' : '' ?>" id="password" name="password" type="password" placeholder="Leave blank to keep current">
                    <?php if (!empty($errors['password'])): ?>
                        <p class="form-error"><?= e($errors['password']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <?php $checked = ($old['is_admin'] ?? null) !== null
                        ? (($old['is_admin'] ?? '') === 'on')
                        : (bool)$editUser->is_admin;
                    ?>
                    <label class="checkbox">
                        <input type="checkbox" name="is_admin" <?= $checked ? 'checked' : '' ?>>
                        <span>Admin</span>
                    </label>
                    <?php if (!empty($errors['is_admin'])): ?>
                        <p class="form-error"><?= e($errors['is_admin']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="/admin/users" class="btn btn-secondary">Cancel</a>
                    </div>

                    <form method="POST" action="/admin/users/<?= (int)$editUser->id ?>" onsubmit="return confirm('Delete this user?')">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="_method" value="DELETE">
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </form>
        </div>
    </div>
</div>
