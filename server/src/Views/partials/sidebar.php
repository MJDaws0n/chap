<?php
/**
 * Sidebar Partial
 * Main application navigation sidebar
 * 
 * Variables:
 * - $currentPage: Current page identifier for active state
 * - $currentTeam: Current team data array
 */
?>
<aside class="sidebar">
    <!-- Logo -->
    <div class="sidebar-header">
        <a href="/dashboard" class="sidebar-logo">
            <svg class="sidebar-logo-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
            </svg>
            <span>Chap</span>
        </a>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">
        <!-- Main Section -->
        <div class="sidebar-section">
            <p class="sidebar-section-title">Main</p>
            
            <a href="/dashboard" class="sidebar-link <?= ($currentPage ?? '') === 'dashboard' ? 'active' : '' ?>">
                <svg class="sidebar-link-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                <span>Dashboard</span>
            </a>

            <a href="/projects" class="sidebar-link <?= ($currentPage ?? '') === 'projects' ? 'active' : '' ?>">
                <svg class="sidebar-link-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                </svg>
                <span>Projects</span>
            </a>

            <a href="/templates" class="sidebar-link <?= ($currentPage ?? '') === 'templates' ? 'active' : '' ?>">
                <svg class="sidebar-link-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/>
                </svg>
                <span>Templates</span>
            </a>
        </div>

        <!-- Management Section -->
        <div class="sidebar-section">
            <p class="sidebar-section-title">Management</p>

            <a href="/teams" class="sidebar-link <?= ($currentPage ?? '') === 'teams' ? 'active' : '' ?>">
                <svg class="sidebar-link-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
                <span>Teams</span>
            </a>

            <a href="/git-sources" class="sidebar-link <?= ($currentPage ?? '') === 'git-sources' ? 'active' : '' ?>">
                <svg class="sidebar-link-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 18a4 4 0 00-8 0m8 0a4 4 0 01-8 0m8 0v-5a4 4 0 00-8 0v5m4-11V3m0 4h.01"/>
                </svg>
                <span>Git Sources</span>
            </a>

            <a href="/activity" class="sidebar-link <?= ($currentPage ?? '') === 'activity' ? 'active' : '' ?>">
                <svg class="sidebar-link-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Activity Log</span>
            </a>

            <a href="/settings" class="sidebar-link <?= ($currentPage ?? '') === 'settings' ? 'active' : '' ?>">
                <svg class="sidebar-link-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span>Settings</span>
            </a>
        </div>

        <?php if (!empty($user['is_admin'])): ?>
            <div class="sidebar-section">
                <p class="sidebar-section-title">Admin</p>

                <a href="/nodes" class="sidebar-link <?= ($currentPage ?? '') === 'nodes' ? 'active' : '' ?>">
                    <svg class="sidebar-link-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
                    </svg>
                    <span>Nodes</span>
                </a>

                <a href="/admin/users" class="sidebar-link <?= str_starts_with(($currentPage ?? ''), 'admin-users') ? 'active' : '' ?>">
                    <svg class="sidebar-link-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                    <span>Users</span>
                </a>

                <a href="/admin/settings/email" class="sidebar-link <?= str_starts_with(($currentPage ?? ''), 'admin-settings') ? 'active' : '' ?>">
                    <svg class="sidebar-link-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8m-18 8h18a2 2 0 002-2V8a2 2 0 00-2-2H3a2 2 0 00-2 2v6a2 2 0 002 2z"/>
                    </svg>
                    <span>Email</span>
                </a>

                <a href="/admin/activity" class="sidebar-link <?= str_starts_with(($currentPage ?? ''), 'admin-activity') ? 'active' : '' ?>">
                    <svg class="sidebar-link-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>Logs</span>
                </a>
            </div>
        <?php endif; ?>
    </nav>

    <!-- Team Selector -->
    <div class="sidebar-footer">
        <div class="sidebar-team">
            <div class="sidebar-team-avatar">
                <?= substr($currentTeam['name'] ?? 'T', 0, 1) ?>
            </div>
            <span class="sidebar-team-name"><?= htmlspecialchars($currentTeam['name'] ?? 'Personal Team') ?></span>
        </div>
    </div>
</aside>

<!-- Mobile Overlay -->
<div class="sidebar-overlay"></div>
