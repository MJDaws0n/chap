<?php
/**
 * Admin Activity Logs
 */
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-header-title">Activity Logs</h1>
                <p class="page-header-description">View activity for all users</p>
            </div>
        </div>

        <div class="page-header-actions mt-4" style="display:flex; gap: 12px; flex-wrap: wrap;">
            <form method="GET" action="/admin/activity" class="flex items-center gap-3">
                <label class="text-sm text-secondary" for="user_id">User</label>
                <div style="min-width: 260px;">
                    <select class="select" id="user_id" name="user_id" data-search="true" data-search-placeholder="Search users...">
                        <option value="">All users</option>
                        <?php foreach (($users ?? []) as $u): ?>
                            <option value="<?= (int)$u->id ?>" <?= (!empty($selectedUserId) && (int)$selectedUserId === (int)$u->id) ? 'selected' : '' ?>>
                                <?= e(($u->name ?: $u->username) . ' — ' . $u->email) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-secondary">Filter</button>
                <a href="/admin/activity" class="btn btn-ghost">Reset</a>
            </form>
        </div>
    </div>

    <?php if (empty($rows)): ?>
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12,6 12,12 16,14"></polyline>
                        </svg>
                    </div>
                    <p class="empty-state-title">No activity found</p>
                    <p class="empty-state-description">Try adjusting your filters.</p>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Recent Activity</h2>
            </div>

            <div class="card-body p-0">
                <?php foreach ($rows as $row): ?>
                    <?php
                    $actionText = (string)($row['action'] ?? '');
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
                    $props = [];
                    if (!empty($row['properties'])) {
                        $decoded = json_decode((string)$row['properties'], true);
                        $props = is_array($decoded) ? $decoded : [];
                    }
                    $userLabel = (string)($row['user_name'] ?: ($row['user_username'] ?: 'System'));
                    $teamLabel = (string)($row['team_name'] ?? '');
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
                                <?php if (!empty($row['subject_type'])): ?>
                                    <span class="text-secondary font-normal"> on </span>
                                    <span class="text-blue font-normal"><?= e((string)$row['subject_type']) ?></span>
                                <?php endif; ?>
                            </p>

                            <p class="text-sm text-secondary mt-1 truncate">
                                <?= e($userLabel) ?>
                                <?php if (!empty($row['user_email'])): ?>
                                    <span class="text-tertiary">·</span> <?= e((string)$row['user_email']) ?>
                                <?php endif; ?>
                                <?php if ($teamLabel !== ''): ?>
                                    <span class="text-tertiary">·</span> <?= e($teamLabel) ?>
                                <?php endif; ?>
                            </p>

                            <?php if (!empty($props)): ?>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <?php foreach ($props as $key => $value): ?>
                                        <span class="badge badge-neutral badge-sm">
                                            <?= e((string)$key) ?>: <?= e(is_array($value) ? json_encode($value) : (string)$value) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <p class="text-xs text-tertiary mt-2">
                                <?= !empty($row['created_at']) ? e(time_ago((string)$row['created_at'])) : '-' ?>
                                <?php if (!empty($row['ip_address'])): ?>
                                    · <?= e((string)$row['ip_address']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card-body flex items-center justify-between">
                <div class="text-sm text-secondary">
                    <?= (int)($total ?? 0) ?> total
                </div>
                <div class="flex gap-2">
                    <?php $page = (int)($page ?? 1); ?>
                    <?php $queryUser = !empty($selectedUserId) ? ('&user_id=' . (int)$selectedUserId) : ''; ?>
                    <?php if ($page > 1): ?>
                        <a class="btn btn-ghost btn-sm" href="/admin/activity?page=<?= $page - 1 ?><?= $queryUser ?>">Previous</a>
                    <?php endif; ?>
                    <?php if (!empty($hasMore)): ?>
                        <a class="btn btn-ghost btn-sm" href="/admin/activity?page=<?= $page + 1 ?><?= $queryUser ?>">Next</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
