<?php
$errors = $_SESSION['_errors'] ?? [];
unset($_SESSION['_errors']);
?>

<h2 class="auth-title">Two-Factor Authentication</h2>

<p class="text-secondary text-sm mb-6">
    Enter the 6-digit code from your authenticator app for <strong><?= htmlspecialchars($email ?? '', ENT_QUOTES) ?></strong>.
</p>

<form action="/mfa" method="POST">
    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

    <div class="form-group">
        <label for="code" class="label">Authentication code</label>
        <input
            type="text"
            id="code"
            name="code"
            inputmode="numeric"
            autocomplete="one-time-code"
            pattern="[0-9]{6}"
            required
            class="input <?= !empty($errors['code']) ? 'input-error' : '' ?>"
            placeholder="123456"
        >
        <?php if (!empty($errors['code'])): ?>
            <p class="form-error"><?= htmlspecialchars($errors['code']) ?></p>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary w-full">
        Verify
    </button>
</form>

<p class="auth-footer">
    <a href="/login">Back to login</a>
</p>
