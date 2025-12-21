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

<div class="deployment-show">
    <!-- Breadcrumb & Header -->
    <div class="page-header">
        <div>
            <nav class="breadcrumb">
                <a href="/projects">Projects</a>
                <span class="breadcrumb-separator">/</span>
                <a href="/projects/<?= $project->uuid ?>"><?= e($project->name) ?></a>
                <span class="breadcrumb-separator">/</span>
                <a href="/environments/<?= $environment->uuid ?>"><?= e($environment->name) ?></a>
                <span class="breadcrumb-separator">/</span>
                <a href="/applications/<?= $application->uuid ?>"><?= e($application->name) ?></a>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-current">Deployment</span>
            </nav>
            <div class="flex items-center gap-md mt-sm">
                <h1 class="page-title">Deployment</h1>
                <span class="badge <?= $statusBadge ?>">
                    <?= ucfirst($deployment->status) ?>
                </span>
            </div>
            <p class="text-muted mt-xs">
                <?= e($deployment->commit_message ?? 'Manual deployment') ?>
                <?php if ($deployment->commit_sha): ?>
                    • <code class="code-inline"><?= substr($deployment->commit_sha, 0, 7) ?></code>
                <?php endif; ?>
            </p>
        </div>
        <div class="page-actions">
            <a href="/applications/<?= $application->uuid ?>" class="btn btn-secondary">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Application
            </a>
            <?php if ($isInProgress): ?>
                <form method="POST" action="/deployments/<?= $deployment->uuid ?>/cancel" class="inline">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" class="btn btn-danger">Cancel</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="deployment-layout">
        <!-- Logs -->
        <div class="logs-panel">
            <div class="card card-glass">
                <div class="card-header">
                    <h2 class="card-title">Build Logs</h2>
                    <?php if ($isInProgress): ?>
                        <div class="live-indicator">
                            <span class="live-dot"></span>
                            <span>Live</span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="logs-container" id="logs-container">
                    <?php 
                    $logs = $deployment->logsArray();
                    if (empty($logs)):
                    ?>
                        <p class="text-muted">Waiting for logs...</p>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            $logClass = 'log-info';
                            $msg = strtolower($log['message'] ?? '');
                            if (str_contains($msg, 'error') || str_contains($msg, 'failed')) {
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

        <!-- Sidebar -->
        <div class="deployment-sidebar">
            <!-- Details Card -->
            <div class="card card-glass">
                <div class="card-header">
                    <h3 class="card-title">Details</h3>
                </div>
                <div class="card-body">
                    <dl class="info-list">
                        <div class="info-item">
                            <dt>Status</dt>
                            <dd><span class="badge <?= $statusBadge ?>"><?= ucfirst($deployment->status) ?></span></dd>
                        </div>
                        <div class="info-item">
                            <dt>Started</dt>
                            <dd><?= time_ago($deployment->created_at) ?></dd>
                        </div>
                        <?php if ($deployment->finished_at): ?>
                            <div class="info-item">
                                <dt>Finished</dt>
                                <dd><?= time_ago($deployment->finished_at) ?></dd>
                            </div>
                            <div class="info-item">
                                <dt>Duration</dt>
                                <dd>
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
                        <div class="info-item">
                            <dt>Application</dt>
                            <dd><a href="/applications/<?= $application->uuid ?>" class="link"><?= e($application->name) ?></a></dd>
                        </div>
                        <?php if ($deployment->commit_sha): ?>
                            <div class="info-item">
                                <dt>Commit</dt>
                                <dd><code class="code-inline"><?= substr($deployment->commit_sha, 0, 7) ?></code></dd>
                            </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <dt>Triggered by</dt>
                            <dd><?= e($deployment->triggered_by ?? 'Manual') ?></dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Actions Card -->
            <div class="card card-glass">
                <div class="card-header">
                    <h3 class="card-title">Actions</h3>
                </div>
                <div class="card-body">
                    <div class="btn-stack">
                        <form method="POST" action="/applications/<?= $application->uuid ?>/deploy">
                            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                            <button type="submit" class="btn btn-primary w-full">
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                                Redeploy
                            </button>
                        </form>
                        <?php if ($deployment->status === 'success' || $deployment->status === 'running'): ?>
                            <form method="POST" action="/applications/<?= $application->uuid ?>/stop">
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
.deployment-show {
    display: flex;
    flex-direction: column;
    gap: var(--space-lg);
}

.deployment-layout {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--space-lg);
}

@media (min-width: 1024px) {
    .deployment-layout {
        grid-template-columns: 3fr 1fr;
    }
}

.logs-panel {
    min-width: 0;
}

.deployment-sidebar {
    display: flex;
    flex-direction: column;
    gap: var(--space-lg);
}

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
    background: var(--green-500);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* Logs Container */
.logs-container {
    background: var(--gray-900);
    border-radius: var(--radius-lg);
    padding: var(--space-md);
    height: 600px;
    overflow-y: auto;
    font-family: var(--font-mono);
    font-size: var(--text-sm);
}

[data-theme="light"] .logs-container {
    background: var(--gray-100);
}

.log-entry {
    display: flex;
    align-items: flex-start;
    gap: var(--space-sm);
    padding: var(--space-xs) 0;
}

.log-timestamp {
    color: var(--text-tertiary);
    flex-shrink: 0;
    font-size: var(--text-xs);
}

.log-message {
    white-space: pre-wrap;
    word-break: break-all;
    overflow-wrap: anywhere;
    color: var(--text-secondary);
}

.log-entry.log-error .log-message {
    color: var(--red-400);
}

.log-entry.log-warning .log-message {
    color: var(--yellow-400);
}

.log-entry.log-success .log-message {
    color: var(--green-400);
}

/* Info List */
.info-list {
    display: flex;
    flex-direction: column;
    gap: var(--space-md);
}

.info-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: var(--text-sm);
}

.info-item dt {
    color: var(--text-tertiary);
}

.info-item dd {
    color: var(--text-primary);
    margin: 0;
}

.link {
    color: var(--blue-400);
    text-decoration: none;
}

.link:hover {
    color: var(--blue-300);
}

.code-inline {
    font-family: var(--font-mono);
    font-size: var(--text-xs);
    background: var(--surface-secondary);
    padding: var(--space-xs) var(--space-sm);
    border-radius: var(--radius-sm);
}

/* Button Stack */
.btn-stack {
    display: flex;
    flex-direction: column;
    gap: var(--space-sm);
}

.btn-stack form {
    width: 100%;
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
