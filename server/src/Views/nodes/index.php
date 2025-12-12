<div class="mb-8 flex items-center justify-between">
    <div>
        <h1 class="text-3xl font-bold">Nodes</h1>
        <p class="text-gray-400">Servers where your applications run</p>
    </div>
    <a href="/nodes/create" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors flex items-center space-x-2">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        <span>Add Node</span>
    </a>
</div>

<?php if (empty($nodes)): ?>
    <div class="bg-gray-800 rounded-lg border border-gray-700 p-12 text-center">
        <svg class="w-16 h-16 text-gray-600 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
        </svg>
        <h3 class="text-xl font-semibold mb-2">No nodes connected</h3>
        <p class="text-gray-400 mb-6">Add your first server to start deploying applications</p>
        <a href="/nodes/create" class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
            <svg class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add Node
        </a>
    </div>
<?php else: ?>
    <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
        <table class="w-full">
            <thead>
                <tr class="text-left text-sm text-gray-400 border-b border-gray-700 bg-gray-800/50">
                    <th class="px-6 py-4 font-medium">Node</th>
                    <th class="px-6 py-4 font-medium">Status</th>
                    <th class="px-6 py-4 font-medium">Agent Version</th>
                    <th class="px-6 py-4 font-medium">Last Seen</th>
                    <th class="px-6 py-4 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-700">
                <?php foreach ($nodes as $node): ?>
                    <tr class="hover:bg-gray-700/50 cursor-pointer group" onclick="window.location='/nodes/<?= e($node->uuid) ?>'">
                        <td class="px-6 py-4">
                            <div class="flex items-center space-x-3">
                                <div class="w-10 h-10 bg-purple-600/20 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2"/>
                                    </svg>
                                </div>
                                <div>
                                    <span class="font-medium group-hover:text-blue-400 transition-colors"><?= e($node->name) ?></span>
                                    <?php if (!empty($node->description)): ?>
                                        <p class="text-sm text-gray-500"><?= e($node->description) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <?php
                            $statusColors = [
                                'online' => 'text-green-400 bg-green-400/10',
                                'offline' => 'text-red-400 bg-red-400/10',
                                'pending' => 'text-yellow-400 bg-yellow-400/10',
                                'error' => 'text-red-400 bg-red-400/10',
                            ];
                            $status = $node->status ?? 'pending';
                            $colorClass = $statusColors[$status] ?? 'text-gray-400 bg-gray-400/10';
                            ?>
                            <span class="inline-flex px-2 py-1 text-xs rounded-full <?= $colorClass ?>">
                                <?= ucfirst($status) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-gray-400 font-mono text-sm">
                            <?= $node->agent_version ? e($node->agent_version) : '<span class="text-gray-600">-</span>' ?>
                        </td>
                        <td class="px-6 py-4 text-gray-400 text-sm">
                            <?= $node->last_seen_at ? time_ago($node->last_seen_at) : 'Never' ?>
                        </td>
                        <td class="px-6 py-4">
                            <form action="/nodes/<?= e($node->uuid) ?>" method="POST" class="inline" onsubmit="event.stopPropagation(); return confirm('Are you sure you want to delete this node?')">
                                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="_method" value="DELETE">
                                <button type="submit" class="text-gray-400 hover:text-red-400" title="Delete" onclick="event.stopPropagation();">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
