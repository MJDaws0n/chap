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
    <main class="content">
        <div class="container container--fluid">
            <?= $content ?? '' ?>
        </div>
    </main>
</body>
</html>
