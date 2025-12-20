<!DOCTYPE html>
<html lang="en">
<head>
    <?php \Chap\View\View::partial('head', ['title' => $title ?? 'Chap']); ?>
</head>
<body>
    <header class="topbar">
        <div class="container row row--between">
            <a href="/" class="row" style="gap:10px">
                <span style="font-weight:740; letter-spacing:-0.02em">Chap</span>
            </a>
            <div class="theme-switch" role="group" aria-label="Theme">
                <button type="button" data-theme-mode="auto" aria-pressed="false">Auto</button>
                <button type="button" data-theme-mode="light" aria-pressed="false">Light</button>
                <button type="button" data-theme-mode="dark" aria-pressed="false">Dark</button>
            </div>
        </div>
    </header>

    <main class="content" style="display:grid; place-items:center; padding-top: 28px;">
        <div class="container" style="max-width: 460px;">
            <div class="card">
                <div class="card__body stack" style="gap:14px">
                    <div class="stack" style="gap:6px">
                        <div class="row" style="justify-content:center">
                            <span style="font-size:24px; font-weight:760; letter-spacing:-0.02em">Chap</span>
                        </div>
                        <div class="text-muted" style="text-align:center; font-size:13px">Self-hosted deployment platform</div>
                    </div>

                    <?php \Chap\View\View::partial('flash', ['flash' => $flash ?? []]); ?>

                    <?= $content ?? '' ?>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
