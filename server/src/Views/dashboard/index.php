<div class="mb-8">
    <h1 class="text-3xl font-bold">Dashboard</h1>
    <p class="text-gray-400">Welcome back, <?= htmlspecialchars($user['name'] ?? 'User') ?>!</p>
</div>

<!-- Stats Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-400 text-sm">Projects</p>
                <p class="text-3xl font-bold"><?= $stats['projects'] ?? 0 ?></p>
            </div>
            <div class="w-12 h-12 bg-blue-600/20 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                </svg>
            </div>
        </div>
    </div>

    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-400 text-sm">Applications</p>
                <p class="text-3xl font-bold"><?= $stats['applications'] ?? 0 ?></p>
            </div>
            <div class="w-12 h-12 bg-green-600/20 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
        </div>
    </div>

    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-400 text-sm">Nodes</p>
                <p class="text-3xl font-bold"><?= $stats['nodes'] ?? 0 ?></p>
            </div>
            <div class="w-12 h-12 bg-purple-600/20 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
                </svg>
            </div>
        </div>
    </div>

    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-400 text-sm">Deployments</p>
                <p class="text-3xl font-bold"><?= $stats['deployments'] ?? 0 ?></p>
            </div>
            <div class="w-12 h-12 bg-orange-600/20 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                </svg>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity & Quick Actions -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Recent Deployments -->
    <div class="bg-gray-800 rounded-lg border border-gray-700">
        <div class="px-6 py-4 border-b border-gray-700 flex items-center justify-between">
            <h2 class="text-lg font-semibold">Recent Deployments</h2>
            <a href="/deployments" class="text-sm text-blue-500 hover:text-blue-400">View all</a>
        </div>
        <div class="divide-y divide-gray-700">
            <?php if (empty($recentDeployments)): ?>
                <div class="px-6 py-8 text-center text-gray-400">
                    <p>No deployments yet</p>
                    <a href="/projects" class="text-blue-500 hover:text-blue-400 text-sm">Create your first application</a>
                </div>
            <?php else: ?>
                <?php foreach ($recentDeployments as $deployment): ?>
                    <a href="/deployments/<?= $deployment['id'] ?>" class="block px-6 py-4 hover:bg-gray-700/50 transition-colors">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="font-medium"><?= htmlspecialchars($deployment['application_name'] ?? 'Unknown') ?></p>
                                <p class="text-sm text-gray-400">
                                    <?= htmlspecialchars(substr($deployment['git_commit_sha'] ?? '', 0, 7)) ?>
                                    <?php if (!empty($deployment['git_branch'])): ?>
                                        on <?= htmlspecialchars($deployment['git_branch']) ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <?php
                                $statusColors = [
                                    'running' => 'text-green-400 bg-green-400/10',
                                    'deploying' => 'text-blue-400 bg-blue-400/10',
                                    'building' => 'text-yellow-400 bg-yellow-400/10',
                                    'failed' => 'text-red-400 bg-red-400/10',
                                    'queued' => 'text-gray-400 bg-gray-400/10',
                                ];
                                $status = $deployment['status'] ?? 'queued';
                                $colorClass = $statusColors[$status] ?? 'text-gray-400 bg-gray-400/10';
                                ?>
                                <span class="inline-flex px-2 py-1 text-xs rounded-full <?= $colorClass ?>">
                                    <?= ucfirst($status) ?>
                                </span>
                                <p class="text-xs text-gray-500 mt-1"><?= time_ago($deployment['created_at'] ?? '') ?></p>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="bg-gray-800 rounded-lg border border-gray-700">
        <div class="px-6 py-4 border-b border-gray-700">
            <h2 class="text-lg font-semibold">Quick Actions</h2>
        </div>
        <div class="p-6 space-y-3">
            <a href="/projects/create" class="flex items-center space-x-3 p-4 bg-gray-700/50 rounded-lg hover:bg-gray-700 transition-colors">
                <div class="w-10 h-10 bg-blue-600/20 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                </div>
                <div>
                    <p class="font-medium">New Project</p>
                    <p class="text-sm text-gray-400">Create a new project to organize your apps</p>
                </div>
            </a>

            <a href="/nodes/create" class="flex items-center space-x-3 p-4 bg-gray-700/50 rounded-lg hover:bg-gray-700 transition-colors">
                <div class="w-10 h-10 bg-purple-600/20 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2"/>
                    </svg>
                </div>
                <div>
                    <p class="font-medium">Add Node</p>
                    <p class="text-sm text-gray-400">Connect a new server to deploy applications</p>
                </div>
            </a>

            <a href="/templates" class="flex items-center space-x-3 p-4 bg-gray-700/50 rounded-lg hover:bg-gray-700 transition-colors">
                <div class="w-10 h-10 bg-green-600/20 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5z"/>
                    </svg>
                </div>
                <div>
                    <p class="font-medium">Browse Templates</p>
                    <p class="text-sm text-gray-400">Deploy one-click services and applications</p>
                </div>
            </a>
        </div>
    </div>
</div>

<!-- Nodes Status -->
<?php if (!empty($nodes)): ?>
<div class="mt-6 bg-gray-800 rounded-lg border border-gray-700">
    <div class="px-6 py-4 border-b border-gray-700 flex items-center justify-between">
        <h2 class="text-lg font-semibold">Node Status</h2>
        <a href="/nodes" class="text-sm text-blue-500 hover:text-blue-400">Manage nodes</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="text-left text-sm text-gray-400 border-b border-gray-700">
                    <th class="px-6 py-3 font-medium">Node</th>
                    <th class="px-6 py-3 font-medium">Status</th>
                    <th class="px-6 py-3 font-medium">Agent</th>
                    <th class="px-6 py-3 font-medium">Last Seen</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-700">
                <?php foreach ($nodes as $node): ?>
                    <tr class="hover:bg-gray-700/50">
                        <td class="px-6 py-4">
                            <div>
                                <a href="/nodes/<?= e($node->uuid) ?>" class="font-medium hover:text-blue-400"><?= e($node->name) ?></a>
                                <?php if ($node->description): ?>
                                    <p class="text-sm text-gray-400"><?= e($node->description) ?></p>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <?php
                            $nodeStatusColors = [
                                'online' => 'text-green-400 bg-green-400/10',
                                'offline' => 'text-red-400 bg-red-400/10',
                                'pending' => 'text-yellow-400 bg-yellow-400/10',
                            ];
                            $nodeStatus = $node->status ?? 'pending';
                            $nodeColorClass = $nodeStatusColors[$nodeStatus] ?? 'text-gray-400 bg-gray-400/10';
                            ?>
                            <span class="inline-flex px-2 py-1 text-xs rounded-full <?= $nodeColorClass ?>">
                                <?= ucfirst($nodeStatus) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-gray-400 text-sm">
                            <?= $node->agent_version ? e($node->agent_version) : '-' ?>
                        </td>
                        <td class="px-6 py-4 text-gray-400 text-sm">
                            <?= $node->last_seen_at ? time_ago($node->last_seen_at) : 'Never' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
