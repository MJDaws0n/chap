<div class="mb-8">
    <h1 class="text-3xl font-bold">Activity Log</h1>
    <p class="text-gray-400">Recent actions in your team</p>
</div>

<?php if (empty($activity)): ?>
    <div class="bg-gray-800 rounded-lg border border-gray-700 p-12 text-center">
        <svg class="w-16 h-16 text-gray-600 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <h3 class="text-xl font-semibold mb-2">No activity yet</h3>
        <p class="text-gray-400">Actions in your team will appear here</p>
    </div>
<?php else: ?>
    <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
        <div class="divide-y divide-gray-700">
            <?php foreach ($activity as $item): ?>
                <div class="px-6 py-4 hover:bg-gray-700/50">
                    <div class="flex items-start space-x-4">
                        <div class="w-10 h-10 bg-blue-600/20 rounded-full flex items-center justify-center flex-shrink-0">
                            <?php
                            $actionIcon = match(true) {
                                str_contains($item->action, 'created') => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>',
                                str_contains($item->action, 'updated') => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>',
                                str_contains($item->action, 'deleted') => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>',
                                str_contains($item->action, 'deploy') => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>',
                                default => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'
                            };
                            ?>
                            <svg class="w-5 h-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <?= $actionIcon ?>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm">
                                <span class="font-medium"><?= e($item->action) ?></span>
                                <?php if ($item->subject_type): ?>
                                    <span class="text-gray-400">on</span>
                                    <span class="text-blue-400"><?= e($item->subject_type) ?></span>
                                <?php endif; ?>
                            </p>
                            <?php 
                            $props = $item->getProperties();
                            if (!empty($props)):
                            ?>
                                <p class="text-sm text-gray-400 mt-1">
                                    <?php foreach ($props as $key => $value): ?>
                                        <span class="mr-4"><?= e($key) ?>: <?= e(is_array($value) ? json_encode($value) : $value) ?></span>
                                    <?php endforeach; ?>
                                </p>
                            <?php endif; ?>
                            <p class="text-xs text-gray-500 mt-1">
                                <?= time_ago($item->created_at) ?>
                                <?php if ($item->ip_address): ?>
                                    · <?= e($item->ip_address) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($page > 1 || $hasMore): ?>
        <div class="mt-6 flex items-center justify-between">
            <?php if ($page > 1): ?>
                <a href="/activity?page=<?= $page - 1 ?>" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg transition-colors">
                    ← Previous
                </a>
            <?php else: ?>
                <div></div>
            <?php endif; ?>

            <?php if ($hasMore): ?>
                <a href="/activity?page=<?= $page + 1 ?>" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg transition-colors">
                    Next →
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
