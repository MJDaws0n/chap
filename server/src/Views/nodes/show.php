<div class="mb-8">
    <a href="/nodes" class="text-gray-400 hover:text-white inline-flex items-center mb-4">
        <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
        </svg>
        Back to Nodes
    </a>
    <div class="flex items-center justify-between">
        <div class="flex items-center space-x-4">
            <div class="w-14 h-14 bg-purple-600/20 rounded-xl flex items-center justify-center">
                <svg class="w-7 h-7 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2"/>
                </svg>
            </div>
            <div>
                <h1 class="text-3xl font-bold"><?= e($node->name) ?></h1>
                <?php if (!empty($node->description)): ?>
                    <p class="text-gray-400"><?= e($node->description) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="flex items-center space-x-3">
            <?php
            $statusColors = [
                'online' => 'text-green-400 bg-green-400/10 border-green-400/30',
                'offline' => 'text-red-400 bg-red-400/10 border-red-400/30',
                'pending' => 'text-yellow-400 bg-yellow-400/10 border-yellow-400/30',
                'error' => 'text-red-400 bg-red-400/10 border-red-400/30',
            ];
            $status = $node->status ?? 'pending';
            $colorClass = $statusColors[$status] ?? 'text-gray-400 bg-gray-400/10 border-gray-400/30';
            ?>
            <span class="inline-flex px-3 py-1 text-sm rounded-full border <?= $colorClass ?>">
                <?= ucfirst($status) ?>
            </span>
            <form action="/nodes/<?= e($node->uuid) ?>" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this node?')">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="_method" value="DELETE">
                <button type="submit" class="px-4 py-2 bg-red-600/20 hover:bg-red-600/30 text-red-400 rounded-lg transition-colors">
                    Delete Node
                </button>
            </form>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <!-- Node Info Card -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 p-6">
        <h2 class="text-lg font-semibold mb-4">Node Information</h2>
        <dl class="space-y-4">
            <div>
                <dt class="text-gray-400 text-sm">UUID</dt>
                <dd class="font-mono text-sm mt-1"><?= e($node->uuid) ?></dd>
            </div>
            <div>
                <dt class="text-gray-400 text-sm">Agent Version</dt>
                <dd class="mt-1"><?= $node->agent_version ? e($node->agent_version) : '<span class="text-gray-600">Not connected</span>' ?></dd>
            </div>
            <div>
                <dt class="text-gray-400 text-sm">Last Seen</dt>
                <dd class="mt-1"><?= $node->last_seen_at ? time_ago($node->last_seen_at) : '<span class="text-gray-600">Never</span>' ?></dd>
            </div>
            <div>
                <dt class="text-gray-400 text-sm">Created</dt>
                <dd class="mt-1"><?= $node->created_at ? time_ago($node->created_at) : '-' ?></dd>
            </div>
        </dl>
    </div>

    <!-- Connection Token Card -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 p-6 lg:col-span-2">
        <h2 class="text-lg font-semibold mb-4">Connection Token</h2>
        <p class="text-gray-400 text-sm mb-4">Use this token to connect the Chap Node agent to this server.</p>
        
        <div class="bg-gray-900 rounded-lg p-4 font-mono text-sm break-all border border-gray-700">
            <code class="text-green-400"><?= e($node->token) ?></code>
        </div>
        
        <div class="mt-4">
            <h3 class="text-sm font-medium text-gray-300 mb-2">Install Command</h3>
            <div class="bg-gray-900 rounded-lg p-4 font-mono text-sm border border-gray-700">
                <code class="text-blue-400">curl -sSL https://get.chap.dev | bash -s -- --token=<?= e($node->token) ?></code>
            </div>
        </div>
    </div>
</div>

<!-- Containers Section -->
<div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-700 flex items-center justify-between">
        <h2 class="text-lg font-semibold">Containers</h2>
        <?php if ($node->status === 'online'): ?>
            <span class="text-sm text-gray-400">Live data from node</span>
        <?php endif; ?>
    </div>
    
    <?php if ($node->status !== 'online'): ?>
        <div class="p-12 text-center">
            <svg class="w-16 h-16 text-gray-600 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
            <h3 class="text-xl font-semibold mb-2">Node Offline</h3>
            <p class="text-gray-400">Connect this node to see running containers</p>
        </div>
    <?php else: ?>
        <div class="p-6">
            <p class="text-gray-400">Container list will appear here when the node agent reports data.</p>
        </div>
    <?php endif; ?>
</div>
