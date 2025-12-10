<?php
/**
 * Git Source Show View
 */
$typeLabels = [
    'github' => 'GitHub',
    'gitlab' => 'GitLab',
    'bitbucket' => 'Bitbucket'
];
$typeLabel = $typeLabels[$gitSource->type] ?? ucfirst($gitSource->type);
?>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div class="flex items-center space-x-4">
            <?php if ($gitSource->type === 'github'): ?>
                <svg class="w-12 h-12" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
                </svg>
            <?php elseif ($gitSource->type === 'gitlab'): ?>
                <svg class="w-12 h-12 text-orange-500" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M22.65 14.39L12 22.13 1.35 14.39a.84.84 0 0 1-.3-.94l1.22-3.78 2.44-7.51A.42.42 0 0 1 4.82 2a.43.43 0 0 1 .58 0 .42.42 0 0 1 .11.18l2.44 7.49h8.1l2.44-7.51A.42.42 0 0 1 18.6 2a.43.43 0 0 1 .58 0 .42.42 0 0 1 .11.18l2.44 7.51L23 13.45a.84.84 0 0 1-.35.94z"/>
                </svg>
            <?php else: ?>
                <svg class="w-12 h-12 text-blue-500" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M.778 1.213a.768.768 0 00-.768.892l3.263 19.81c.084.5.515.868 1.022.873H19.95a.772.772 0 00.77-.646l3.27-20.03a.768.768 0 00-.768-.9zM14.52 15.53H9.522L8.17 8.466h7.561z"/>
                </svg>
            <?php endif; ?>
            <div>
                <h1 class="text-2xl font-bold"><?= e($gitSource->name) ?></h1>
                <p class="text-gray-400"><?= $typeLabel ?></p>
            </div>
        </div>
        <div class="flex space-x-3">
            <form method="POST" action="/git-sources/<?= $gitSource->uuid ?>/test" class="inline">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <button type="submit" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                    Test Connection
                </button>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Details Card -->
        <div class="bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4">Details</h2>
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-gray-400">Status</span>
                    <span class="px-2 py-1 text-xs rounded-full <?= $gitSource->status === 'connected' ? 'bg-green-600' : 'bg-gray-600' ?>">
                        <?= ucfirst($gitSource->status ?? 'pending') ?>
                    </span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-gray-400">Type</span>
                    <span><?= $typeLabel ?></span>
                </div>
                <?php if (!empty($gitSource->api_url)): ?>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">API URL</span>
                        <span class="text-sm"><?= e($gitSource->api_url) ?></span>
                    </div>
                <?php endif; ?>
                <div class="flex items-center justify-between">
                    <span class="text-gray-400">Scope</span>
                    <span><?= $gitSource->is_system_wide ? 'System-wide' : 'Team-specific' ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-gray-400">Created</span>
                    <span><?= date('M j, Y', strtotime($gitSource->created_at)) ?></span>
                </div>
            </div>
        </div>

        <!-- Usage Card -->
        <div class="bg-gray-800 rounded-lg p-6">
            <h2 class="text-lg font-semibold mb-4">Usage</h2>
            <div class="space-y-4">
                <p class="text-gray-400">This Git source is used by the following applications:</p>
                <?php if (!empty($applications)): ?>
                    <div class="space-y-2">
                        <?php foreach ($applications as $app): ?>
                            <a href="/applications/<?= $app->uuid ?>" class="block bg-gray-700 px-4 py-3 rounded-lg hover:bg-gray-600">
                                <?= e($app->name) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-gray-500">No applications are using this Git source yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Update Token -->
    <div class="bg-gray-800 rounded-lg p-6">
        <h2 class="text-lg font-semibold mb-4">Update Access Token</h2>
        <form method="POST" action="/git-sources/<?= $gitSource->uuid ?>" class="space-y-4">
            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="_method" value="PUT">
            <div class="max-w-md">
                <label for="access_token" class="block text-sm font-medium text-gray-300 mb-2">New Access Token</label>
                <input type="password" name="access_token" id="access_token"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    placeholder="Enter new token">
            </div>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                Update Token
            </button>
        </form>
    </div>

    <!-- Danger Zone -->
    <div class="bg-gray-800 rounded-lg p-6 border border-red-600/30">
        <h2 class="text-lg font-semibold text-red-400 mb-4">Danger Zone</h2>
        <div class="flex items-center justify-between">
            <div>
                <p class="font-medium">Delete Git Source</p>
                <p class="text-sm text-gray-400">This will disconnect the Git source. Existing deployments will not be affected.</p>
            </div>
            <form method="POST" action="/git-sources/<?= $gitSource->uuid ?>" onsubmit="return confirm('Are you sure you want to delete this Git source?')">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="_method" value="DELETE">
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                    Delete
                </button>
            </form>
        </div>
    </div>
</div>
