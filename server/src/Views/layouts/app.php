<!DOCTYPE html>
<html lang="en">
<head>
    <?php \Chap\View\View::partial('head', ['title' => $title ?? 'Chap']); ?>
</head>
<body>
    <div class="sidebar__overlay" data-action="sidebar-overlay" aria-hidden="true"></div>
    <div class="app-shell">
        <!-- Sidebar -->
        <aside class="sidebar" aria-label="Primary">
            <!-- Logo -->
            <div class="row" style="padding: 10px 10px 14px; border-bottom: 1px solid var(--border)">
                <a href="/dashboard" class="row" style="gap:10px">
                    <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                    </svg>
                    <span style="font-size: 18px; font-weight: 760; letter-spacing: -0.02em">Chap</span>
                </a>
            </div>

            <!-- Navigation -->
            <nav class="nav" style="flex:1">
                <a href="/dashboard" class="nav-link <?= ($currentPage ?? '') === 'dashboard' ? 'is-active' : '' ?>">
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    <span>Dashboard</span>
                </a>

                <a href="/projects" class="nav-link <?= ($currentPage ?? '') === 'projects' ? 'is-active' : '' ?>">
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                    </svg>
                    <span>Projects</span>
                </a>

                <a href="/nodes" class="nav-link <?= ($currentPage ?? '') === 'nodes' ? 'is-active' : '' ?>">
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
                    </svg>
                    <span>Nodes</span>
                </a>

                <a href="/templates" class="nav-link <?= ($currentPage ?? '') === 'templates' ? 'is-active' : '' ?>">
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/>
                    </svg>
                    <span>Templates</span>
                </a>

                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border)">
                    <a href="/teams" class="nav-link <?= ($currentPage ?? '') === 'teams' ? 'is-active' : '' ?>">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                        <span>Teams</span>
                    </a>

                    <a href="/settings" class="nav-link <?= ($currentPage ?? '') === 'settings' ? 'is-active' : '' ?>">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>Settings</span>
                    </a>
                </div>
            </nav>

            <!-- Team Selector -->
            <div style="padding: 12px 10px; border-top: 1px solid var(--border)">
                <div class="row" style="gap: 10px; padding: 10px 12px; border-radius: 14px; border: 1px solid var(--border); background: var(--surface-strong)">
                    <div style="width:32px; height:32px; border-radius:999px; background: var(--accent); display:grid; place-items:center; color: #fff; font-weight:760; font-size:13px; flex: 0 0 auto;">
                        <?= substr($currentTeam['name'] ?? 'T', 0, 1) ?>
                    </div>
                    <div style="min-width:0">
                        <div style="font-size:13px; font-weight:650; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                            <?= htmlspecialchars($currentTeam['name'] ?? 'Personal Team') ?>
                        </div>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="main">
            <!-- Top Header -->
            <header class="topbar" role="banner">
                <div class="container container--fluid row row--between">
                <button class="btn btn--ghost btn--sm" type="button" data-action="sidebar-toggle" aria-label="Toggle sidebar">
                    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>

                <div class="row" style="gap: 12px">
                    <div class="theme-switch" role="group" aria-label="Theme">
                        <button type="button" data-theme-mode="auto" aria-pressed="false">Auto</button>
                        <button type="button" data-theme-mode="light" aria-pressed="false">Light</button>
                        <button type="button" data-theme-mode="dark" aria-pressed="false">Dark</button>
                    </div>

                    <!-- User Menu -->
                    <div style="position: relative">
                        <button class="btn btn--ghost btn--sm" type="button" data-action="user-menu-toggle" aria-expanded="false">
                            <div style="width: 30px; height: 30px; border-radius: 999px; overflow: hidden; border: 1px solid var(--border); background: var(--surface-strong); display:grid; place-items:center">
                                <?php if (!empty($user['avatar'])): ?>
                                    <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="" width="30" height="30">
                                <?php else: ?>
                                    <span style="font-size: 12px; font-weight: 760"><?= substr($user['name'] ?? 'U', 0, 1) ?></span>
                                <?php endif; ?>
                            </div>
                            <span style="font-size: 13px; max-width: 160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">
                                <?= htmlspecialchars($user['name'] ?? 'User') ?>
                            </span>
                            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <div class="menu" data-user-menu hidden>
                            <a href="/profile">Profile</a>
                            <a href="/activity">Activity Log</a>
                            <div style="height:1px; background: var(--border); margin: 6px 4px"></div>
                            <form action="/logout" method="POST">
                                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                <button type="submit" style="color: var(--danger)">Sign Out</button>
                            </form>
                        </div>
                    </div>
                </div>
                </div>
            </header>

            <!-- Page Content -->
            <main class="content" role="main">
                <div class="container container--fluid stack" style="gap: 14px">
                    <?php \Chap\View\View::partial('flash', ['flash' => $flash ?? []]); ?>
                    <?= $content ?? '' ?>
                </div>
            </main>
        </div>
    </div>

    <script>
        // CSRF token for AJAX requests
        window.csrfToken = '<?= csrf_token() ?>';
        // Helper function for API calls
        window.api = async function api(url, method = 'GET', data = null) {
            const options = {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.csrfToken
                }
            };
            if (data) options.body = JSON.stringify(data);
            const response = await fetch(url, options);
            return response.json();
        };
    </script>
</body>
</html>
