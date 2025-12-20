<!DOCTYPE html>
<html lang="en">
<head>
    <?php \Chap\View\View::partial('head', ['title' => '404 - Page Not Found']); ?>
</head>
<body>
    <main class="content" style="display:grid; place-items:center; padding-top: 40px;">
        <div class="container" style="max-width: 560px;">
            <div class="card">
                <div class="card__body stack" style="gap: 14px; text-align: center;">
                    <div style="font-size: 64px; font-weight: 820; letter-spacing: -0.04em; color: var(--text-subtle); line-height: 1;">404</div>
                    <div class="h1" style="font-size: 22px;">Page Not Found</div>
                    <div class="text-muted">The page you’re looking for doesn’t exist or has been moved.</div>
                    <div class="row" style="justify-content:center; margin-top: 8px;">
                        <a href="/dashboard" class="btn btn--primary">
                            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                            </svg>
                            Go to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
