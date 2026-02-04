<?php
$errors = $_SESSION['_errors'] ?? [];
unset($_SESSION['_errors']);
$old = $_SESSION['_old_input'] ?? [];
unset($_SESSION['_old_input']);
?>

<h2 class="auth-title">Forgot Password</h2>
<p class="text-secondary text-sm mb-6">Enter your email and we’ll send you a password reset link.</p>

<form action="/forgot-password" method="POST">
    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

    <div class="form-group">
        <label for="email" class="label">Email</label>
        <input
            type="email"
            id="email"
            name="email"
            required
            class="input <?= !empty($errors['email']) ? 'input-error' : '' ?>"
            value="<?= htmlspecialchars($old['email'] ?? '') ?>"
            placeholder="you@example.com"
        >
        <?php if (!empty($errors['email'])): ?>
            <p class="form-error"><?= htmlspecialchars($errors['email']) ?></p>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/../partials/captcha.php'; ?>

    <button type="submit" class="btn btn-primary w-full">
        Send Reset Link
    </button>
</form>

<p class="auth-footer">
    Remembered your password? <a href="/login">Sign in</a>
</p>
