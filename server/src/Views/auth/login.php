<div class="stack" style="gap: 16px;">
    <div class="stack" style="gap: 6px;">
        <h2 class="h1" style="font-size: 22px;">Sign In</h2>
        <div class="text-muted" style="font-size: 13px;">Use your email and password.</div>
    </div>

    <form action="/login" method="POST" class="stack" style="gap: 14px;">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

        <div class="field">
            <label for="email" class="label">Email</label>
            <input 
                type="email" 
                id="email" 
                name="email" 
                required
                class="input"
                value="<?= htmlspecialchars($old['email'] ?? '') ?>"
            >
            <?php if (!empty($errors['email'])): ?>
                <div class="text-subtle" style="color: var(--danger); font-size: 13px;">
                    <?= htmlspecialchars($errors['email']) ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="password" class="label">Password</label>
            <input 
                type="password" 
                id="password" 
                name="password" 
                required
                class="input"
            >
            <?php if (!empty($errors['password'])): ?>
                <div class="text-subtle" style="color: var(--danger); font-size: 13px;">
                    <?= htmlspecialchars($errors['password']) ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="row row--between" style="gap: 14px; align-items: center;">
            <label class="row" style="gap: 10px; align-items: center;">
                <input type="checkbox" name="remember">
                <span class="text-muted" style="font-size: 13px;">Remember me</span>
            </label>
            <a href="/forgot-password" class="text-muted" style="font-size: 13px;">Forgot password?</a>
        </div>

        <button type="submit" class="btn btn--primary" style="width: 100%;">Sign In</button>
    </form>

    <div class="stack" style="gap: 12px;">
        <div class="row" style="gap: 12px; justify-content: center;">
            <div style="height:1px; background: var(--border); flex: 1 1 auto;"></div>
            <div class="text-subtle" style="font-size: 12px;">Or continue with</div>
            <div style="height:1px; background: var(--border); flex: 1 1 auto;"></div>
        </div>

        <a href="/auth/github" class="btn" style="width: 100%; justify-content: center;">
                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
                </svg>
                GitHub
        </a>
    </div>

    <div class="text-muted" style="text-align:center; font-size: 13px;">
        Don’t have an account? <a href="/register">Sign up</a>
    </div>
</div>
