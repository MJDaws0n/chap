<?php
/**
 * Header Partial
 * Top navigation header for app layout
 * 
 * Variables:
 * - $user: Current user data array
 */
?>
<header class="header">
    <div class="header-left">
        <!-- Mobile menu button -->
        <button type="button" class="header-menu-btn" aria-label="Toggle sidebar">
            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>
    </div>

    <div class="header-right">
        <!-- Theme Toggle -->
        <div class="theme-toggle">
            <button type="button" class="theme-toggle-btn" data-theme="light" aria-label="Light mode">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="5"></circle>
                    <line x1="12" y1="1" x2="12" y2="3"></line>
                    <line x1="12" y1="21" x2="12" y2="23"></line>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                    <line x1="1" y1="12" x2="3" y2="12"></line>
                    <line x1="21" y1="12" x2="23" y2="12"></line>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                </svg>
            </button>
            <button type="button" class="theme-toggle-btn" data-theme="dark" aria-label="Dark mode">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                </svg>
            </button>
            <button type="button" class="theme-toggle-btn" data-theme="system" aria-label="System preference">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                    <line x1="8" y1="21" x2="16" y2="21"></line>
                    <line x1="12" y1="17" x2="12" y2="21"></line>
                </svg>
            </button>
        </div>

        <!-- User Menu -->
        <div class="user-menu dropdown">
            <button type="button" class="user-menu-trigger" data-dropdown-trigger="user-dropdown" aria-expanded="false">
                <div class="avatar avatar-sm">
                    <?php if (!empty($user['avatar'])): ?>
                        <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="<?= htmlspecialchars($user['name'] ?? 'User') ?>">
                    <?php else: ?>
                        <span><?= substr($user['name'] ?? 'U', 0, 1) ?></span>
                    <?php endif; ?>
                </div>
                <span class="user-menu-name md:block hidden"><?= htmlspecialchars($user['name'] ?? 'User') ?></span>
                <svg class="user-menu-chevron" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>

            <div id="user-dropdown" class="dropdown-menu">
                <?php if (!empty($user['is_admin'])): ?>
                    <div class="dropdown-header">View Mode</div>
                    <form action="/admin/view-mode" method="POST">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="mode" value="personal">
                        <button type="submit" class="dropdown-item">
                            <svg class="dropdown-item-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                            Personal
                            <?php if (isset($adminViewAll) && $adminViewAll === false): ?>
                                <span class="badge badge-success" style="margin-left: auto;">Active</span>
                            <?php endif; ?>
                        </button>
                    </form>
                    <form action="/admin/view-mode" method="POST">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="mode" value="all">
                        <button type="submit" class="dropdown-item">
                            <svg class="dropdown-item-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                            </svg>
                            View All
                            <?php if (isset($adminViewAll) && $adminViewAll === true): ?>
                                <span class="badge badge-success" style="margin-left: auto;">Active</span>
                            <?php endif; ?>
                        </button>
                    </form>
                    <div class="dropdown-divider"></div>
                <?php endif; ?>
                <a href="/profile" class="dropdown-item">
                    <svg class="dropdown-item-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                    Profile
                </a>
                <a href="/activity" class="dropdown-item">
                    <svg class="dropdown-item-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Activity Log
                </a>
                <div class="dropdown-divider"></div>
                <form action="/logout" method="POST">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" class="dropdown-item dropdown-item-danger">
                        <svg class="dropdown-item-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                        Sign Out
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
