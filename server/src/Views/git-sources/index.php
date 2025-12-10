<?php
/**
 * Git Sources Index View
 */
?>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold">Git Sources</h1>
            <p class="text-gray-400 mt-1">Connect your Git providers to deploy repositories</p>
        </div>
        <a href="/git-sources/create" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
            Add Git Source
        </a>
    </div>

    <?php if (empty($gitSources)): ?>
        <div class="bg-gray-800 rounded-lg p-12 text-center">
            <svg class="w-16 h-16 text-gray-600 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
            </svg>
            <h3 class="text-xl font-semibold mb-2">No Git sources configured</h3>
            <p class="text-gray-400 mb-6">Connect GitHub, GitLab, or Bitbucket to deploy from your repositories</p>
            <a href="/git-sources/create" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg inline-block">
                Add Your First Git Source
            </a>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($gitSources as $source): ?>
                <a href="/git-sources/<?= $source->uuid ?>" class="bg-gray-800 rounded-lg p-6 hover:bg-gray-750 transition block">
                    <div class="flex items-center space-x-4 mb-4">
                        <?php if ($source->type === 'github'): ?>
                            <svg class="w-10 h-10" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
                            </svg>
                        <?php elseif ($source->type === 'gitlab'): ?>
                            <svg class="w-10 h-10 text-orange-500" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M22.65 14.39L12 22.13 1.35 14.39a.84.84 0 0 1-.3-.94l1.22-3.78 2.44-7.51A.42.42 0 0 1 4.82 2a.43.43 0 0 1 .58 0 .42.42 0 0 1 .11.18l2.44 7.49h8.1l2.44-7.51A.42.42 0 0 1 18.6 2a.43.43 0 0 1 .58 0 .42.42 0 0 1 .11.18l2.44 7.51L23 13.45a.84.84 0 0 1-.35.94z"/>
                            </svg>
                        <?php else: ?>
                            <svg class="w-10 h-10 text-blue-500" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M.778 1.213a.768.768 0 00-.768.892l3.263 19.81c.084.5.515.868 1.022.873H19.95a.772.772 0 00.77-.646l3.27-20.03a.768.768 0 00-.768-.9zM14.52 15.53H9.522L8.17 8.466h7.561z"/>
                            </svg>
                        <?php endif; ?>
                        <div>
                            <h3 class="font-semibold"><?= e($source->name) ?></h3>
                            <p class="text-sm text-gray-400"><?= ucfirst($source->type) ?></p>
                        </div>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-gray-400">
                            <?php if ($source->is_system_wide): ?>
                                <span class="text-blue-400">System-wide</span>
                            <?php else: ?>
                                Team-specific
                            <?php endif; ?>
                        </span>
                        <span class="px-2 py-1 text-xs rounded-full <?= $source->status === 'connected' ? 'bg-green-600' : 'bg-gray-600' ?>">
                            <?= ucfirst($source->status ?? 'pending') ?>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
