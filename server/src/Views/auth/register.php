<?php
$errors = $_SESSION['_errors'] ?? [];
unset($_SESSION['_errors']);
$old = $_SESSION['_old_input'] ?? [];
unset($_SESSION['_old_input']);
?>

<h2 class="auth-title">Create Account</h2>

<form action="/register" method="POST">
    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

    <div class="form-group">
        <label for="name" class="label">Name</label>
        <input 
            type="text" 
            id="name" 
            name="name" 
            required
            class="input <?= !empty($errors['name']) ? 'input-error' : '' ?>"
            value="<?= htmlspecialchars($old['name'] ?? '') ?>"
            placeholder="Your name"
        >
        <?php if (!empty($errors['name'])): ?>
            <p class="form-error"><?= htmlspecialchars($errors['name']) ?></p>
        <?php endif; ?>
    </div>

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
        <label for="password_confirmation" class="label">Confirm Password</label>
        <input 
            type="password" 
            id="password_confirmation" 
            name="password_confirmation" 
            required
            class="input"
            placeholder="••••••••"
        >
    </div>

    <?php $captchaProvider = config('captcha.provider', 'none'); ?>
    <?php if ($captchaProvider === 'recaptcha'): ?>
        <?php $siteKey = config('captcha.recaptcha.site_key', ''); ?>
        <?php if (!empty($siteKey)): ?>
            <div class="mb-6">
                <div class="g-recaptcha" data-sitekey="<?= e($siteKey) ?>"></div>
            </div>
            <script src="https://www.google.com/recaptcha/api.js" async defer></script>
        <?php endif; ?>
    <?php elseif ($captchaProvider === 'autogate'): ?>
        <?php $publicKey = config('captcha.autogate.public_key', ''); ?>
        <?php $theme = config('captcha.theme', 'dark'); ?>
        <?php if (!empty($publicKey)): ?>
            <div class="mb-6">
                <div id="captcha"></div>
                <input type="hidden" name="captcha_token" id="captcha_token">
            </div>
            <script src="https://autogate.mjdawson.net/lib/autogate.js"></script>
            <script>
            (() => {
                const el = document.getElementById('captcha');
                const tokenEl = document.getElementById('captcha_token');
                if (!el || !tokenEl) return;

                const gate = new AutoGate('#captcha', <?= json_encode($publicKey) ?>, {
                    theme: <?= json_encode($theme) ?>,
                });

                gate.onSuccess = (token) => {
                    tokenEl.value = token;
                };
            })();
            </script>
        <?php endif; ?>
    <?php endif; ?>

    <button type="submit" class="btn btn-primary w-full">
        Create Account
    </button>
</form>

<p class="auth-footer">
    Already have an account? <a href="/login">Sign in</a>
</p>
