<?php
/**
 * Node Show View
 * Updated to use new design system
 */
$errors = $_SESSION['_errors'] ?? [];
$old = $_SESSION['_old_input'] ?? [];
$oldPortRanges = $old['port_ranges'] ?? null;
if ($oldPortRanges === null) {
    $oldPortRanges = [];
    foreach (($portRanges ?? []) as $r) {
        $s = (int)($r['start_port'] ?? 0);
        $e = (int)($r['end_port'] ?? 0);
        if ($s > 0 && $e > 0) {
            $oldPortRanges[] = ($s === $e) ? (string)$s : ($s . '-' . $e);
        }
    }
}
if (!is_array($oldPortRanges)) $oldPortRanges = [$oldPortRanges];
if (empty($oldPortRanges)) $oldPortRanges = [''];

$statusBadge = [
    'online' => 'badge-success',
    'offline' => 'badge-danger',
    'pending' => 'badge-warning',
    'error' => 'badge-danger',
][$node->status ?? 'pending'] ?? 'badge-default';
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <nav class="breadcrumb">
                    <span class="breadcrumb-item"><a href="/admin/nodes">Nodes</a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current"><?= e($node->name) ?></span>
                </nav>

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-purple icon-box-lg">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"></path>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="page-header-title truncate"><?= e($node->name) ?></h1>
                        <?php if (!empty($node->description)): ?>
                            <p class="page-header-description truncate"><?= e($node->description) ?></p>
                        <?php else: ?>
                            <p class="page-header-description">Node details and connection info</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="page-header-actions">
                <span class="badge <?= $statusBadge ?>"><?= ucfirst($node->status ?? 'pending') ?></span>
                <form action="/admin/nodes/<?= e($node->uuid) ?>" method="POST" class="inline-block" id="delete-node-form">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="button" class="btn btn-danger-ghost" id="delete-node-btn">Delete Node</button>
                </form>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Node Info Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Node Information</h2>
            </div>
            <div class="card-body">
                <dl class="flex flex-col gap-4">
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <dt class="text-tertiary">UUID</dt>
                        <dd class="m-0"><code class="break-all"><?= e($node->uuid) ?></code></dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <dt class="text-tertiary">Agent Version</dt>
                        <dd class="m-0"><?= $node->agent_version ? e($node->agent_version) : '<span class="text-secondary">Not connected</span>' ?></dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <dt class="text-tertiary">Live Logs WebSocket</dt>
                        <dd class="m-0 break-all">
                            <?= $node->logs_websocket_url
                                ? '<code>' . e($node->logs_websocket_url) . '</code>'
                                : '<span class="text-secondary">Not configured (using polling)</span>' ?>
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <dt class="text-tertiary">Node API URL</dt>
                        <dd class="m-0 break-all">
                            <?= $node->api_url
                                ? '<code>' . e($node->api_url) . '</code>'
                                : '<span class="text-secondary">Not configured</span>' ?>
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <dt class="text-tertiary">Last Seen</dt>
                        <dd class="m-0"><?= $node->last_seen_at ? time_ago($node->last_seen_at) : '<span class="text-secondary">Never</span>' ?></dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <dt class="text-tertiary">Created</dt>
                        <dd class="m-0"><?= $node->created_at ? time_ago($node->created_at) : '-' ?></dd>
                    </div>
                </dl>
            </div>

            <!-- Edit Node Form -->
            <div class="card-footer">
                <form method="POST" action="/admin/nodes/<?= e($node->uuid) ?>">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="_method" value="PUT">
                    
                    <div class="flex flex-col gap-4">
                        <div class="form-group">
                            <label for="description" class="form-label">Description</label>
                            <input 
                                type="text" 
                                id="description" 
                                name="description" 
                                class="input"
                                placeholder="Production server in AWS us-east-1"
                                value="<?= e($node->description ?? '') ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label for="logs_websocket_url" class="form-label">Live Logs WebSocket URL</label>
                            <input 
                                type="text" 
                                id="logs_websocket_url" 
                                name="logs_websocket_url" 
                                class="input"
                                placeholder="wss://node.example.com:6002"
                                value="<?= e($node->logs_websocket_url ?? '') ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label for="api_url" class="form-label">Node API URL</label>
                            <input
                                type="text"
                                id="api_url"
                                name="api_url"
                                class="input"
                                placeholder="https://node.example.com:6002"
                                value="<?= e($node->api_url ?? '') ?>"
                            >
                            <p class="text-xs text-tertiary mt-2">Base URL used for client Node API calls (e.g. /node/v2/health).</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Port Ranges</label>
                            <div id="port-ranges" class="flex flex-col gap-2">
                                <?php foreach ($oldPortRanges as $v): ?>
                                    <input
                                        type="text"
                                        name="port_ranges[]"
                                        class="input"
                                        placeholder="3000-3999 or 25565"
                                        value="<?= e((string)$v) ?>"
                                    >
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-2">
                                <button type="button" class="btn btn-ghost btn-sm" id="add-port-range">+ Add range</button>
                            </div>
                            <p class="text-xs text-tertiary mt-2">Only these ports can be auto-allocated on this node. Use a single port (e.g. 25565) or range (e.g. 3000–3999).</p>
                            <?php if (!empty($errors['port_ranges'])): ?>
                                <p class="text-sm text-red mt-2"><?= e($errors['port_ranges']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="flex items-center justify-start">
                            <button type="submit" class="btn btn-primary">Update</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Connection Token Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Connection Token</h2>
            </div>
            <div class="card-body">
                <p class="text-secondary text-sm mb-4">Use this token to connect the Chap Node agent to this server.</p>

                <div class="bg-tertiary border border-primary rounded-lg p-4">
                    <p class="text-xs text-tertiary mb-2">Token</p>
                    <code class="break-all"><?= e($node->token) ?></code>
                </div>

                <div class="mt-4">
                    <p class="text-xs text-tertiary mb-2">Install Command</p>
                    <pre class="break-all"><code>curl -sSL https://get.chap.dev | bash -s -- --token=<?= e($node->token) ?></code></pre>
                </div>
            </div>
        </div>
    </div>

    <!-- Containers Section -->
    <div class="card mt-6">
        <div class="card-header">
            <h2 class="card-title">Containers</h2>
            <?php if ($node->status === 'online'): ?>
                <span class="text-secondary text-sm">Live data from node</span>
            <?php endif; ?>
        </div>
        
        <?php if ($node->status !== 'online'): ?>
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                    </div>
                    <p class="empty-state-title">Node Offline</p>
                    <p class="empty-state-description">Connect this node to see running containers</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card-body">
                <p class="text-secondary">Container list will appear here when the node agent reports data.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('delete-node-btn').addEventListener('click', function() {
    Modal.confirmDelete('Are you sure you want to delete this node?')
        .then(confirmed => {
            if (confirmed) {
                document.getElementById('delete-node-form').submit();
            }
        });
});

(function() {
    const rangesEl = document.getElementById('port-ranges');
    const addRangeBtn = document.getElementById('add-port-range');
    if (!rangesEl || !addRangeBtn) return;

    addRangeBtn.addEventListener('click', function() {
        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'port_ranges[]';
        input.className = 'input';
        input.placeholder = '3000-3999 or 25565';
        rangesEl.appendChild(input);
        input.focus();
    });
})();
</script>
