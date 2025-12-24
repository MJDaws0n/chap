<?php
/**
 * Activity Index View
 * Updated to use new design system
 */
?>

<div class="page-header">
    <div class="page-header-top">
        <div>
            <h1 class="page-header-title">Activity Log</h1>
            <p class="page-header-description">Recent actions in your team</p>
        </div>
    </div>
</div>

<?php if (empty($activity)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <div class="empty-state-icon">
                    <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12,6 12,12 16,14"></polyline>
                    </svg>
                </div>
                <p class="empty-state-title">No activity yet</p>
                <p class="empty-state-description">Actions in your team will appear here.</p>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Recent Activity</h2>
        </div>

        <div class="card-body p-0">
            <?php foreach ($activity as $item): ?>
                <?php
                $actionText = (string)($item->action ?? '');
                $action = strtolower($actionText);
                $iconPath = match(true) {
                    str_contains($action, 'created') => '<line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line>',
                    str_contains($action, 'updated') => '<path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"></path>',
                    str_contains($action, 'deleted') => '<polyline points="3,6 5,6 21,6"></polyline><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"></path>',
                    str_contains($action, 'deploy') => '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"></path><polyline points="17,8 12,3 7,8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line>',
                    default => '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>',
                };
                $iconBoxClass = match(true) {
                    str_contains($action, 'deleted') => 'icon-box-red',
                    str_contains($action, 'created') => 'icon-box-green',
                    str_contains($action, 'updated') => 'icon-box-blue',
                    str_contains($action, 'deploy') => 'icon-box-orange',
                    default => 'icon-box-purple',
                };
                $props = $item->getProperties();
                ?>

                <div class="flex items-start gap-4 px-6 py-4 border-b border-primary">
                    <div class="icon-box icon-box-sm <?= $iconBoxClass ?> flex-shrink-0">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <?= $iconPath ?>
                        </svg>
                    </div>

                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-primary truncate">
                            <?= e($actionText) ?>
                            <?php if (!empty($item->subject_type)): ?>
                                <span class="text-secondary font-normal"> on </span>
                                <span class="text-blue font-normal"><?= e($item->subject_type) ?></span>
                            <?php endif; ?>
                        </p>

                        <?php if (!empty($props)): ?>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <?php foreach ($props as $key => $value): ?>
                                    <span class="badge badge-neutral badge-sm">
                                        <?= e($key) ?>: <?= e(is_array($value) ? json_encode($value) : $value) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <p class="text-xs text-tertiary mt-2">
                            <?= time_ago($item->created_at) ?>
                            <?php if (!empty($item->ip_address)): ?>
                                · <?= e($item->ip_address) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($page > 1 || $hasMore): ?>
        <div class="flex items-center justify-between mt-6">
            <?php if ($page > 1): ?>
                <a href="/activity?page=<?= $page - 1 ?>" class="btn btn-secondary">← Previous</a>
            <?php else: ?>
                <div></div>
            <?php endif; ?>

            <?php if ($hasMore): ?>
                <a href="/activity?page=<?= $page + 1 ?>" class="btn btn-secondary">Next →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
