<?php
/**
 * Deployment Show View
 */
$statusColors = [
    'queued' => 'bg-gray-600',
    'building' => 'bg-yellow-600',
    'deploying' => 'bg-blue-600',
    'running' => 'bg-green-600',
    'success' => 'bg-green-600',
    'failed' => 'bg-red-600',
    'cancelled' => 'bg-gray-600',
];
$statusColor = $statusColors[$deployment->status] ?? 'bg-gray-600';
$isInProgress = in_array($deployment->status, ['queued', 'building', 'deploying']);
?>
<div class="space-y-6">
    <!-- Breadcrumb & Header -->
    <div class="flex items-center justify-between">
        <div>
            <div class="flex items-center space-x-2 text-sm text-gray-400 mb-2">
                <a href="/projects" class="hover:text-white">Projects</a>
                <span>/</span>
                <a href="/projects/<?= $project->uuid ?>" class="hover:text-white"><?= e($project->name) ?></a>
                <span>/</span>
                <a href="/environments/<?= $environment->uuid ?>" class="hover:text-white"><?= e($environment->name) ?></a>
                <span>/</span>
                <a href="/applications/<?= $application->uuid ?>" class="hover:text-white"><?= e($application->name) ?></a>
                <span>/</span>
                <span>Deployment</span>
            </div>
            <div class="flex items-center space-x-3">
                <h1 class="text-2xl font-bold">Deployment</h1>
                <span class="px-2 py-1 text-xs rounded-full <?= $statusColor ?>">
                    <?= ucfirst($deployment->status) ?>
                </span>
            </div>
            <p class="text-gray-400 mt-1">
                <?= e($deployment->commit_message ?? 'Manual deployment') ?>
                <?php if ($deployment->commit_sha): ?>
                    • <code class="text-sm"><?= substr($deployment->commit_sha, 0, 7) ?></code>
                <?php endif; ?>
            </p>
        </div>
        <div class="flex space-x-3">
            <a href="/applications/<?= $application->uuid ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                ← Back to Application
            </a>
            <?php if ($isInProgress): ?>
                <form method="POST" action="/deployments/<?= $deployment->uuid ?>/cancel" class="inline">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                        Cancel
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <!-- Logs -->
        <div class="lg:col-span-3">
            <div class="bg-gray-800 rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold">Build Logs</h2>
                    <?php if ($isInProgress): ?>
                        <div class="flex items-center space-x-2 text-sm text-gray-400">
                            <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                            <span>Live</span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div id="logs-container" class="bg-gray-900 rounded-lg p-4 h-[600px] overflow-y-auto font-mono text-sm">
                    <?php 
                    $logs = $deployment->logsArray();
                    if (empty($logs)):
                    ?>
                        <p class="text-gray-500">Waiting for logs...</p>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            $logClass = 'text-gray-300';
                            if (str_contains(strtolower($log['message'] ?? ''), 'error')) {
                                $logClass = 'text-red-400';
                            } elseif (str_contains(strtolower($log['message'] ?? ''), 'warning')) {
                                $logClass = 'text-yellow-400';
                            } elseif (str_contains(strtolower($log['message'] ?? ''), 'success')) {
                                $logClass = 'text-green-400';
                            }
                            ?>
                            <div class="flex items-start space-x-2 py-1 <?= $logClass ?>">
                                <span class="text-gray-500 flex-shrink-0"><?= e($log['timestamp'] ?? '') ?></span>
                                <span class="whitespace-pre-wrap"><?= e($log['message'] ?? '') ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Details Card -->
            <div class="bg-gray-800 rounded-lg p-6">
                <h2 class="text-lg font-semibold mb-4">Details</h2>
                <div class="space-y-4 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">Status</span>
                        <span class="px-2 py-1 text-xs rounded-full <?= $statusColor ?>">
                            <?= ucfirst($deployment->status) ?>
                        </span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">Started</span>
                        <span><?= time_ago($deployment->created_at) ?></span>
                    </div>
                    <?php if ($deployment->finished_at): ?>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Finished</span>
                            <span><?= time_ago($deployment->finished_at) ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Duration</span>
                            <span>
                                <?php
                                $start = strtotime($deployment->created_at);
                                $end = strtotime($deployment->finished_at);
                                $duration = $end - $start;
                                if ($duration < 60) {
                                    echo $duration . 's';
                                } else {
                                    echo floor($duration / 60) . 'm ' . ($duration % 60) . 's';
                                }
                                ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">Application</span>
                        <a href="/applications/<?= $application->uuid ?>" class="text-blue-400 hover:text-blue-300">
                            <?= e($application->name) ?>
                        </a>
                    </div>
                    <?php if ($deployment->commit_sha): ?>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Commit</span>
                            <code class="text-xs bg-gray-700 px-2 py-1 rounded"><?= substr($deployment->commit_sha, 0, 7) ?></code>
                        </div>
                    <?php endif; ?>
                    <!-- <?php if ($deployment->triggered_by): ?> -->
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Triggered by</span>
                            <span> Not implemented </span>
                            <!-- <span><?= e($deployment->triggered_by) ?></span> -->
                        </div>
                    <!-- <?php endif; ?> -->
                </div>
            </div>

            <!-- Actions Card -->
            <div class="bg-gray-800 rounded-lg p-6">
                <h2 class="text-lg font-semibold mb-4">Actions</h2>
                <div class="space-y-3">
                    <form method="POST" action="/applications/<?= $application->uuid ?>/deploy">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                            Redeploy
                        </button>
                    </form>
                    <?php if ($deployment->status === 'success' || $deployment->status === 'running'): ?>
                        <form method="POST" action="/applications/<?= $application->uuid ?>/stop">
                            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                            <button type="submit" class="w-full bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                                Stop Application
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($isInProgress): ?>
<script>
// Auto-refresh logs while deployment is in progress
const logsContainer = document.getElementById('logs-container');
let lastLogCount = <?= count($logs) ?>;

async function fetchLogs() {
    try {
        const response = await fetch('/deployments/<?= $deployment->uuid ?>/logs');
        const data = await response.json();
        
        console.log('Fetched logs:', data); // Debug log
        
        if (data.logs && data.logs.length !== lastLogCount) {
            // Clear existing logs
            logsContainer.innerHTML = '';
            
            if (data.logs.length === 0) {
                logsContainer.innerHTML = '<p class="text-gray-500">Waiting for logs...</p>';
            } else {
                // Add all logs
                data.logs.forEach(log => {
                    const div = document.createElement('div');
                    div.className = 'flex items-start space-x-2 py-1';
                    
                    let logClass = 'text-gray-300';
                    const message = (log.message || '').toLowerCase();
                    if (message.includes('error') || message.includes('failed')) {
                        logClass = 'text-red-400';
                    } else if (message.includes('warning')) {
                        logClass = 'text-yellow-400';
                    } else if (message.includes('success') || message.includes('completed') || message.includes('✓') || message.includes('✅')) {
                        logClass = 'text-green-400';
                    }
                    div.classList.add(logClass);
                    
                    div.innerHTML = `
                        <span class="text-gray-500 flex-shrink-0">${escapeHtml(log.timestamp || '')}</span>
                        <span class="whitespace-pre-wrap">${escapeHtml(log.message || '')}</span>
                    `;
                    logsContainer.appendChild(div);
                });
            }
            
            lastLogCount = data.logs.length;
            logsContainer.scrollTop = logsContainer.scrollHeight;
        }
        
        // Check if deployment is complete
        if (data.status && !['queued', 'building', 'deploying'].includes(data.status)) {
            // Reload page to update status
            setTimeout(() => window.location.reload(), 1000);
        }
    } catch (error) {
        console.error('Failed to fetch logs:', error);
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initial fetch
fetchLogs();

// Poll every 2 seconds
setInterval(fetchLogs, 2000);

// Auto-scroll to bottom
logsContainer.scrollTop = logsContainer.scrollHeight;
</script>
<?php endif; ?>
