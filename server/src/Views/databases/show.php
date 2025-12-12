<?php
/**
 * Database Show View
 */
$typeLabels = [
    'mysql' => 'MySQL',
    'postgresql' => 'PostgreSQL',
    'mariadb' => 'MariaDB',
    'redis' => 'Redis',
    'mongodb' => 'MongoDB'
];
$typeLabel = $typeLabels[$database->type] ?? ucfirst($database->type);
?>
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold"><?= e($database->name) ?></h1>
            <p class="text-gray-400 mt-1"><?= $typeLabel ?> Database</p>
        </div>
        <div class="flex space-x-3">
            <a href="/databases/<?= $database->uuid ?>/edit" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                Edit
            </a>
            <?php if ($database->status === 'running'): ?>
                <form method="POST" action="/databases/<?= $database->uuid ?>/stop" class="inline">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-lg">
                        Stop
                    </button>
                </form>
            <?php else: ?>
                <form method="POST" action="/databases/<?= $database->uuid ?>/start" class="inline">
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
                    <span class="px-2 py-1 text-xs rounded-full <?= $database->status === 'running' ? 'bg-green-600' : 'bg-gray-600' ?>">
                        <?= ucfirst($database->status) ?>
                    </span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-gray-400">Type</span>
                    <span><?= $typeLabel ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-gray-400">Version</span>
                    <span><?= e($database->version ?? 'latest') ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-gray-400">Node</span>
                    <span><?= e($database->node()->name ?? 'Unknown') ?></span>
                </div>
            </div>
        </div>

        <!-- Connection Info Card -->
        <div class="bg-gray-800 rounded-lg p-6 lg:col-span-2">
            <h2 class="text-lg font-semibold mb-4">Connection Details</h2>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm text-gray-400 mb-1">Host</label>
                    <div class="flex items-center space-x-2">
                        <code class="flex-1 bg-gray-700 px-3 py-2 rounded"><?= e($database->node()->name ?? 'localhost') ?></code>
                        <button onclick="copyToClipboard('<?= e($database->node()->name ?? 'localhost') ?>')" class="text-gray-400 hover:text-white">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Port</label>
                        <code class="block bg-gray-700 px-3 py-2 rounded"><?= e($database->external_port ?? $database->internal_port ?? '3306') ?></code>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Database</label>
                        <code class="block bg-gray-700 px-3 py-2 rounded"><?= e($database->db_name ?? 'app') ?></code>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Username</label>
                        <code class="block bg-gray-700 px-3 py-2 rounded"><?= e($database->db_user ?? 'admin') ?></code>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-400 mb-1">Password</label>
                        <div class="flex items-center space-x-2">
                            <code class="flex-1 bg-gray-700 px-3 py-2 rounded" id="db-password">••••••••</code>
                            <button onclick="togglePassword()" class="text-gray-400 hover:text-white">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Danger Zone -->
    <div class="bg-gray-800 rounded-lg p-6 border border-red-600/30">
        <h2 class="text-lg font-semibold text-red-400 mb-4">Danger Zone</h2>
        <div class="flex items-center justify-between">
            <div>
                <p class="font-medium">Delete Database</p>
                <p class="text-sm text-gray-400">This will permanently delete the database and all its data.</p>
            </div>
            <form method="POST" action="/databases/<?= $database->uuid ?>" onsubmit="event.preventDefault(); chapSwal({title: 'Are you sure?', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', cancelButtonText: 'Cancel'}).then((result) => { if(result.isConfirmed) this.submit(); }); return false;">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="_method" value="DELETE">
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                    Delete Database
                </button>
            </form>
        </div>
    </div>
</div>

<script>
let passwordVisible = false;
const realPassword = '<?= e($database->db_password ?? '') ?>';

function togglePassword() {
    const el = document.getElementById('db-password');
    passwordVisible = !passwordVisible;
    el.textContent = passwordVisible ? realPassword : '••••••••';
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text);
}
</script>
