<?php
/**
 * Application Show View
 * Updated to use new design system with vanilla JavaScript
 */
$statusColors = [
    'running' => 'badge-success',
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
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <nav class="breadcrumb">
                    <span class="breadcrumb-item"><a href="/projects">Projects</a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-item"><a href="/projects/<?= e($project->uuid) ?>"><?= e($project->name) ?></a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-item"><a href="/environments/<?= e($environment->uuid) ?>"><?= e($environment->name) ?></a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current"><?= e($application->name) ?></span>
                </nav>

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-lg icon-box-blue">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>

                    <div class="min-w-0">
                        <div class="flex items-center flex-wrap gap-3">
                            <h1 class="page-header-title"><?= e($application->name) ?></h1>
                            <span class="badge <?= $statusColor ?>"><?= ucfirst($application->status) ?></span>
                        </div>
                        <?php if (!empty($application->description)): ?>
                            <p class="page-header-description truncate"><?= e($application->description) ?></p>
                        <?php else: ?>
                            <p class="page-header-description">No description</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="page-header-actions flex-wrap">
                <a href="/applications/<?= e($application->uuid) ?>/logs" class="btn btn-secondary">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Live Logs
                </a>

                <a href="/applications/<?= e($application->uuid) ?>/files" class="btn btn-secondary">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 012-2h5l2 2h7a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>
                    </svg>
                    Files
                </a>

                <?php if (($application->status ?? '') === 'running'): ?>
                    <form method="POST" action="/applications/<?= e($application->uuid) ?>/restart" class="inline-block">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <button type="submit" class="btn btn-warning">Restart</button>
                    </form>
                    <form method="POST" action="/applications/<?= e($application->uuid) ?>/stop" class="inline-block">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <button type="submit" class="btn btn-secondary">Stop</button>
                    </form>
                <?php endif; ?>

                <form method="POST" action="/applications/<?= e($application->uuid) ?>/deploy" class="inline-block" data-deploy-form>
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" class="btn btn-primary" <?= $isDeploying ? 'disabled aria-disabled="true"' : '' ?>>
                        <?= $isDeploying ? 'Deploying…' : 'Deploy' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3">
        <div class="lg:col-span-2 flex flex-col gap-6">
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
                            <a href="/deployments/<?= e($deployment->uuid) ?>" class="flex items-center justify-between px-6 py-4 border-b border-primary transition-colors">
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium truncate"><?= e($msg) ?></p>
                                    <p class="text-sm text-secondary truncate"><?= e($sha) ?> • <?= time_ago($deployment->created_at) ?></p>
                                </div>
                                <div class="flex items-center gap-3 ml-4 flex-shrink-0">
                                    <span class="badge <?= $depColor ?>"><?= ucfirst($depStatus) ?></span>
                                    <svg class="icon text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                    </svg>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Configuration -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Configuration</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="/applications/<?= $application->uuid ?>" id="config-form">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="_method" value="PUT">

                        <div class="grid grid-cols-1 md:grid-cols-2">
                            <div class="form-group">
                                <label class="form-label" for="name">Name</label>
                                <input type="text" name="name" id="name" value="<?= e($application->name) ?>" class="input">
                            </div>

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
                            <button type="submit" class="btn btn-primary">Save Changes</button>
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
                    <form method="POST" action="/applications/<?= $application->uuid ?>" id="env-form">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="_method" value="PUT">
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

        elements.deleteAppBtn.addEventListener('click', () => {
            Modal.confirmDelete('Are you sure you want to delete this application? This action cannot be undone.')
                .then(result => {
                    if (result && result.confirmed) {
                        elements.deleteForm.submit();
                    }
                });
        });

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
