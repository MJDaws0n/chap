<?php
/**
 * Service Show View
 */
?>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold"><?= e($service->name) ?></h1>
            <p class="text-gray-400 mt-1"><?= e($service->template()->name ?? 'Custom Service') ?></p>
        </div>
        <div class="flex space-x-3">
            <a href="/services/<?= $service->uuid ?>/edit" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                Edit
            </a>
            <?php if ($service->status === 'running'): ?>
                <form method="POST" action="/services/<?= $service->uuid ?>/stop" class="inline">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-lg">
                        Stop
                    </button>
                </form>
                <form method="POST" action="/services/<?= $service->uuid ?>/restart" class="inline">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                        Restart
                    </button>
                </form>
            <?php else: ?>
                <form method="POST" action="/services/<?= $service->uuid ?>/start" class="inline">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                        Start
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Status Card -->
        <div class="bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4">Status</h2>
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-gray-400">Status</span>
                    <span class="px-2 py-1 text-xs rounded-full <?= $service->status === 'running' ? 'bg-green-600' : 'bg-gray-600' ?>">
                        <?= ucfirst($service->status) ?>
                    </span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-gray-400">Template</span>
                    <span><?= e($service->template()->name ?? 'Custom') ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-gray-400">Node</span>
                    <span><?= e($service->node()->name ?? 'Unknown') ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-gray-400">Environment</span>
                    <span><?= e($service->environment()->name ?? 'Unknown') ?></span>
                </div>
            </div>
        </div>

        <!-- URLs Card -->
        <div class="bg-gray-800 rounded-lg p-6 lg:col-span-2">
            <h2 class="text-lg font-semibold mb-4">Access URLs</h2>
            <?php if (!empty($service->fqdn)): ?>
                <div class="space-y-3">
                    <?php foreach (explode(',', $service->fqdn) as $url): ?>
                        <div class="flex items-center justify-between bg-gray-700 px-4 py-3 rounded-lg">
                            <a href="<?= e(trim($url)) ?>" target="_blank" class="text-blue-400 hover:text-blue-300">
                                <?= e(trim($url)) ?>
                            </a>
                            <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                            </svg>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-gray-400">No URLs configured. Add domains in the service settings.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Configuration Card -->
    <div class="bg-gray-800 rounded-lg p-6">
        <h2 class="text-lg font-semibold mb-4">Configuration</h2>
        <?php if (!empty($service->configuration)): ?>
            <?php $config = json_decode($service->configuration, true) ?? []; ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($config as $key => $value): ?>
                    <div class="bg-gray-700 px-4 py-3 rounded-lg">
                        <div class="text-sm text-gray-400"><?= e($key) ?></div>
                        <div class="font-mono text-sm"><?= e(is_array($value) ? json_encode($value) : $value) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-gray-400">No configuration set.</p>
        <?php endif; ?>
    </div>

    <!-- Danger Zone -->
    <div class="bg-gray-800 rounded-lg p-6 border border-red-600/30">
        <h2 class="text-lg font-semibold text-red-400 mb-4">Danger Zone</h2>
        <div class="flex items-center justify-between">
            <div>
                <p class="font-medium">Delete Service</p>
                <p class="text-sm text-gray-400">This will permanently delete the service and all its data.</p>
            </div>
            <form method="POST" action="/services/<?= $service->uuid ?>" onsubmit="return confirm('Are you sure? This cannot be undone.')">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="_method" value="DELETE">
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                    Delete Service
                </button>
            </form>
        </div>
    </div>
</div>
