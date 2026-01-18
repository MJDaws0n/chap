<?php
/**
 * Profile Index View
 * Updated to use new design system
 */
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-header-title">Profile</h1>
                <p class="page-header-description">Manage your account settings</p>
            </div>
        </div>
    </div>

    <div class="w-full max-w-2xl flex flex-col gap-6">
        <!-- Profile Information -->
        <form action="/profile" method="POST" class="card card-glass">
            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

            <div class="card-header">
                <div>
                    <h2 class="card-title">Profile Information</h2>
                    <p class="text-secondary text-sm">Update your account's profile information and email address.</p>
                </div>
            </div>

            <div class="card-body">
                <div class="flex flex-col gap-4">
                    <div class="form-group">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" name="name" id="name"
                               value="<?= e($user['name'] ?? '') ?>"
                               class="input">
                    </div>

                    <div class="form-group">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" name="email" id="email"
                               value="<?= e($user['email'] ?? '') ?>"
                               class="input">
                    </div>
                </div>
            </div>

            <div class="card-footer">
                <div class="flex items-center justify-end gap-3">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </form>

        <!-- Change Password -->
        <form action="/profile/password" method="POST" class="card card-glass">
            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

            <div class="card-header">
                <div>
                    <h2 class="card-title">Update Password</h2>
                    <p class="text-secondary text-sm">Ensure your account is using a long, random password to stay secure.</p>
                </div>
            </div>

            <div class="card-body">
                <div class="flex flex-col gap-4">
                    <div class="form-group">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" name="current_password" id="current_password" class="input">
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label">New Password</label>
                        <input type="password" name="password" id="password" class="input">
                    </div>

                    <div class="form-group">
                        <label for="password_confirmation" class="form-label">Confirm New Password</label>
                        <input type="password" name="password_confirmation" id="password_confirmation" class="input">
                    </div>
                </div>
            </div>

            <div class="card-footer">
                <div class="flex items-center justify-end gap-3">
                    <button type="submit" class="btn btn-primary">Update Password</button>
                </div>
            </div>
        </form>

        <!-- Multi-Factor Authentication -->
        <div class="card card-glass">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Multi-Factor Authentication</h2>
                    <p class="text-secondary text-sm">Add an authenticator app code to protect your account.</p>
                </div>
            </div>

            <div class="card-body">
                <?php $mfaEnabled = (bool)($user['two_factor_enabled'] ?? false); ?>
                <p class="text-secondary text-sm mb-4">
                    Status: <strong><?= $mfaEnabled ? 'Enabled' : 'Disabled' ?></strong>
                </p>
                <a href="/profile/mfa" class="btn btn-secondary">Manage MFA</a>
            </div>
        </div>

        <!-- Delete Account -->
        <form action="/profile" method="POST" id="delete-account-form" class="card card-glass border-red">
            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="_method" value="DELETE">

            <div class="card-header">
                <div>
                    <h2 class="card-title text-red">Delete Account</h2>
                    <p class="text-secondary text-sm">Permanently delete your account and all associated data.</p>
                </div>
            </div>

            <div class="card-body">
                <p class="text-secondary text-sm mb-4">
                    Once your account is deleted, all of its resources and data will be permanently deleted.
                    Before deleting your account, please download any data or information that you wish to retain.
                </p>
                <button
                    type="button"
                    class="btn btn-danger"
                    data-delete="#delete-account-form"
                    data-delete-name="your account"
                >Delete Account</button>
            </div>
        </form>
    </div>
</div>
