<div class="mb-8">
    <a href="/projects/<?= e($project->uuid) ?>" class="text-gray-400 hover:text-white inline-flex items-center mb-4">
        <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Back to <?= e($project->name) ?>
    </a>
    <div class="flex items-center justify-between">
        <div class="flex items-center space-x-4">
            <div class="w-14 h-14 bg-green-600/20 rounded-xl flex items-center justify-center">
                <svg class="w-7 h-7 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                </svg>
            </div>
            <div>
                <h1 class="text-3xl font-bold"><?= e($environment->name) ?></h1>
                <?php if (!empty($environment->description)): ?>
                    <p class="text-gray-400"><?= e($environment->description) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="flex items-center space-x-3">
            <a href="/environments/<?= e($environment->uuid) ?>/edit" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg transition-colors">
                Edit
            </a>
            <form action="/environments/<?= e($environment->uuid) ?>" method="POST" class="inline" onsubmit="event.preventDefault(); chapSwal({title: 'Delete Environment?', text: 'Are you sure? All applications will be deleted.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', cancelButtonText: 'Cancel'}).then((result) => { if(result.isConfirmed) this.submit(); }); return false;">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="_method" value="DELETE">
                <button type="submit" class="px-4 py-2 bg-red-600/20 hover:bg-red-600/30 text-red-400 rounded-lg transition-colors">
                    Delete
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Add New Section -->
<div class="mb-6 flex items-center space-x-4">
    <a href="/environments/<?= e($environment->uuid) ?>/applications/create" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors flex items-center space-x-2">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        <span>New Application</span>
    </a>
    <a href="/environments/<?= e($environment->uuid) ?>/databases/create" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg transition-colors flex items-center space-x-2">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
        </svg>
        <span>New Database</span>
    </a>
    <a href="/environments/<?= e($environment->uuid) ?>/services/create" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg transition-colors flex items-center space-x-2">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
        </svg>
        <span>New Service</span>
    </a>
</div>

<!-- Applications -->
<div class="mb-8">
    <h2 class="text-xl font-semibold mb-4">Applications</h2>
    
    <?php if (empty($applications)): ?>
        <div class="bg-gray-800 rounded-lg border border-gray-700 p-8 text-center">
            <p class="text-gray-400">No applications yet. Create your first application to get started.</p>
        </div>
    <?php else: ?>
        <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
            <table class="w-full">
                <thead>
                    <tr class="text-left text-sm text-gray-400 border-b border-gray-700 bg-gray-800/50">
                        <th class="px-6 py-4 font-medium">Application</th>
                        <th class="px-6 py-4 font-medium">Status</th>
                        <th class="px-6 py-4 font-medium">Branch</th>
                        <th class="px-6 py-4 font-medium">Last Deployed</th>
                        <th class="px-6 py-4 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700">
                    <?php foreach ($applications as $app): ?>
                        <tr class="hover:bg-gray-700/50">
                            <td class="px-6 py-4">
                                <a href="/applications/<?= e($app->uuid) ?>" class="font-medium hover:text-blue-400">
                                    <?= e($app->name) ?>
                                </a>
                            </td>
                            <td class="px-6 py-4">
                                <?php
                                $statusColors = [
                                    'running' => 'text-green-400 bg-green-400/10',
                                    'stopped' => 'text-gray-400 bg-gray-400/10',
                                    'deploying' => 'text-yellow-400 bg-yellow-400/10',
                                    'error' => 'text-red-400 bg-red-400/10',
                                ];
                                $colorClass = $statusColors[$app->status] ?? 'text-gray-400 bg-gray-400/10';
                                ?>
                                <span class="inline-flex px-2 py-1 text-xs rounded-full <?= $colorClass ?>">
                                    <?= ucfirst($app->status) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-gray-400 font-mono text-sm">
                                <?= e($app->git_branch) ?>
                            </td>
                            <td class="px-6 py-4 text-gray-400 text-sm">
                                <?php $latest = $app->latestDeployment(); ?>
                                <?= $latest ? time_ago($latest->created_at) : 'Never' ?>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center space-x-3">
                                    <a href="/applications/<?= e($app->uuid) ?>/logs" class="text-gray-400 hover:text-green-400 text-sm" title="Live Logs">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                        </svg>
                                    </a>
                                    <a href="/applications/<?= e($app->uuid) ?>" class="text-blue-400 hover:text-blue-300 text-sm">
                                        View →
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
