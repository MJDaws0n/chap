<div class="mb-8">
    <a href="/projects" class="text-gray-400 hover:text-white inline-flex items-center mb-4">
        <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Back to Projects
    </a>
    <div class="flex items-center justify-between">
        <div class="flex items-center space-x-4">
            <div class="w-14 h-14 bg-blue-600/20 rounded-xl flex items-center justify-center">
                <svg class="w-7 h-7 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                </svg>
            </div>
            <div>
                <h1 class="text-3xl font-bold"><?= e($project->name) ?></h1>
                <?php if (!empty($project->description)): ?>
                    <p class="text-gray-400"><?= e($project->description) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="flex items-center space-x-3">
            <a href="/projects/<?= e($project->uuid) ?>/edit" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg transition-colors">
                Edit
            </a>
            <form action="/projects/<?= e($project->uuid) ?>" method="POST" class="inline" onsubmit="event.preventDefault(); chapSwal({title: 'Delete Project?', text: 'Are you sure you want to delete this project? All environments and applications will be deleted.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', cancelButtonText: 'Cancel'}).then((result) => { if(result.isConfirmed) this.submit(); }); return false;">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="_method" value="DELETE">
                <button type="submit" class="px-4 py-2 bg-red-600/20 hover:bg-red-600/30 text-red-400 rounded-lg transition-colors">
                    Delete
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Environments Section -->
<div class="mb-6 flex items-center justify-between">
    <h2 class="text-xl font-semibold">Environments</h2>
    <a href="/projects/<?= e($project->uuid) ?>/environments/create" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors flex items-center space-x-2">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        <span>New Environment</span>
    </a>
</div>

<?php if (empty($environments)): ?>
    <div class="bg-gray-800 rounded-lg border border-gray-700 p-12 text-center">
        <svg class="w-16 h-16 text-gray-600 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
        </svg>
        <h3 class="text-xl font-semibold mb-2">No environments yet</h3>
        <p class="text-gray-400 mb-6">Create your first environment (e.g., Production, Staging, Development)</p>
        <a href="/projects/<?= e($project->uuid) ?>/environments/create" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
            <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Create Environment
        </a>
    </div>
<?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($environments as $environment): ?>
            <?php $apps = $environment->applications(); ?>
            <a href="/environments/<?= e($environment->uuid) ?>" class="bg-gray-800 rounded-lg border border-gray-700 p-6 hover:border-gray-600 transition-colors">
                <div class="flex items-start justify-between mb-4">
                    <div class="w-12 h-12 bg-green-600/20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                        </svg>
                    </div>
                    <span class="text-sm text-gray-500"><?= count($apps) ?> application<?= count($apps) !== 1 ? 's' : '' ?></span>
                </div>
                <h3 class="text-lg font-semibold mb-1"><?= e($environment->name) ?></h3>
                <?php if (!empty($environment->description)): ?>
                    <p class="text-gray-400 text-sm line-clamp-2"><?= e($environment->description) ?></p>
                <?php endif; ?>
                
                <?php if (!empty($apps)): ?>
                    <div class="mt-4 pt-4 border-t border-gray-700">
                        <div class="flex flex-wrap gap-2">
                            <?php foreach (array_slice($apps, 0, 3) as $app): ?>
                                <?php
                                $statusColors = [
                                    'running' => 'text-green-400 bg-green-400/10',
                                    'stopped' => 'text-gray-400 bg-gray-400/10',
                                    'deploying' => 'text-yellow-400 bg-yellow-400/10',
                                    'error' => 'text-red-400 bg-red-400/10',
                                ];
                                $colorClass = $statusColors[$app->status] ?? 'text-gray-400 bg-gray-400/10';
                                ?>
                                <span class="inline-flex items-center px-2 py-1 text-xs rounded <?= $colorClass ?>">
                                    <?= e($app->name) ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if (count($apps) > 3): ?>
                                <span class="text-xs text-gray-500">+<?= count($apps) - 3 ?> more</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Project Info Card -->
<div class="mt-8 bg-gray-800 rounded-lg border border-gray-700 p-6">
    <h2 class="text-lg font-semibold mb-4">Project Information</h2>
    <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <dt class="text-gray-400 text-sm">UUID</dt>
            <dd class="font-mono text-sm mt-1"><?= e($project->uuid) ?></dd>
        </div>
        <div>
            <dt class="text-gray-400 text-sm">Created</dt>
            <dd class="mt-1"><?= $project->created_at ? time_ago($project->created_at) : '-' ?></dd>
        </div>
        <div>
            <dt class="text-gray-400 text-sm">Environments</dt>
            <dd class="mt-1"><?= count($environments) ?></dd>
        </div>
        <div>
            <dt class="text-gray-400 text-sm">Total Applications</dt>
            <dd class="mt-1">
                <?php
                $totalApps = 0;
                foreach ($environments as $env) {
                    $totalApps += count($env->applications());
                }
                echo $totalApps;
                ?>
            </dd>
        </div>
    </dl>
</div>
