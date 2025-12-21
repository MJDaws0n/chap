<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/../partials/head.php'; ?>
</head>
<body>
    <div class="auth-layout">
        <div class="auth-container">
            <!-- Logo -->
            <div class="auth-logo">
                <svg class="auth-logo-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                </svg>
                <span class="auth-logo-text">Chap</span>
                <span class="auth-logo-tagline">Self-hosted deployment platform</span>
            </div>

            <!-- Flash Messages -->
            <?php include __DIR__ . '/../partials/flash.php'; ?>

            <!-- Auth Card -->
            <div class="auth-card">
                <?= $content ?? '' ?>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../partials/scripts.php'; ?>
</body>
</html>
