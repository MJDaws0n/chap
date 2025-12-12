<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?? 'Chap' ?> | Chap</title>
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="/js/chapSwal.js"></script>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen">
    <div class="flex min-h-screen" x-data="{ sidebarOpen: true }">
        <!-- Sidebar -->
        <aside 
            class="w-64 bg-gray-800 border-r border-gray-700 flex flex-col transition-all duration-300"
            :class="{ '-ml-64': !sidebarOpen }"
        >
            <!-- Logo -->
            <div class="h-16 flex items-center px-6 border-b border-gray-700">
                <a href="/dashboard" class="flex items-center space-x-2">
                    <svg class="w-8 h-8 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                    </svg>
                    <span class="text-xl font-bold">Chap</span>
                </a>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 px-4 py-6 space-y-2">
                <a href="/dashboard" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 <?= ($currentPage ?? '') === 'dashboard' ? 'bg-gray-700 text-white' : 'text-gray-400' ?>">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    <span>Dashboard</span>
                </a>

                <a href="/projects" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 <?= ($currentPage ?? '') === 'projects' ? 'bg-gray-700 text-white' : 'text-gray-400' ?>">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                    </svg>
                    <span>Projects</span>
                </a>

                <a href="/nodes" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 <?= ($currentPage ?? '') === 'nodes' ? 'bg-gray-700 text-white' : 'text-gray-400' ?>">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
                    </svg>
                    <span>Nodes</span>
                </a>

                <a href="/templates" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 <?= ($currentPage ?? '') === 'templates' ? 'bg-gray-700 text-white' : 'text-gray-400' ?>">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/>
                    </svg>
                    <span>Templates</span>
                </a>

                <div class="pt-4 mt-4 border-t border-gray-700">
                    <a href="/teams" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 <?= ($currentPage ?? '') === 'teams' ? 'bg-gray-700 text-white' : 'text-gray-400' ?>">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                        <span>Teams</span>
                    </a>

                    <a href="/settings" class="flex items-center space-x-3 px-3 py-2 rounded-lg hover:bg-gray-700 <?= ($currentPage ?? '') === 'settings' ? 'bg-gray-700 text-white' : 'text-gray-400' ?>">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>Settings</span>
                    </a>
                </div>
            </nav>

            <!-- Team Selector -->
            <div class="p-4 border-t border-gray-700">
                <div class="flex items-center space-x-3 px-3 py-2 bg-gray-700 rounded-lg">
                    <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-sm font-bold">
                        <?= substr($currentTeam['name'] ?? 'T', 0, 1) ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium truncate"><?= htmlspecialchars($currentTeam['name'] ?? 'Personal Team') ?></p>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
            <!-- Top Header -->
            <header class="h-16 bg-gray-800 border-b border-gray-700 flex items-center justify-between px-6">
                <button @click="sidebarOpen = !sidebarOpen" class="text-gray-400 hover:text-white">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>

                <div class="flex items-center space-x-4">
                    <!-- User Menu -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="flex items-center space-x-2">
                            <div class="w-8 h-8 bg-gray-600 rounded-full flex items-center justify-center">
                                <?php if (!empty($user['avatar'])): ?>
                                    <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="" class="w-8 h-8 rounded-full">
                                <?php else: ?>
                                    <span class="text-sm font-bold"><?= substr($user['name'] ?? 'U', 0, 1) ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="text-sm"><?= htmlspecialchars($user['name'] ?? 'User') ?></span>
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <div 
                            x-show="open" 
                            @click.away="open = false"
                            x-cloak
                            class="absolute right-0 mt-2 w-48 bg-gray-800 border border-gray-700 rounded-lg shadow-lg py-1 z-50"
                        >
                            <a href="/profile" class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700">Profile</a>
                            <a href="/activity" class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-700">Activity Log</a>
                            <hr class="my-1 border-gray-700">
                            <form action="/logout" method="POST" class="block">
                                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-400 hover:bg-gray-700">Sign Out</button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 p-6">
                <?php if (!empty($flash['success'])): ?>
                    <div class="mb-4 bg-green-900/50 border border-green-600 text-green-300 px-4 py-3 rounded-lg">
                        <?= htmlspecialchars($flash['success']) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($flash['error'])): ?>
                    <div class="mb-4 bg-red-900/50 border border-red-600 text-red-300 px-4 py-3 rounded-lg">
                        <?= htmlspecialchars($flash['error']) ?>
                    </div>
                <?php endif; ?>

                <?= $content ?? '' ?>
            </main>
        </div>
    </div>

    <script>
        // CSRF token for AJAX requests
        window.csrfToken = '<?= csrf_token() ?>';
        // Helper function for API calls
        async function api(url, method = 'GET', data = null) {
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
        }
    </script>
    <!-- SweetAlert2 and Chap custom theme -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js"></script>
    <script src="/js/chapSwal.js"></script>
</body>
</html>
