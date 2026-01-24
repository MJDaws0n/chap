<?php
/**
 * Application Show View
 * Updated to use new design system with vanilla JavaScript
 */
/** @var \Chap\Models\Application $application */
$statusColors = [
    'running' => 'badge-success',
    'restarting' => 'badge-warning',
    'stopped' => 'badge-neutral',
    'building' => 'badge-warning',
    'deploying' => 'badge-info',
    'failed' => 'badge-danger',
];
$statusColor = $statusColors[$application->status] ?? 'badge-default';

$isDeploying = method_exists($application, 'isDeploying')
    ? $application->isDeploying()
    : (($application->status ?? null) === 'deploying');

$envArr = $application->getEnvironmentVariables();
$envVarsRaw = '';
if (!empty($envArr)) {
    foreach ($envArr as $k => $v) {
        $envVarsRaw .= $k . '=' . $v . "\n";
    }
    $envVarsRaw = rtrim($envVarsRaw, "\n");
}

$incomingWebhooks = $incomingWebhooks ?? [];
$incomingWebhookReveals = $incomingWebhookReveals ?? [];
$allocatedPorts = $allocatedPorts ?? [];
$allocatedPorts = array_values(array_map('intval', is_array($allocatedPorts) ? $allocatedPorts : []));

$errors = $_SESSION['_errors'] ?? [];
$old = $_SESSION['_old_input'] ?? [];
unset($_SESSION['_errors'], $_SESSION['_old_input']);

$canEditResourceLimits = $canEditResourceLimits ?? false;
$appNotificationSettings = $appNotificationSettings ?? [];
$appNotifyEnabled = array_key_exists('notify_app_deployments_enabled', $old)
    ? ((string)$old['notify_app_deployments_enabled'] === '1')
    : (bool)($appNotificationSettings['deployments']['enabled'] ?? true);
$appNotifyMode = array_key_exists('notify_app_deployments_mode', $old)
    ? (string)$old['notify_app_deployments_mode']
    : (string)($appNotificationSettings['deployments']['mode'] ?? 'all');
?>

<div class="flex flex-col gap-6">
    <?php $activeTab = $activeTab ?? 'config'; ?>
    <?php $latestDeployment = !empty($deployments) ? $deployments[0] : null; ?>
    <?php include __DIR__ . '/_header_tabs.php'; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3">
        <div class="lg:col-span-2 flex flex-col gap-6">
            <?php if ($activeTab === 'deploy'): ?>
            <!-- Deployments -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Recent Deployments</h2>
                </div>
                <?php if (empty($deployments)): ?>
                    <div class="card-body">
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                </svg>
                            </div>
                            <p class="empty-state-title">No deployments yet</p>
                            <p class="empty-state-description">Click “Deploy” to start your first deployment.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card-body p-0">
                        <?php foreach ($deployments as $deployment): ?>
                            <?php
                            $depStatus = $deployment->status ?? 'queued';
                            $depColor = match($depStatus) {
                                'running', 'success' => 'badge-success',
                                'deploying' => 'badge-info',
                                'building' => 'badge-warning',
                                'failed' => 'badge-danger',
                                default => 'badge-default',
                            };
                            $sha = $deployment->commit_sha ? substr($deployment->commit_sha, 0, 7) : 'N/A';
                            $msg = $deployment->commit_message ?? 'Manual deployment';
                            ?>
                            <div class="flex items-center justify-between px-6 py-4 border-b border-primary cursor-default">
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium truncate"><?= e($msg) ?></p>
                                    <p class="text-sm text-secondary truncate"><?= e($sha) ?> • <?= time_ago($deployment->created_at) ?></p>
                                </div>
                                <div class="flex items-center gap-3 ml-4 flex-shrink-0">
                                    <span class="badge <?= $depColor ?>"><?= ucfirst($depStatus) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Deploy Settings -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Deploy</h2>
                </div>
                <div class="card-body">
                    <p class="text-sm text-secondary mb-4">Repository + node settings used for deployments.</p>

                    <form method="POST" action="/applications/<?= $application->uuid ?>" id="deploy-settings-form">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="_method" value="PUT">
                        <input type="hidden" name="_redirect_tab" value="deploy">

                        <div class="grid grid-cols-1 md:grid-cols-2">
                            <div class="form-group">
                                <label class="form-label" for="node_uuid">Node</label>
                                <select name="node_uuid" id="node_uuid" class="select">
                                    <?php foreach ($nodes as $node): ?>
                                        <option value="<?= $node->uuid ?>" <?= $application->node_id === $node->id ? 'selected' : '' ?>>
                                            <?= e($node->name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="git_repository">Git Repository</label>
                                <input type="text" name="git_repository" id="git_repository" value="<?= e($application->git_repository) ?>" class="input truncate">
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="git_branch">Branch</label>
                                <input type="text" name="git_branch" id="git_branch" value="<?= e($application->git_branch) ?>" class="input">
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="build_pack">Build Pack</label>
                                <select name="build_pack" id="build_pack" class="select">
                                    <option value="docker-compose" <?= $application->build_pack === 'docker-compose' ? 'selected' : '' ?>>Docker Compose</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 pt-4 mt-4 border-t">
                            <button type="submit" class="btn btn-primary">Save Deploy Settings</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Deployment Notifications -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Notifications</h2>
                </div>
                <div class="card-body">
                    <p class="text-sm text-secondary mb-4">Control deployment notifications for this application. Users must enable notifications in their settings too.</p>

                    <form method="POST" action="/applications/<?= $application->uuid ?>">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="_method" value="PUT">
                        <input type="hidden" name="_redirect_tab" value="deploy">
                        <input type="hidden" name="app_notify_settings_form" value="1">

                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="font-medium">Deployment Notifications</p>
                                <p class="text-secondary text-sm">Send notifications when deployments finish.</p>
                            </div>
                            <label class="toggle" aria-label="Application Deployment Notifications">
                                <input type="hidden" name="notify_app_deployments_enabled" value="0">
                                <input type="checkbox" name="notify_app_deployments_enabled" value="1" <?= $appNotifyEnabled ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="form-group mt-4">
                            <label class="form-label" for="notify_app_deployments_mode">Notify on</label>
                            <select class="select" id="notify_app_deployments_mode" name="notify_app_deployments_mode">
                                <option value="all" <?= $appNotifyMode === 'all' ? 'selected' : '' ?>>All deployments</option>
                                <option value="failed" <?= $appNotifyMode === 'failed' ? 'selected' : '' ?>>Failed deployments only</option>
                            </select>
                        </div>

                        <div class="flex justify-end gap-3 pt-4 mt-4 border-t">
                            <button type="submit" class="btn btn-primary">Save Notification Settings</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Incoming Webhooks -->
            <div class="card">
                <div class="card-header flex items-center justify-between gap-4 flex-wrap">
                    <h2 class="card-title">Incoming Webhooks</h2>
                </div>
                <div class="card-body">
                    <p class="text-sm text-secondary">
                        Create a GitHub webhook that auto-deploys this application on push.
                        GitHub should be configured to send <code>application/json</code> (recommended), but <code>application/x-www-form-urlencoded</code> is also supported.
                    </p>

                    <form method="POST" action="/applications/<?= e($application->uuid) ?>/incoming-webhooks" class="mt-4">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <div class="grid grid-cols-1 md:grid-cols-3">
                            <div class="form-group">
                                <label class="form-label" for="ih-name">Name</label>
                                <input type="text" name="name" id="ih-name" value="GitHub" class="input">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="ih-branch">Branch override (optional)</label>
                                <input type="text" name="branch" id="ih-branch" placeholder="<?= e($application->git_branch) ?>" class="input">
                            </div>
                            <div class="form-group flex items-end">
                                <button type="submit" class="btn btn-primary w-full">Create Webhook</button>
                            </div>
                        </div>
                    </form>

                    <?php if (empty($incomingWebhooks)): ?>
                        <div class="empty-state mt-6">
                            <p class="empty-state-title">No incoming webhooks</p>
                            <p class="empty-state-description">Create one to enable auto-deploy on GitHub push.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container mt-6">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Branch</th>
                                        <th>Endpoint</th>
                                        <th>Secret</th>
                                        <th>Last</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($incomingWebhooks as $wh): ?>
                                        <?php
                                            $endpointUrl = url('/webhooks/github/' . $wh->uuid);
                                            $revealed = ($incomingWebhookReveals[$wh->uuid] ?? null);
                                            $branchLabel = $wh->branch ? $wh->branch : ($application->git_branch ?: '-');
                                            $last = $wh->last_received_at ? time_ago($wh->last_received_at) : 'Never';
                                            $secretLabel = $revealed ? $revealed : '••••••••';
                                        ?>
                                        <tr>
                                            <td class="font-medium truncate">
                                                <?= e($wh->name) ?>
                                                <?php if (!($wh->is_active ?? true)): ?>
                                                    <span class="badge badge-neutral ml-2">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><code><?= e($branchLabel) ?></code></td>
                                            <td class="min-w-0">
                                                <div class="flex items-center gap-2 min-w-0">
                                                    <code class="truncate min-w-0 flex-1"><?= e($endpointUrl) ?></code>
                                                    <button type="button" class="btn btn-ghost btn-sm flex-shrink-0" onclick="copyToClipboard('<?= e($endpointUrl) ?>')">Copy</button>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="flex items-center gap-2 min-w-0">
                                                    <code class="truncate min-w-0 flex-1"><?= e($secretLabel) ?></code>
                                                    <?php if ($revealed): ?>
                                                        <button type="button" class="btn btn-ghost btn-sm flex-shrink-0" onclick="copyToClipboard('<?= e($revealed) ?>')">Copy</button>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($revealed): ?>
                                                    <p class="text-xs text-secondary mt-1">Shown once — store it in GitHub now.</p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-secondary">
                                                <?= e($last) ?>
                                                <?php if (!empty($wh->last_status)): ?>
                                                    <span class="text-tertiary">• <?= e($wh->last_status) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right">
                                                <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                                                    <form method="POST" action="/incoming-webhooks/<?= e($wh->uuid) ?>/rotate" class="inline-block">
                                                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                                        <button type="submit" class="btn btn-secondary btn-sm">Rotate Secret</button>
                                                    </form>
                                                    <form method="POST" action="/incoming-webhooks/<?= e($wh->uuid) ?>" class="inline-block" data-delete-incoming-webhook>
                                                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                                        <input type="hidden" name="_method" value="DELETE">
                                                        <button type="submit" class="btn btn-danger-ghost btn-sm">Delete</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php else: ?>

            <!-- Configuration -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Configuration</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="/applications/<?= $application->uuid ?>" id="config-form" data-confirm-resource-limits="1">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="_method" value="PUT">
                        <input type="hidden" name="_redirect_tab" value="config">

                        <div class="grid grid-cols-1 md:grid-cols-2">
                            <div class="form-group">
                                <label class="form-label" for="name">Name</label>
                                <input type="text" name="name" id="name" value="<?= e($application->name) ?>" class="input">
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="cpu_limit">CPU Limit</label>
                                <input
                                    type="text"
                                    name="cpu_limit"
                                    id="cpu_limit"
                                    value="<?= e($canEditResourceLimits ? ($old['cpu_limit'] ?? $application->cpu_limit) : $application->cpu_limit) ?>"
                                    class="input"
                                    placeholder="e.g. 1"
                                    <?= !$canEditResourceLimits ? 'disabled aria-disabled="true"' : '' ?>
                                >
                                <?php if (!empty($errors['cpu_limit'])): ?><p class="form-error"><?= e($errors['cpu_limit']) ?></p><?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="memory_limit">Memory Limit</label>
                                <input
                                    type="text"
                                    name="memory_limit"
                                    id="memory_limit"
                                    value="<?= e($canEditResourceLimits ? ($old['memory_limit'] ?? $application->memory_limit) : $application->memory_limit) ?>"
                                    class="input"
                                    placeholder="e.g. 512m"
                                    <?= !$canEditResourceLimits ? 'disabled aria-disabled="true"' : '' ?>
                                >
                                <?php if (!empty($errors['memory_limit'])): ?><p class="form-error"><?= e($errors['memory_limit']) ?></p><?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="storage_mb_limit">Storage Limit (MB or -1)</label>
                                <input
                                    type="number"
                                    name="storage_mb_limit"
                                    id="storage_mb_limit"
                                    value="<?= e($canEditResourceLimits ? ($old['storage_mb_limit'] ?? (string)$application->storage_mb_limit) : (string)$application->storage_mb_limit) ?>"
                                    class="input"
                                    placeholder="-1"
                                    <?= !$canEditResourceLimits ? 'disabled aria-disabled="true"' : '' ?>
                                >
                                <?php if (!empty($errors['storage_mb_limit'])): ?><p class="form-error"><?= e($errors['storage_mb_limit']) ?></p><?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="bandwidth_mbps_limit">Bandwidth Limit (Mbps or -1)</label>
                                <input
                                    type="number"
                                    name="bandwidth_mbps_limit"
                                    id="bandwidth_mbps_limit"
                                    value="<?= e($canEditResourceLimits ? ($old['bandwidth_mbps_limit'] ?? (string)$application->bandwidth_mbps_limit) : (string)$application->bandwidth_mbps_limit) ?>"
                                    class="input"
                                    placeholder="-1"
                                    <?= !$canEditResourceLimits ? 'disabled aria-disabled="true"' : '' ?>
                                >
                                <?php if (!empty($errors['bandwidth_mbps_limit'])): ?><p class="form-error"><?= e($errors['bandwidth_mbps_limit']) ?></p><?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="pids_limit">Process Limit (PIDs or -1)</label>
                                <input
                                    type="number"
                                    name="pids_limit"
                                    id="pids_limit"
                                    value="<?= e($canEditResourceLimits ? ($old['pids_limit'] ?? (string)$application->pids_limit) : (string)$application->pids_limit) ?>"
                                    class="input"
                                    placeholder="-1"
                                    <?= !$canEditResourceLimits ? 'disabled aria-disabled="true"' : '' ?>
                                >
                                <?php if (!empty($errors['pids_limit'])): ?><p class="form-error"><?= e($errors['pids_limit']) ?></p><?php endif; ?>
                            </div>

                            <div class="md:col-span-2">
                                <div class="alert alert-warning">
                                    <svg class="alert-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                    <span>
                                        Changing resource limits triggers an automatic redeploy.
                                        <?php if (!$canEditResourceLimits): ?>
                                            You don't have permission to edit resource limits.
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 pt-4 mt-4 border-t">
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Ports -->
            <div class="card">
                <div class="card-header flex items-center justify-between gap-4 flex-wrap">
                    <h2 class="card-title">Ports</h2>
                    <button type="button" class="btn btn-secondary btn-sm" id="add-port-btn">New Port</button>
                </div>
                <div class="card-body">
                    <div id="ports-error" class="text-sm text-red mb-3 hidden"></div>
                    <div id="ports-list" class="flex flex-col gap-2"></div>
                    <div id="ports-empty" class="text-muted text-sm hidden">No ports allocated.</div>
                    <p class="text-xs text-tertiary mt-3">Ports are system-managed. Dynamic vars: <code>{port[0]}</code>, <code>{port[1]}</code>, … (zero-based) and <code>{name}</code>, <code>{node}</code>, <code>{repo}</code>, <code>{repo_brach}</code>, <code>{cpu}</code>, <code>{ram}</code>.</p>
                </div>
            </div>

            <!-- Environment Variables -->
            <div class="card">
                <div class="card-header flex items-center justify-between gap-4 flex-wrap">
                    <h2 class="card-title">Environment Variables</h2>
                    <div class="flex items-center gap-2 flex-wrap">
                        <button type="button" class="btn btn-ghost btn-sm" id="bulk-edit-btn">Bulk Edit</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="add-env-btn">Add Variable</button>
                    </div>
                </div>
                <div class="card-body">
                    <p class="text-xs text-tertiary mb-3">Dynamic vars: <code>{port[0]}</code>, <code>{port[1]}</code>, … (zero-based) plus <code>{name}</code>, <code>{node}</code>, <code>{repo}</code>, <code>{repo_brach}</code>, <code>{cpu}</code>, <code>{ram}</code>. Saving will fail if a referenced value isn’t available.</p>
                    <form method="POST" action="/applications/<?= $application->uuid ?>" id="env-form">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="_method" value="PUT">
                        <input type="hidden" name="_redirect_tab" value="config">
                        <input type="hidden" name="environment_variables" id="env-serialized" value="">

                        <div id="env-rows" class="flex flex-col gap-3">
                            <!-- Rows will be rendered by JS -->
                        </div>

                        <div id="env-empty" class="text-muted text-sm hidden">
                            No environment variables configured.
                        </div>

                        <div class="flex justify-end gap-3 pt-4 mt-4 border-t flex-wrap">
                            <button type="button" class="btn btn-ghost" id="env-cancel-btn">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Variables</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php endif; ?>
        </div>

        <div class="flex flex-col gap-6">
            <!-- Status Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Status</h3>
                </div>
                <div class="card-body">
                    <dl class="flex flex-col gap-4">
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-tertiary">Status</dt>
                            <dd class="m-0"><span class="badge <?= $statusColor ?>"><?= ucfirst($application->status) ?></span></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-tertiary">Node</dt>
                            <dd class="m-0 text-primary truncate"><?= $application->node() ? e($application->node()->name) : 'Not assigned' ?></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-tertiary">Memory</dt>
                            <dd class="m-0 text-primary"><?= e($application->memory_limit ?? '512m') ?></dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 text-sm">
                            <dt class="text-tertiary">CPU</dt>
                            <dd class="m-0 text-primary"><?= e($application->cpu_limit ?? '1') ?> CPU</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <?php if ($activeTab === 'config'): ?>
                <!-- Danger Zone -->
                <div class="card border-red">
                    <div class="card-header">
                        <h3 class="card-title text-red">Danger Zone</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="/applications/<?= $application->uuid ?>" id="delete-form">
                            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="_method" value="DELETE">
                            <button type="button" class="btn btn-danger w-full" id="delete-app-btn">
                                Delete Application
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.env-key {
    flex: 0 0 180px;
    min-width: 120px;
}

@media (max-width: 639px) {
    .env-key {
        flex-basis: 45%;
    }
}
</style>

<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text);
    if (window.Toast && typeof window.Toast.success === 'function') {
        window.Toast.success('Copied to clipboard');
    }
}

document.querySelectorAll('[data-delete-incoming-webhook]').forEach(form => {
    form.addEventListener('submit', (e) => {
        if (window.Modal && typeof window.Modal.confirmDelete === 'function') {
            e.preventDefault();
            window.Modal.confirmDelete('This will permanently delete the webhook endpoint. GitHub will stop being able to deploy from it.')
                .then(result => {
                    if (result && result.confirmed) form.submit();
                });
        }
    });
});
</script>

<script>
(function() {
    'use strict';

    const config = {
        applicationUuid: '<?= e($application->uuid) ?>',
        initialPorts: <?= json_encode($allocatedPorts, JSON_UNESCAPED_SLASHES) ?>,
    };

    const els = {
        addBtn: document.getElementById('add-port-btn'),
        list: document.getElementById('ports-list'),
        empty: document.getElementById('ports-empty'),
        error: document.getElementById('ports-error'),
    };

    if (!els.addBtn || !els.list || !els.empty || !els.error) return;

    const state = {
        ports: Array.isArray(config.initialPorts) ? config.initialPorts.slice() : [],
        busy: false,
    };

    function setError(message) {
        if (!message) {
            els.error.textContent = '';
            els.error.classList.add('hidden');
            return;
        }
        els.error.textContent = String(message);
        els.error.classList.remove('hidden');
    }

    function render() {
        const ports = state.ports || [];
        if (ports.length === 0) {
            els.empty.classList.remove('hidden');
            els.list.innerHTML = '';
            return;
        }

        els.empty.classList.add('hidden');
        els.list.innerHTML = ports.map((p, idx) => `
            <div class="flex items-center justify-between gap-3 p-2 bg-tertiary border border-primary rounded-md">
                <div class="min-w-0">
                    <div class="font-medium text-primary">${escapeHtml(String(p))}</div>
                    <div class="text-xs text-tertiary">Use {port[${idx}]}</div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" class="btn btn-ghost btn-sm" data-copy="${escapeAttr(String(p))}" ${state.busy ? 'disabled' : ''}>Copy</button>
                    <button type="button" class="btn btn-danger btn-sm" title="Unallocate" aria-label="Unallocate" data-unallocate="${escapeAttr(String(p))}" ${state.busy ? 'disabled' : ''}>
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 6V4h8v2"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 6l-1 16H6L5 6"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 11v6"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 11v6"/>
                        </svg>
                    </button>
                </div>
            </div>
        `).join('');

        els.list.querySelectorAll('[data-copy]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const val = btn.getAttribute('data-copy') || '';
                try {
                    await navigator.clipboard.writeText(val);
                    if (window.Toast && typeof window.Toast.success === 'function') {
                        window.Toast.success('Copied');
                    }
                } catch {
                    // ignore
                }
            });
        });

        els.list.querySelectorAll('[data-unallocate]').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (state.busy) return;
                const val = btn.getAttribute('data-unallocate') || '';
                const port = parseInt(val, 10);
                if (!Number.isInteger(port)) return;

                if (window.Modal && typeof window.Modal.confirmDelete === 'function') {
                    const res = await window.Modal.confirmDelete(
                        `Unallocate port ${port}?`,
                        'This will remove the port allocation and re-deploy the application so the port stops being published.'
                    );
                    if (!res || !res.confirmed) return;
                }

                await unallocatePort(port);
            });
        });
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function escapeAttr(str) {
        return escapeHtml(str);
    }

    async function allocatePort() {
        if (state.busy) return;
        state.busy = true;
        setError('');
        els.addBtn.disabled = true;
        els.addBtn.setAttribute('aria-disabled', 'true');

        try {
            const url = `/applications/${encodeURIComponent(config.applicationUuid)}/ports`;

            let data;
            if (window.Chap && typeof window.Chap.api === 'function') {
                data = await window.Chap.api(url, 'POST', {});
            } else {
                const csrf = document.querySelector('input[name="_csrf_token"]')?.value || '';
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
                    },
                    body: JSON.stringify({ _csrf_token: csrf }),
                });

                data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    throw new Error((data && (data.error || data.message)) ? (data.error || data.message) : 'Failed to allocate port');
                }
            }

            if (Array.isArray(data?.ports)) {
                state.ports = data.ports.map((x) => parseInt(x, 10)).filter((x) => Number.isInteger(x));
            } else if (Number.isInteger(parseInt(data?.port, 10))) {
                const p = parseInt(data.port, 10);
                state.ports = Array.from(new Set([...(state.ports || []), p])).sort((a, b) => a - b);
            }
            render();
        } catch (e) {
            const msg = e && e.message ? e.message : String(e);
            setError(msg);
        } finally {
            state.busy = false;
            els.addBtn.disabled = false;
            els.addBtn.removeAttribute('aria-disabled');
            render();
        }
    }

    async function redeploy() {
        const csrf = window.csrfToken || document.querySelector('input[name="_csrf_token"]')?.value || '';
        const url = `/applications/${encodeURIComponent(config.applicationUuid)}/deploy`;

        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
            },
            body: JSON.stringify({}),
        });

        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            throw new Error((data && (data.error || data.message)) ? (data.error || data.message) : 'Failed to start redeploy');
        }

        if (window.Toast && typeof window.Toast.success === 'function') {
            window.Toast.success('Redeploy started');
        }

        return data;
    }

    async function unallocatePort(port) {
        if (state.busy) return;
        state.busy = true;
        setError('');
        els.addBtn.disabled = true;
        els.addBtn.setAttribute('aria-disabled', 'true');
        render();

        try {
            const url = `/applications/${encodeURIComponent(config.applicationUuid)}/ports/${encodeURIComponent(String(port))}`;

            let data;
            if (window.Chap && typeof window.Chap.api === 'function') {
                data = await window.Chap.api(url, 'DELETE');
            } else {
                const csrf = window.csrfToken || document.querySelector('input[name="_csrf_token"]')?.value || '';
                const res = await fetch(url, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
                    },
                });
                data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    throw new Error((data && (data.error || data.message)) ? (data.error || data.message) : 'Failed to unallocate port');
                }
            }

            if (Array.isArray(data?.ports)) {
                state.ports = data.ports.map((x) => parseInt(x, 10)).filter((x) => Number.isInteger(x));
            } else {
                state.ports = (state.ports || []).filter((p) => p !== port);
            }
            render();

            try {
                await redeploy();
            } catch (e) {
                const msg = e && e.message ? e.message : String(e);
                setError(msg);
            }
        } catch (e) {
            const msg = e && e.message ? e.message : String(e);
            setError(msg);
        } finally {
            state.busy = false;
            els.addBtn.disabled = false;
            els.addBtn.removeAttribute('aria-disabled');
            render();
        }
    }

    els.addBtn.addEventListener('click', (e) => {
        e.preventDefault();
        allocatePort();
    });
    render();
})();
</script>

<script>
(function() {
    'use strict';

    // Initial environment variables from PHP
    const initialEnvB64 = '<?= base64_encode($envVarsRaw) ?>';

    // State
    const state = {
        rows: [],
        originalRows: []
    };

    // DOM Elements
    const elements = {};

    function init() {
        cacheElements();

        // This entire section is only rendered on the Configuration tab.
        // If the elements aren't present, bail out quietly.
        if (!elements.envRows || !elements.envForm || !elements.envSerialized || !elements.addEnvBtn || !elements.bulkEditBtn || !elements.envCancelBtn) {
            return;
        }

        bindEvents();
        parseInitialEnv();
        renderRows();
    }

    function cacheElements() {
        elements.envRows = document.getElementById('env-rows');
        elements.envEmpty = document.getElementById('env-empty');
        elements.envSerialized = document.getElementById('env-serialized');
        elements.envForm = document.getElementById('env-form');
        elements.addEnvBtn = document.getElementById('add-env-btn');
        elements.bulkEditBtn = document.getElementById('bulk-edit-btn');
        elements.envCancelBtn = document.getElementById('env-cancel-btn');
        elements.deleteAppBtn = document.getElementById('delete-app-btn');
        elements.deleteForm = document.getElementById('delete-form');
    }

    function bindEvents() {
        elements.addEnvBtn.addEventListener('click', () => {
            addRow('', '');
            renderRows();
        });

        elements.bulkEditBtn.addEventListener('click', openBulkEditor);

        elements.envCancelBtn.addEventListener('click', () => {
            state.rows = JSON.parse(JSON.stringify(state.originalRows));
            renderRows();
        });

        if (elements.deleteAppBtn && elements.deleteForm && window.Modal && typeof window.Modal.confirmDelete === 'function') {
            elements.deleteAppBtn.addEventListener('click', () => {
                window.Modal.confirmDelete('Are you sure you want to delete this application? This action cannot be undone.')
                    .then(result => {
                        if (result && result.confirmed) {
                            elements.deleteForm.submit();
                        }
                    });
            });
        }

        elements.envForm.addEventListener('submit', () => {
            updateSerialized();
        });
    }

    function parseInitialEnv() {
        state.rows = [];
        try {
            const str = atob(initialEnvB64);
            if (!str) return;
            
            const lines = str.split(/\r?\n/);
            for (const line of lines) {
                const trimmed = line.trim();
                if (!trimmed || trimmed.startsWith('#')) continue;
                
                const idx = trimmed.indexOf('=');
                if (idx === -1) continue;
                
                const key = trimmed.substring(0, idx).trim();
                const value = trimmed.substring(idx + 1);
                state.rows.push({ key, value, revealed: false });
            }
        } catch (e) {
            console.warn('Failed to parse initial env:', e);
        }
        
        state.originalRows = JSON.parse(JSON.stringify(state.rows));
        updateSerialized();
    }

    function addRow(key = '', value = '') {
        state.rows.push({ key, value, revealed: false });
        updateSerialized();
    }

    function removeRow(index) {
        state.rows.splice(index, 1);
        renderRows();
    }

    function updateSerialized() {
        const serialized = state.rows
            .filter(r => r.key)
            .map(r => r.key + '=' + r.value)
            .join('\n');
        elements.envSerialized.value = serialized;
    }

    function renderRows() {
        if (state.rows.length === 0) {
            elements.envEmpty.classList.remove('hidden');
            elements.envRows.innerHTML = '';
            return;
        }

        elements.envEmpty.classList.add('hidden');
        elements.envRows.innerHTML = state.rows.map((row, idx) => `
            <div class="env-row flex flex-wrap items-center gap-2 p-2 rounded-md" data-index="${idx}">
                <input type="text" class="input input-sm env-key" placeholder="KEY" value="${escapeAttr(row.key)}" data-field="key">
                <input type="${row.revealed ? 'text' : 'password'}" class="input input-sm flex-1" placeholder="value" value="${escapeAttr(row.value)}" data-field="value">
                <button type="button" class="btn btn-ghost btn-sm toggle-reveal">${row.revealed ? 'Hide' : 'Show'}</button>
                <button type="button" class="btn btn-danger-ghost btn-sm remove-row">Remove</button>
            </div>
        `).join('');

        // Bind events to new elements
        elements.envRows.querySelectorAll('.env-row').forEach(row => {
            const idx = parseInt(row.dataset.index, 10);
            
            row.querySelector('[data-field="key"]').addEventListener('input', (e) => {
                state.rows[idx].key = e.target.value;
                updateSerialized();
            });
            
            row.querySelector('[data-field="value"]').addEventListener('input', (e) => {
                state.rows[idx].value = e.target.value;
                updateSerialized();
            });
            
            row.querySelector('.toggle-reveal').addEventListener('click', () => {
                state.rows[idx].revealed = !state.rows[idx].revealed;
                renderRows();
            });
            
            row.querySelector('.remove-row').addEventListener('click', () => {
                removeRow(idx);
            });
        });

        updateSerialized();
    }

    function openBulkEditor() {
        const currentValue = state.rows
            .filter(r => r.key)
            .map(r => r.key + '=' + r.value)
            .join('\n');

        Modal.prompt('Bulk Edit Environment Variables', {
            inputType: 'textarea',
            value: currentValue,
            placeholder: 'KEY=VALUE\nANOTHER=VAL',
            confirmText: 'Apply',
            required: false
        }).then(({ confirmed, value }) => {
            if (!confirmed) return;
            parseEnvString(value || '');
            state.originalRows = JSON.parse(JSON.stringify(state.rows));
            renderRows();
        });
    }

    function parseEnvString(str) {
        state.rows = [];
        if (!str) return;
        
        const lines = str.split(/\r?\n/);
        for (const line of lines) {
            const trimmed = line.trim();
            if (!trimmed || trimmed.startsWith('#')) continue;
            
            const idx = trimmed.indexOf('=');
            if (idx === -1) continue;
            
            const key = trimmed.substring(0, idx).trim();
            const value = trimmed.substring(idx + 1);
            state.rows.push({ key, value, revealed: false });
        }
        
        updateSerialized();
    }

    function escapeAttr(str) {
        if (!str) return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
