<!-- Page Header -->
<div class="page-header">
    <div class="page-header-top">
        <div>
            <h1 class="page-header-title">Dashboard</h1>
            <p class="page-header-description">Welcome back, <?= htmlspecialchars($user['name'] ?? 'User') ?>!</p>
        </div>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid mb-8">
    <div class="stat-card">
        <div class="stat-card-header">
            <div>
                <p class="stat-label">Projects</p>
                <p class="stat-value"><?= $stats['projects'] ?? 0 ?></p>
            </div>
            <div class="icon-box icon-box-blue">
                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                </svg>
            </div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-card-header">
            <div>
                <p class="stat-label">Applications</p>
                <p class="stat-value"><?= $stats['applications'] ?? 0 ?></p>
            </div>
            <div class="icon-box icon-box-green">
                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-card-header">
            <div>
                <p class="stat-label">Nodes</p>
                <p class="stat-value"><?= $stats['nodes'] ?? 0 ?></p>
            </div>
            <div class="icon-box icon-box-purple">
                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
                </svg>
            </div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-card-header">
            <div>
                <p class="stat-label">Deployments</p>
                <p class="stat-value"><?= $stats['deployments'] ?? 0 ?></p>
            </div>
            <div class="icon-box icon-box-orange">
                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                </svg>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity & Quick Actions -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Recent Deployments -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Recent Deployments</h2>
        </div>
        
        <?php if (empty($recentDeployments)): ?>
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                    </div>
                    <p class="empty-state-title">No deployments yet</p>
                    <p class="empty-state-description">Create your first application to get started</p>
                    <a href="/projects" class="btn btn-primary btn-sm">Create Application</a>
                </div>
            </div>
        <?php else: ?>
            <div class="card-body p-0">
                <?php foreach ($recentDeployments as $deployment): ?>
                    <a href="/deployments/<?= $deployment['id'] ?>" class="flex items-center justify-between px-6 py-4 border-b border-primary hover:bg-tertiary transition-colors">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium truncate"><?= htmlspecialchars($deployment['application_name'] ?? 'Unknown') ?></p>
                            <p class="text-sm text-secondary truncate">
                                <?= htmlspecialchars(substr($deployment['git_commit_sha'] ?? '', 0, 7)) ?>
                                <?php if (!empty($deployment['git_branch'])): ?>
                                    on <?= htmlspecialchars($deployment['git_branch']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="text-right ml-4 flex-shrink-0">
                            <?php
                            $status = $deployment['status'] ?? 'queued';
                            $badgeClass = match($status) {
                                'running' => 'badge-success',
                                'deploying' => 'badge-info',
                                'building' => 'badge-warning',
                                'failed' => 'badge-danger',
                                default => 'badge-default'
                            };
                            ?>
                            <span class="badge <?= $badgeClass ?>"><?= ucfirst($status) ?></span>
                            <p class="text-xs text-tertiary mt-1"><?= time_ago($deployment['created_at'] ?? '') ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quick Actions -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Quick Actions</h2>
        </div>
        <div class="card-body">
            <div class="flex flex-col gap-3">
                <a href="/projects/create" class="flex items-center gap-4 p-4 bg-tertiary rounded-lg hover:bg-secondary transition-colors">
                    <div class="icon-box icon-box-blue">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">New Project</p>
                        <p class="text-sm text-secondary truncate">Create a new project to organize your apps</p>
                    </div>
                </a>

                <a href="/nodes/create" class="flex items-center gap-4 p-4 bg-tertiary rounded-lg hover:bg-secondary transition-colors">
                    <div class="icon-box icon-box-purple">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2"/>
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">Add Node</p>
                        <p class="text-sm text-secondary truncate">Connect a new server to deploy applications</p>
                    </div>
                </a>

                <a href="/templates" class="flex items-center gap-4 p-4 bg-tertiary rounded-lg hover:bg-secondary transition-colors">
                    <div class="icon-box icon-box-green">
                        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5z"/>
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">Browse Templates</p>
                        <p class="text-sm text-secondary truncate">Deploy one-click services and applications</p>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Nodes Status -->
<?php if (!empty($nodes)): ?>
<div class="card mt-6">
    <div class="card-header">
        <h2 class="card-title">Node Status</h2>
        <a href="/nodes" class="text-sm text-blue">Manage nodes</a>
    </div>
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Node</th>
                    <th>Status</th>
                    <th>Agent</th>
                    <th>Last Seen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($nodes as $node): ?>
                    <tr>
                        <td>
                            <div>
                                <a href="/nodes/<?= e($node->uuid) ?>" class="font-medium text-blue"><?= e($node->name) ?></a>
                                <?php if ($node->description): ?>
                                    <p class="text-sm text-secondary truncate"><?= e($node->description) ?></p>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php
                            $nodeStatus = $node->status ?? 'pending';
                            $badgeClass = match($nodeStatus) {
                                'online' => 'badge-success',
                                'offline' => 'badge-danger',
                                default => 'badge-warning'
                            };
                            ?>
                            <span class="badge <?= $badgeClass ?>"><?= ucfirst($nodeStatus) ?></span>
                        </td>
                        <td class="text-secondary text-sm">
                            <?= $node->agent_version ? e($node->agent_version) : '-' ?>
                        </td>
                        <td class="text-secondary text-sm">
                            <?= $node->last_seen_at ? time_ago($node->last_seen_at) : 'Never' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
