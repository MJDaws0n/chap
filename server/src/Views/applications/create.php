<?php
/**
 * Create Application View
 * Updated to use new design system with vanilla JavaScript
 */
$nodesJson = json_encode(array_map(function($n) { 
    return ['uuid' => $n->uuid, 'name' => $n->name]; 
}, $nodes));
$defaultNode = isset($nodes[0]) ? $nodes[0]->uuid : '';
$initialEnv = old('environment_variables', '');
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div>
            <nav class="breadcrumb">
                <span class="breadcrumb-item">
                    <a href="/projects">Projects</a>
                </span>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-item">
                    <a href="/projects/<?= $project->uuid ?>"><?= e($project->name) ?></a>
                </span>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-item">
                    <a href="/environments/<?= $environment->uuid ?>"><?= e($environment->name) ?></a>
                </span>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-current">New Application</span>
            </nav>
            <h1 class="page-title">New Application</h1>
            <p class="page-header-description">Deploy a new application to <?= e($environment->name) ?></p>
        </div>
    </div>

    <form method="POST" action="/environments/<?= $environment->uuid ?>/applications" id="create-app-form" class="flex flex-col gap-6">
        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="port_reservation_uuid" id="port_reservation_uuid" value="<?= e(old('port_reservation_uuid')) ?>">

        <!-- Basic Info -->
        <div class="card card-glass">
            <div class="card-header">
                <h2 class="card-title">Basic Information</h2>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="name" class="form-label">Application Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="name" required
                            value="<?= e(old('name')) ?>"
                            class="input"
                            placeholder="my-awesome-app">
                        <?php if (!empty($_SESSION['_errors']['name'])): ?>
                            <p class="form-error"><?= e($_SESSION['_errors']['name']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Deploy to Node <span class="text-danger">*</span></label>
                        <input type="hidden" name="node_uuid" id="node_uuid" value="<?= $defaultNode ?>" required>
                        <div class="dropdown" id="node-dropdown">
                            <button type="button" class="btn btn-secondary w-full dropdown-trigger" id="node-select-btn" data-dropdown-trigger="node-dropdown-menu" data-dropdown-placement="bottom-start" aria-expanded="false">
                                <span id="selected-node-name"><?= isset($nodes[0]) ? e($nodes[0]->name) : 'Select node...' ?></span>
                                <svg class="icon dropdown-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div class="dropdown-menu w-full" id="node-dropdown-menu">
                                <div class="dropdown-search">
                                    <input type="text" class="input input-sm" placeholder="Search nodes..." id="node-search" autocomplete="off">
                                </div>
                                <div class="dropdown-items" id="node-list"></div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group form-group-full">
                        <label for="description" class="form-label">Description</label>
                        <textarea name="description" id="description" rows="2" class="textarea" placeholder="Optional description"><?= e(old('description')) ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ports -->
        <div class="card card-glass">
            <div class="card-header flex items-center justify-between gap-4 flex-wrap">
                <h2 class="card-title">Ports</h2>
                <button type="button" class="btn btn-secondary btn-sm" id="add-port-btn">New Port</button>
            </div>
            <div class="card-body">
                <div id="ports-list" class="flex flex-col gap-2"></div>
                <div id="ports-empty" class="text-muted text-sm">No ports allocated.</div>
                <p class="form-hint mt-md">Dynamic vars: <code>{port[0]}</code>, <code>{port[1]}</code>, … and <code>{name}</code>, <code>{node}</code>, <code>{repo}</code>, <code>{repo_brach}</code>, <code>{cpu}</code>, <code>{ram}</code>.</p>
            </div>
        </div>

        <!-- Source -->
        <div class="card card-glass">
            <div class="card-header">
                <h2 class="card-title">Source Code</h2>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="git_repository" class="form-label">Git Repository</label>
                        <input type="text" name="git_repository" id="git_repository"
                            value="<?= e(old('git_repository')) ?>"
                            class="input truncate"
                            placeholder="https://github.com/user/repo.git">
                        <p id="repo-env-error" class="form-error hidden"></p>
                    </div>

                    <div class="form-group">
                        <label for="git_branch" class="form-label">Branch</label>
                        <input type="text" name="git_branch" id="git_branch"
                            value="<?= e(old('git_branch', 'main')) ?>"
                            class="input"
                            placeholder="main">
                    </div>
                </div>
            </div>
        </div>

        <!-- Build Configuration -->
        <div class="card card-glass">
            <div class="card-header">
                <h2 class="card-title">Build Configuration</h2>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="build_pack" class="form-label">Build Pack</label>
                        <select name="build_pack" id="build_pack" class="select">
                            <option value="docker-compose" <?= old('build_pack') === 'docker-compose' ? 'selected' : '' ?>>Docker Compose</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Environment Variables -->
        <div class="card card-glass">
            <div class="card-header flex items-center justify-between gap-4 flex-wrap">
                <h2 class="card-title">Environment Variables</h2>
                <div class="flex items-center gap-2 flex-wrap">
                    <button type="button" class="btn btn-ghost btn-sm" id="pull-env-btn">Pull from repo</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="bulk-edit-btn">Bulk Edit</button>
                    <button type="button" class="btn btn-secondary btn-sm" id="add-env-btn">Add Variable</button>
                </div>
            </div>
            <div class="card-body">
                <input type="hidden" name="environment_variables" id="env-serialized" value="">

                <?php if (!empty($_SESSION['_errors']['environment_variables'])): ?>
                    <p class="form-error mb-3"><?= e($_SESSION['_errors']['environment_variables']) ?></p>
                <?php endif; ?>

                <div id="env-rows" class="flex flex-col gap-3">
                    <!-- Rows rendered by JS -->
                </div>

                <div id="env-empty" class="text-muted text-sm">
                    No environment variables configured.
                </div>

                <p class="form-hint mt-md">Add environment variables as key/value pairs. Values are hidden by default.</p>
            </div>
        </div>

        <!-- Resources -->
        <div class="card card-glass">
            <div class="card-header">
                <h2 class="card-title">Resource Limits</h2>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="memory_limit" class="form-label">Memory Limit</label>
                        <input type="text" name="memory_limit" id="memory_limit"
                            value="<?= e(old('memory_limit', '512m')) ?>"
                            class="input"
                            placeholder="512m">
                        <p class="form-hint">Examples: 512m, 1g, 2048m</p>
                    </div>

                    <div class="form-group">
                        <label for="cpu_limit" class="form-label">CPU Limit</label>
                        <input type="text" name="cpu_limit" id="cpu_limit"
                            value="<?= e(old('cpu_limit', '1')) ?>"
                            class="input"
                            placeholder="1">
                        <p class="form-hint">Examples: 0.5, 1, 2</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="form-actions-bar">
            <a href="/environments/<?= $environment->uuid ?>" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Create Application</button>
        </div>
    </form>
</div>

<?php unset($_SESSION['_errors'], $_SESSION['_old_input']); ?>

<style>
/* Form Grid */
.form-grid {
    display: grid;
    grid-template-columns: repeat(1, 1fr);
    gap: var(--space-md);
}

@media (min-width: 768px) {
    .form-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

.form-group-full {
    grid-column: 1 / -1;
}

/* Form Actions Bar */
.form-actions-bar {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-md);
    padding: var(--space-md) 0;
}

/* Environment Editor */
.env-key {
    flex: 0 0 180px;
    min-width: 120px;
}

@media (max-width: 639px) {
    .env-key {
        flex-basis: 45%;
    }
}

/* Card Actions */
.card-actions {
    display: flex;
    gap: var(--space-sm);
}

/* Form Hint */
.form-hint {
    font-size: var(--text-xs);
    color: var(--text-tertiary);
    margin-top: var(--space-xs);
}

/* Form Error */
.form-error {
    font-size: var(--text-sm);
    color: var(--red-400);
    margin-top: var(--space-xs);
}
</style>

<script>
(function() {
    'use strict';

    // Config from PHP
    const config = {
        nodes: <?= $nodesJson ?>,
        defaultNode: '<?= $defaultNode ?>',
        initialEnvB64: '<?= base64_encode($initialEnv) ?>',
        repoEnvUrl: '/environments/<?= $environment->uuid ?>/applications/repo-env',
        envUuid: '<?= $environment->uuid ?>'
    };

    // State
    const state = {
        selectedNode: config.defaultNode,
        nodeSearch: '',
        envRows: [],
        reservationUuid: '',
        ports: []
    };

    // DOM Elements
    const elements = {};

    function init() {
        cacheElements();
        bindEvents();
        ensureReservationUuid();
        renderNodeList();
        parseInitialEnv();
        renderEnvRows();
        renderPorts();
    }

    function cacheElements() {
        elements.nodeDropdown = document.getElementById('node-dropdown');
        elements.nodeDropdownMenu = document.getElementById('node-dropdown-menu');
        elements.nodeSelectBtn = document.getElementById('node-select-btn');
        elements.selectedNodeName = document.getElementById('selected-node-name');
        elements.nodeSearch = document.getElementById('node-search');
        elements.nodeList = document.getElementById('node-list');
        elements.nodeUuid = document.getElementById('node_uuid');
        elements.envRows = document.getElementById('env-rows');
        elements.envEmpty = document.getElementById('env-empty');
        elements.envSerialized = document.getElementById('env-serialized');
        elements.addEnvBtn = document.getElementById('add-env-btn');
        elements.bulkEditBtn = document.getElementById('bulk-edit-btn');
        elements.pullEnvBtn = document.getElementById('pull-env-btn');
        elements.repoEnvError = document.getElementById('repo-env-error');
        elements.gitRepository = document.getElementById('git_repository');
        elements.gitBranch = document.getElementById('git_branch');

        elements.reservationUuid = document.getElementById('port_reservation_uuid');
        elements.portsList = document.getElementById('ports-list');
        elements.portsEmpty = document.getElementById('ports-empty');
        elements.addPortBtn = document.getElementById('add-port-btn');
    }

    function bindEvents() {
        elements.nodeSearch.addEventListener('input', (e) => {
            state.nodeSearch = e.target.value;
            renderNodeList();
        });

        // Environment buttons
        elements.addEnvBtn.addEventListener('click', () => {
            addEnvRow('', '');
            renderEnvRows();
        });

        elements.bulkEditBtn.addEventListener('click', openBulkEditor);
        elements.pullEnvBtn.addEventListener('click', pullFromRepo);

        elements.addPortBtn.addEventListener('click', allocateReservedPort);

        // Update serialized on form submit
        document.getElementById('create-app-form').addEventListener('submit', updateEnvSerialized);
    }

    // (Dropdown open/close handled by global Chap.dropdown; keep no local implementation)

    // Node Selection
    function renderNodeList() {
        const term = state.nodeSearch.toLowerCase();
        const filtered = config.nodes.filter(n => 
            !term || n.name.toLowerCase().includes(term)
        );

        if (filtered.length === 0) {
            elements.nodeList.innerHTML = '<div class="dropdown-empty">No nodes found</div>';
            return;
        }

        elements.nodeList.innerHTML = filtered.map(n => `
            <button type="button" class="dropdown-item ${n.uuid === state.selectedNode ? 'active' : ''}" data-uuid="${escapeAttr(n.uuid)}">
                ${escapeHtml(n.name)}
            </button>
        `).join('');

        elements.nodeList.querySelectorAll('.dropdown-item').forEach(item => {
            item.addEventListener('click', () => {
                selectNode(item.dataset.uuid);
            });
        });
    }

    function selectNode(uuid) {
        const prevNode = state.selectedNode;
        state.selectedNode = uuid;
        elements.nodeUuid.value = uuid;
        const node = config.nodes.find(n => n.uuid === uuid);
        elements.selectedNodeName.textContent = node ? node.name : 'Select node...';
        if (window.Chap && window.Chap.dropdown) {
            window.Chap.dropdown.close(elements.nodeDropdownMenu);
        }

        if (prevNode && prevNode !== uuid && state.ports.length > 0) {
            releaseReservation(prevNode);
            state.ports = [];
            renderPorts();
        }
        renderNodeList();
    }

    function ensureReservationUuid() {
        const existing = (elements.reservationUuid.value || '').trim();
        if (existing) {
            state.reservationUuid = existing;
            return existing;
        }

        const generated = (window.crypto && typeof window.crypto.randomUUID === 'function')
            ? window.crypto.randomUUID()
            : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                const r = Math.random() * 16 | 0;
                const v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });

        elements.reservationUuid.value = generated;
        state.reservationUuid = generated;
        return generated;
    }

    function reserveUrl(nodeUuid) {
        return `/environments/${encodeURIComponent(config.envUuid)}/nodes/${encodeURIComponent(nodeUuid)}/ports/reserve`;
    }

    function releaseUrl(nodeUuid) {
        return `/environments/${encodeURIComponent(config.envUuid)}/nodes/${encodeURIComponent(nodeUuid)}/ports/release`;
    }

    function renderPorts() {
        const ports = state.ports || [];
        if (!ports.length) {
            elements.portsEmpty.classList.remove('hidden');
            elements.portsList.innerHTML = '';
            return;
        }

        elements.portsEmpty.classList.add('hidden');
        elements.portsList.innerHTML = ports.map((p, idx) => `
            <div class="flex items-center justify-between gap-3 p-2 rounded-md border border-primary">
                <div class="min-w-0">
                    <div class="font-medium">${escapeHtml(String(p))}</div>
                    <div class="text-xs text-tertiary">Use {port[${idx}]}</div>
                </div>
            </div>
        `).join('');
    }

    async function allocateReservedPort() {
        if (!state.selectedNode) {
            if (window.Toast) window.Toast.error('Select a node first');
            return;
        }

        const reservationUuid = ensureReservationUuid();
        try {
            const res = await window.Chap.api(reserveUrl(state.selectedNode), 'POST', {
                reservation_uuid: reservationUuid
            });
            state.ports = res.ports || [];
            renderPorts();
        } catch (e) {
            if (window.Toast) window.Toast.error(e.message || 'Failed to allocate port');
        }
    }

    async function releaseReservation(nodeUuid) {
        const reservationUuid = ensureReservationUuid();
        try {
            await window.Chap.api(releaseUrl(nodeUuid), 'POST', {
                reservation_uuid: reservationUuid
            });
        } catch (e) {
            // best-effort
        }
    }

    // Environment Variables
    function parseInitialEnv() {
        state.envRows = [];
        try {
            const str = atob(config.initialEnvB64);
            if (!str) return;
            
            const lines = str.split(/\r?\n/);
            for (const line of lines) {
                const trimmed = line.trim();
                if (!trimmed || trimmed.startsWith('#')) continue;
                
                const idx = trimmed.indexOf('=');
                if (idx === -1) continue;
                
                const key = trimmed.substring(0, idx).trim();
                const value = trimmed.substring(idx + 1);
                state.envRows.push({ key, value, revealed: false });
            }
        } catch (e) {
            console.warn('Failed to parse initial env:', e);
        }
        updateEnvSerialized();
    }

    function addEnvRow(key = '', value = '') {
        state.envRows.push({ key, value, revealed: false });
        updateEnvSerialized();
    }

    function removeEnvRow(index) {
        state.envRows.splice(index, 1);
        renderEnvRows();
    }

    function updateEnvSerialized() {
        const serialized = state.envRows
            .filter(r => r.key)
            .map(r => r.key + '=' + r.value)
            .join('\n');
        elements.envSerialized.value = serialized;
    }

    function renderEnvRows() {
        if (state.envRows.length === 0) {
            elements.envEmpty.classList.remove('hidden');
            elements.envRows.innerHTML = '';
            return;
        }

        elements.envEmpty.classList.add('hidden');
        elements.envRows.innerHTML = state.envRows.map((row, idx) => `
            <div class="env-row flex flex-wrap items-center gap-2 p-2 rounded-md" data-index="${idx}">
                <input type="text" class="input input-sm env-key" placeholder="KEY" value="${escapeAttr(row.key)}" data-field="key">
                <input type="${row.revealed ? 'text' : 'password'}" class="input input-sm flex-1" placeholder="value" value="${escapeAttr(row.value)}" data-field="value">
                <button type="button" class="btn btn-ghost btn-sm toggle-reveal">${row.revealed ? 'Hide' : 'Show'}</button>
                <button type="button" class="btn btn-danger-ghost btn-sm remove-row">Remove</button>
            </div>
        `).join('');

        // Bind events
        elements.envRows.querySelectorAll('.env-row').forEach(row => {
            const idx = parseInt(row.dataset.index, 10);
            
            row.querySelector('[data-field="key"]').addEventListener('input', (e) => {
                state.envRows[idx].key = e.target.value;
                updateEnvSerialized();
            });
            
            row.querySelector('[data-field="value"]').addEventListener('input', (e) => {
                state.envRows[idx].value = e.target.value;
                updateEnvSerialized();
            });
            
            row.querySelector('.toggle-reveal').addEventListener('click', () => {
                state.envRows[idx].revealed = !state.envRows[idx].revealed;
                renderEnvRows();
            });
            
            row.querySelector('.remove-row').addEventListener('click', () => {
                removeEnvRow(idx);
            });
        });

        updateEnvSerialized();
    }

    function openBulkEditor() {
        const currentValue = state.envRows
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
            renderEnvRows();
        });
    }

    function parseEnvString(str) {
        state.envRows = [];
        if (!str) return;
        
        const lines = str.split(/\r?\n/);
        for (const line of lines) {
            const trimmed = line.trim();
            if (!trimmed || trimmed.startsWith('#')) continue;
            
            const idx = trimmed.indexOf('=');
            if (idx === -1) continue;
            
            const key = trimmed.substring(0, idx).trim();
            const value = trimmed.substring(idx + 1);
            state.envRows.push({ key, value, revealed: false });
        }
        
        updateEnvSerialized();
    }

    async function pullFromRepo() {
        showRepoError('');
        
        const repo = (elements.gitRepository.value || '').trim();
        const branch = (elements.gitBranch.value || 'main').trim();

        if (!repo) {
            showRepoError('Please enter a Git repository URL first.');
            return;
        }

        try {
            const url = config.repoEnvUrl + '?repo=' + encodeURIComponent(repo) + '&branch=' + encodeURIComponent(branch);
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const data = await res.json().catch(() => ({}));

            if (!res.ok) {
                showRepoError(data.error || 'Failed to pull environment variables from repository.');
                return;
            }

            const vars = data.vars || {};
            const existingKeys = new Set(state.envRows.map(r => (r.key || '').trim()).filter(Boolean));
            
            for (const [k, v] of Object.entries(vars)) {
                const key = String(k || '').trim();
                if (!key || existingKeys.has(key)) continue;
                state.envRows.push({ key, value: String(v ?? ''), revealed: false });
                existingKeys.add(key);
            }

            renderEnvRows();
        } catch (e) {
            showRepoError('Failed to pull environment variables. Please check the repo URL and try again.');
        }
    }

    function showRepoError(msg) {
        if (!msg) {
            elements.repoEnvError.textContent = '';
            elements.repoEnvError.classList.add('hidden');
        } else {
            elements.repoEnvError.textContent = msg;
            elements.repoEnvError.classList.remove('hidden');
        }
    }

    // Utilities
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
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

    // Initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
