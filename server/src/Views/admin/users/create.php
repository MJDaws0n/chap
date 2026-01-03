<?php
/**
 * Admin Users Create
 */

$errors = $_SESSION['_errors'] ?? [];
unset($_SESSION['_errors']);
$old = $_SESSION['_old_input'] ?? [];
unset($_SESSION['_old_input']);
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-header-title">Create User</h1>
                <p class="page-header-description">Create a new user account</p>
            </div>
            <div class="page-header-actions">
                <a href="/admin/users" class="btn btn-secondary">Back</a>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="/admin/users" class="form">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

                <div class="form-group">
                    <label class="form-label" for="name">Name</label>
                    <input class="input <?= !empty($errors['name']) ? 'input-error' : '' ?>" id="name" name="name" type="text" value="<?= e($old['name'] ?? '') ?>" required>
                    <?php if (!empty($errors['name'])): ?>
                        <p class="form-error"><?= e($errors['name']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input class="input <?= !empty($errors['username']) ? 'input-error' : '' ?>" id="username" name="username" type="text" value="<?= e($old['username'] ?? '') ?>" required>
                    <?php if (!empty($errors['username'])): ?>
                        <p class="form-error"><?= e($errors['username']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">Email</label>
                    <input class="input <?= !empty($errors['email']) ? 'input-error' : '' ?>" id="email" name="email" type="email" value="<?= e($old['email'] ?? '') ?>" required>
                    <?php if (!empty($errors['email'])): ?>
                        <p class="form-error"><?= e($errors['email']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input class="input <?= !empty($errors['password']) ? 'input-error' : '' ?>" id="password" name="password" type="password" required>
                    <?php if (!empty($errors['password'])): ?>
                        <p class="form-error"><?= e($errors['password']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="checkbox">
                        <input type="checkbox" name="is_admin" <?= (($old['is_admin'] ?? '') === 'on') ? 'checked' : '' ?>>
                        <span>Make this user an admin</span>
                    </label>
                    <?php if (!empty($errors['is_admin'])): ?>
                        <p class="form-error"><?= e($errors['is_admin']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="btn btn-primary">Create User</button>
                    <a href="/admin/users" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
