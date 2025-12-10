<div class="mb-8 flex items-center justify-between">
    <div>
        <h1 class="text-3xl font-bold">Projects</h1>
        <p class="text-gray-400">Organize your applications into projects</p>
    </div>
    <a href="/projects/create" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors flex items-center space-x-2">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        <span>New Project</span>
    </a>
</div>

<?php if (empty($projects)): ?>
    <div class="bg-gray-800 rounded-lg border border-gray-700 p-12 text-center">
        <svg class="w-16 h-16 text-gray-600 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
        </svg>
        <h3 class="text-xl font-semibold mb-2">No projects yet</h3>
        <p class="text-gray-400 mb-6">Create your first project to start deploying applications</p>
        <a href="/projects/create" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
            <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Create Project
        </a>
    </div>
<?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($projects as $project): ?>
            <a href="/projects/<?= e($project->uuid) ?>" class="bg-gray-800 rounded-lg border border-gray-700 p-6 hover:border-gray-600 transition-colors">
                <div class="flex items-start justify-between mb-4">
                    <div class="w-12 h-12 bg-blue-600/20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                        </svg>
                    </div>
                    <span class="text-sm text-gray-500"><?= count($project->environments()) ?> environments</span>
                </div>
                <h3 class="text-lg font-semibold mb-1"><?= e($project->name) ?></h3>
                <?php if (!empty($project->description)): ?>
                    <p class="text-gray-400 text-sm line-clamp-2"><?= e($project->description) ?></p>
                <?php endif; ?>
                <div class="mt-4 pt-4 border-t border-gray-700 flex items-center text-sm text-gray-500">
                    <span>Created <?= time_ago($project->created_at) ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
