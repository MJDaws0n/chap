<?php
$errors = $_SESSION['_errors'] ?? [];
unset($_SESSION['_errors']);
$old = $_SESSION['_old_input'] ?? [];
unset($_SESSION['_old_input']);
?>

<h2 class="auth-title">Sign In</h2>

<form action="/login" method="POST">
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

    <div class="form-group">
        <label for="password" class="label">Password</label>
        <input 
            type="password" 
            id="password" 
            name="password" 
            required
            class="input <?= !empty($errors['password']) ? 'input-error' : '' ?>"
            placeholder="••••••••"
        >
        <?php if (!empty($errors['password'])): ?>
            <p class="form-error"><?= htmlspecialchars($errors['password']) ?></p>
        <?php endif; ?>
    </div>

    <div class="flex items-center justify-between mb-6">
        <label class="checkbox-label">
            <input type="checkbox" name="remember" class="checkbox">
            <span>Remember me</span>
        </label>
        <a href="/forgot-password" class="text-sm text-blue">Forgot password?</a>
    </div>

    <button type="submit" class="btn btn-primary w-full">
        Sign In
    </button>
</form>

<p class="auth-footer">
    Don't have an account? <a href="/register">Sign up</a>
</p>
