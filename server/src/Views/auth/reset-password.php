<?php
$errors = $_SESSION['_errors'] ?? [];
unset($_SESSION['_errors']);
$old = $_SESSION['_old_input'] ?? [];
unset($_SESSION['_old_input']);

$emailPrefill = '';
if (!empty($old['email'])) {
    $emailPrefill = (string)$old['email'];
} elseif (!empty($email)) {
    $emailPrefill = (string)$email;
}
?>

<h2 class="auth-title">Reset Password</h2>
<p class="text-secondary text-sm mb-6">This link expires in 1 hour.</p>

<form action="/reset-password" method="POST">
    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="token" value="<?= htmlspecialchars((string)($token ?? '')) ?>">

    <div class="form-group">
        <label for="email" class="label">Email</label>
        <input
            type="email"
            id="email"
            name="email"
            required
            class="input <?= !empty($errors['email']) ? 'input-error' : '' ?>"
            value="<?= htmlspecialchars($emailPrefill) ?>"
            placeholder="you@example.com"
        >
        <?php if (!empty($errors['email'])): ?>
            <p class="form-error"><?= htmlspecialchars($errors['email']) ?></p>
        <?php endif; ?>
    </div>

    <div class="form-group">
        <label for="password" class="label">New Password</label>
        <input
            type="password"
            id="password"
            name="password"
            required
            minlength="8"
            class="input <?= !empty($errors['password']) ? 'input-error' : '' ?>"
            placeholder="••••••••"
        >
        <?php if (!empty($errors['password'])): ?>
            <p class="form-error"><?= htmlspecialchars($errors['password']) ?></p>
        <?php endif; ?>
        <p class="form-hint">Must be at least 8 characters</p>
    </div>

    <div class="form-group">
        <label for="password_confirmation" class="label">Confirm New Password</label>
        <input
            type="password"
            id="password_confirmation"
            name="password_confirmation"
            required
            minlength="8"
            class="input"
            placeholder="••••••••"
        >
    </div>

    <button type="submit" class="btn btn-primary w-full">
        Reset Password
    </button>
</form>

<p class="auth-footer">
    <a href="/login">Back to sign in</a>
</p>
