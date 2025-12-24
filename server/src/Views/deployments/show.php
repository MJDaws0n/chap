<?php
/**
 * Deployment Show View
 * Updated to use new design system
 */
$statusBadge = [
    'queued' => 'badge-secondary',
    'building' => 'badge-warning',
    'deploying' => 'badge-primary',
    'running' => 'badge-success',
    'success' => 'badge-success',
    'failed' => 'badge-danger',
    'cancelled' => 'badge-secondary',
][$deployment->status] ?? 'badge-secondary';

$isInProgress = in_array($deployment->status, ['queued', 'building', 'deploying']);
?>

<div class="flex flex-col gap-6">
    <!-- Breadcrumb & Header -->
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <nav class="breadcrumb">
                    <span class="breadcrumb-item">
                        <a href="/projects">Projects</a>
                    </span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-item">
                        <a href="/projects/<?= e($project->uuid) ?>"><?= e($project->name) ?></a>
                    </span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-item">
                        <a href="/environments/<?= e($environment->uuid) ?>"><?= e($environment->name) ?></a>
                    </span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-item">
                        <a href="/applications/<?= e($application->uuid) ?>"><?= e($application->name) ?></a>
                    </span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current">Deployment</span>
                </nav>

                <div class="flex items-center flex-wrap gap-3 mt-4">
                    <h1 class="page-header-title">Deployment</h1>
                    <span class="badge <?= $statusBadge ?>"><?= ucfirst($deployment->status) ?></span>
                </div>
                <p class="page-header-description">
                    <?= e($deployment->commit_message ?? 'Manual deployment') ?>
                    <?php if ($deployment->commit_sha): ?>
                        • <code class="code-inline"><?= substr($deployment->commit_sha, 0, 7) ?></code>
                    <?php endif; ?>
                </p>
            </div>

            <div class="page-header-actions">
                <a href="/applications/<?= e($application->uuid) ?>" class="btn btn-secondary">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Back to Application
                </a>
                <?php if ($isInProgress): ?>
                    <form method="POST" action="/deployments/<?= e($deployment->uuid) ?>/cancel" class="inline">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <button type="submit" class="btn btn-danger">Cancel</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3">
        <!-- Logs -->
        <div class="lg:col-span-2 min-w-0">
            <div class="card">
                <div class="card-header">
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <h2 class="card-title">Build Logs</h2>
                        <?php if ($isInProgress): ?>
                            <div class="live-indicator">
                                <span class="live-dot"></span>
                                <span>Live</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="logs-container" id="logs-container">
                        <?php
                        $logs = $deployment->logsArray();
                        if (empty($logs)):
                        ?>
                            <p class="text-secondary text-sm">Waiting for logs...</p>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <?php
                                $logClass = 'log-info';
                                $msg = strtolower($log['message'] ?? '');
                                if (str_contains($msg, 'error') || str_contains($msg, 'failed') || str_contains($msg, 'exception')) {
                                    $logClass = 'log-error';
                                } elseif (str_contains($msg, 'warning') || str_contains($msg, 'warn')) {
                                    $logClass = 'log-warning';
                                } elseif (str_contains($msg, 'success') || str_contains($msg, 'completed')) {
                                    $logClass = 'log-success';
                                }
                                ?>
                                <div class="log-entry <?= $logClass ?>">
                                    <span class="log-timestamp"><?= e($log['timestamp'] ?? '') ?></span>
                                    <span class="log-message"><?= e($log['message'] ?? '') ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="flex flex-col gap-6">
            <!-- Details Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Details</h3>
                </div>
                <div class="card-body">
                    <dl class="flex flex-col gap-4">
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-tertiary">Status</dt>
                            <dd class="m-0"><span class="badge <?= $statusBadge ?>"><?= ucfirst($deployment->status) ?></span></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-tertiary">Started</dt>
                            <dd class="m-0 text-primary"><?= time_ago($deployment->created_at) ?></dd>
                        </div>
                        <?php if ($deployment->finished_at): ?>
                            <div class="flex items-center justify-between gap-4 text-sm">
                                <dt class="text-tertiary">Finished</dt>
                                <dd class="m-0 text-primary"><?= time_ago($deployment->finished_at) ?></dd>
                            </div>
                            <div class="flex items-center justify-between gap-4 text-sm">
                                <dt class="text-tertiary">Duration</dt>
                                <dd class="m-0 text-primary">
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
                                </dd>
                            </div>
                        <?php endif; ?>
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-tertiary">Application</dt>
                            <dd class="m-0"><a href="/applications/<?= e($application->uuid) ?>" class="link"><?= e($application->name) ?></a></dd>
                        </div>
                        <?php if ($deployment->commit_sha): ?>
                            <div class="flex items-center justify-between gap-4 text-sm">
                                <dt class="text-tertiary">Commit</dt>
                                <dd class="m-0"><code class="code-inline"><?= substr($deployment->commit_sha, 0, 7) ?></code></dd>
                            </div>
                        <?php endif; ?>
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-tertiary">Triggered by</dt>
                            <?php $triggerLabel = $deployment->triggered_by_name ?: ($deployment->triggered_by ?: 'Manual'); ?>
                            <dd class="m-0 text-primary"><?= e($triggerLabel) ?></dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Actions Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Actions</h3>
                </div>
                <div class="card-body">
                    <div class="flex flex-col gap-3">
                        <form method="POST" action="/applications/<?= e($application->uuid) ?>/deploy">
                            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                            <button type="submit" class="btn btn-primary w-full" <?= $isInProgress ? 'disabled aria-disabled="true"' : '' ?>>
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                                <?= $isInProgress ? 'Redeploying…' : 'Redeploy' ?>
                            </button>
                        </form>
                        <?php if ($deployment->status === 'success' || $deployment->status === 'running'): ?>
                            <form method="POST" action="/applications/<?= e($application->uuid) ?>/stop">
                                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                <button type="submit" class="btn btn-secondary w-full">
                                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="6" y="6" width="12" height="12" rx="2"/>
                                    </svg>
                                    Stop Application
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.live-indicator {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
    font-size: var(--text-sm);
    color: var(--text-tertiary);
}

.live-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--accent-green);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* Logs Container */
.logs-container {
    background: var(--bg-primary);
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-lg);
    padding: var(--space-4);
    height: min(600px, 70vh);
    overflow-y: auto;
    overflow-x: hidden;
    font-family: var(--font-mono);
    font-size: var(--text-sm);
}

.log-entry {
    display: grid;
    grid-template-columns: max-content 1fr;
    align-items: start;
    column-gap: var(--space-4);
    padding: var(--space-1) 0;
    min-width: 0;
}

.log-timestamp {
    color: var(--text-tertiary);
    flex-shrink: 0;
    font-size: var(--text-xs);
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.log-message {
    white-space: pre-wrap;
    word-break: break-all;
    overflow-wrap: anywhere;
    color: var(--text-secondary);
    min-width: 0;
    flex: 1;
}

.log-entry.log-error .log-message {
    color: var(--accent-red);
}

.log-entry.log-warning .log-message {
    color: var(--accent-yellow);
}

.log-entry.log-success .log-message {
    color: var(--accent-green);
}

.link {
    color: var(--accent-blue);
    text-decoration: none;
}

.link:hover {
    color: var(--accent-blue-hover);
}

.code-inline {
    font-family: var(--font-mono);
    font-size: var(--text-xs);
    background: var(--bg-tertiary);
    border: 1px solid var(--border-primary);
    padding: var(--space-1) var(--space-2);
    border-radius: var(--radius-sm);
}
</style>

<?php if ($isInProgress): ?>
<script>
(function() {
    const logsContainer = document.getElementById('logs-container');
    let lastLogCount = <?= count($logs) ?>;
    let pollInterval = null;

    async function fetchLogs() {
        try {
            const response = await fetch('/deployments/<?= $deployment->uuid ?>/logs');
            const data = await response.json();
            
            if (data.logs && data.logs.length !== lastLogCount) {
                logsContainer.innerHTML = '';
                
                data.logs.forEach(log => {
                    const entry = document.createElement('div');
                    entry.className = 'log-entry ' + getLogClass(log.message);
                    
                    const timestamp = document.createElement('span');
                    timestamp.className = 'log-timestamp';
                    timestamp.textContent = log.timestamp || '';
                    
                    const message = document.createElement('span');
                    message.className = 'log-message';
                    message.textContent = log.message || '';
                    
                    entry.appendChild(timestamp);
                    entry.appendChild(message);
                    logsContainer.appendChild(entry);
                });
                
                lastLogCount = data.logs.length;
                logsContainer.scrollTop = logsContainer.scrollHeight;
            }
            
            // Check if deployment finished
            if (data.status && !['queued', 'building', 'deploying'].includes(data.status)) {
                clearInterval(pollInterval);
                setTimeout(() => location.reload(), 1000);
            }
        } catch (e) {
            console.error('Failed to fetch logs:', e);
        }
    }

    function getLogClass(message) {
        const lower = (message || '').toLowerCase();
        if (lower.includes('error') || lower.includes('failed')) return 'log-error';
        if (lower.includes('warning') || lower.includes('warn')) return 'log-warning';
        if (lower.includes('success') || lower.includes('completed')) return 'log-success';
        return 'log-info';
    }

    // Start polling
    pollInterval = setInterval(fetchLogs, 2000);
    
    // Initial scroll to bottom
    logsContainer.scrollTop = logsContainer.scrollHeight;
})();
</script>
<?php endif; ?>
