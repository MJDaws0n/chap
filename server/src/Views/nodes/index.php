<?php
/**
 * Nodes Index View
 * Updated to use new design system
 */
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-header-title">Nodes</h1>
                <p class="page-header-description">Servers where your applications run</p>
            </div>
            <?php if (!empty($isAdmin)): ?>
                <div class="page-header-actions">
                    <a href="/admin/nodes/create" class="btn btn-primary">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                        Add Node
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($nodes)): ?>
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
                        </svg>
                    </div>
                    <p class="empty-state-title">No nodes connected</p>
                    <p class="empty-state-description">Add your first server to start deploying applications</p>
                    <?php if (!empty($isAdmin)): ?>
                        <a href="/admin/nodes/create" class="btn btn-primary btn-sm">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                            </svg>
                            Add Node
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="table-container">
                <table class="table table-clickable">
                    <thead>
                        <tr>
                            <th>Node</th>
                            <th>Status</th>
                            <th>Agent Version</th>
                            <th>Last Seen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($nodes as $node): ?>
                            <?php
                            $statusBadge = [
                                'online' => 'badge-success',
                                'offline' => 'badge-danger',
                                'pending' => 'badge-warning',
                                'error' => 'badge-danger',
                            ][$node->status ?? 'pending'] ?? 'badge-default';
                            ?>
                            <tr onclick="window.location='/admin/nodes/<?= e($node->uuid) ?>'">
                                <td>
                                    <div class="flex items-center gap-4">
                                        <div class="icon-box icon-box-purple">
                                            <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"></path>
                                            </svg>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-lg font-semibold text-primary truncate"><?= e($node->name) ?></p>
                                            <?php if (!empty($node->description)): ?>
                                                <p class="text-secondary text-sm truncate"><?= e($node->description) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?= $statusBadge ?>">
                                        <?= ucfirst($node->status ?? 'pending') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($node->agent_version): ?>
                                        <code><?= e($node->agent_version) ?></code>
                                    <?php else: ?>
                                        <span class="text-secondary">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-secondary">
                                    <?= $node->last_seen_at ? time_ago($node->last_seen_at) : 'Never' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
