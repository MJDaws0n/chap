<?php
/**
 * Application Bash Execution View
 * Allows running bash commands inside containers via WebSocket
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
?>

<div class="flex flex-col gap-6">
    <?php $activeTab = 'bash'; ?>
    <?php include __DIR__ . '/_header_tabs.php'; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Bash Execution</h2>
                    <div class="flex items-center gap-3 flex-wrap">
                        <!-- Container Dropdown -->
                        <div class="dropdown" id="container-dropdown">
                            <button type="button" class="btn btn-secondary w-full sm:w-auto min-w-0" id="container-select-btn" data-dropdown-trigger="container-dropdown-menu" data-dropdown-placement="bottom-start">
                                <span class="break-all" id="selected-container-name">Select container...</span>
                                <svg class="icon dropdown-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div class="dropdown-menu" id="container-dropdown-menu" style="min-width: 320px;">
                                <div class="p-3 border-b border-primary">
                                    <input type="text" class="input input-sm" placeholder="Search containers..." id="container-search" autocomplete="off">
                                </div>
                                <div class="dropdown-items" id="container-list">
                                    <div class="dropdown-empty">No containers available</div>
                                </div>
                            </div>
                        </div>

                        <!-- Connection Status -->
                        <div class="flex items-center gap-2 text-sm text-secondary" id="connection-status">
                            <span class="status-dot" id="status-dot"></span>
                            <span class="status-text" id="status-text">Connecting...</span>
                        </div>

                        <!-- Clear Output Button -->
                        <button type="button" class="btn btn-ghost btn-sm" id="clear-btn" title="Clear output">
                            Clear
                        </button>

                        <!-- Reconnect Button -->
                        <button type="button" class="btn btn-secondary btn-sm" id="reconnect-btn">
                            Reconnect
                        </button>
                    </div>
                </div>

                <div class="card-body p-0">
                    <div id="bash-output-wrap">
                        <div class="bash-container" id="bash-container">
                            <div class="flex flex-col items-center justify-center h-full gap-3" id="bash-empty">
                                <svg class="icon icon-lg text-tertiary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                <p class="text-secondary text-sm">Select a container to run bash commands.</p>
                            </div>
                            <div class="flex flex-col items-center justify-center h-full gap-3 hidden" id="bash-loading">
                                <div class="spinner"></div>
                                <p class="text-secondary text-sm">Connecting...</p>
                            </div>
                            <div class="bash-content" id="bash-content"></div>
                        </div>

                        <div class="border-t border-primary p-4" id="bash-input-wrap">
                            <div class="flex items-center gap-3">
                                <span class="text-tertiary font-mono">$</span>
                                <input
                                    type="text"
                                    class="input input-sm flex-1 min-w-0 font-mono"
                                    id="bash-input"
                                    placeholder="Enter command..."
                                    autocomplete="off"
                                    spellcheck="false"
                                    disabled
                                >
                                <button type="button" class="btn btn-primary btn-sm" id="bash-send-btn" disabled>
                                    Run
                                </button>
                            </div>
                            <p class="text-tertiary text-xs mt-2">
                                Executes bash commands directly in the container. Commands are logged for audit purposes.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-6">
            <!-- Container Info Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Container Info</h3>
                </div>
                <div class="card-body">
                    <div id="container-info-empty">
                        <p class="text-secondary text-sm">Select a container</p>
                    </div>
                    <div id="container-info" class="hidden">
                        <dl class="flex flex-col gap-4">
                            <div class="flex items-center justify-between gap-4 text-sm">
                                <dt class="text-tertiary">Name</dt>
                                <dd id="info-name" class="m-0 text-primary truncate">-</dd>
                            </div>
                            <div class="flex items-center justify-between gap-4 text-sm">
                                <dt class="text-tertiary">Status</dt>
                                <dd class="m-0"><span id="info-status" class="badge badge-sm">-</span></dd>
                            </div>
                            <div class="flex items-center justify-between gap-4 text-sm">
                                <dt class="text-tertiary">ID</dt>
                                <dd class="m-0"><code id="info-id" class="code-inline">-</code></dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

            <!-- Security Notice Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Security Notice</h3>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning mb-0">
                        <svg class="alert-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <div>
                            <p class="font-medium">Commands are logged</p>
                            <p class="text-sm mt-1">All bash commands and their output are logged for security audit purposes.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Command Limits Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Limits</h3>
                </div>
                <div class="card-body">
                    <dl class="flex flex-col gap-3 text-sm">
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-tertiary">Max command length</dt>
                            <dd class="m-0 text-primary">1000 chars</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-tertiary">Output limit</dt>
                            <dd class="m-0 text-primary">128 KB</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-tertiary">Timeout</dt>
                            <dd class="m-0 text-primary">30 seconds</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-tertiary">Rate limit</dt>
                            <dd class="m-0 text-primary">3 / 10 sec</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Command History Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">History</h3>
                </div>
                <div class="card-body">
                    <div id="history-empty">
                        <p class="text-secondary text-sm">No commands executed yet</p>
                    </div>
                    <div id="history-list" class="hidden flex flex-col gap-2 max-h-48 overflow-y-auto">
                        <!-- History items will be inserted here -->
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--accent-gray);
    transition: var(--transition-colors);
}

.status-dot.live {
    background: var(--accent-green);
    animation: pulse 2s infinite;
}

.status-dot.connecting {
    background: var(--accent-yellow);
    animation: pulse 1s infinite;
}

.status-dot.disconnected {
    background: var(--accent-gray);
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* Bash Container */
.bash-container {
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

.bash-content {
    display: flex;
    flex-direction: column;
}

/* Bash Entry */
.bash-entry {
    display: flex;
    flex-direction: column;
    padding: var(--space-2) 0;
    min-width: 0;
    border-bottom: 1px solid var(--border-primary);
}

.bash-entry:last-child {
    border-bottom: none;
}

.bash-command {
    color: var(--accent-blue);
    font-weight: 500;
    margin-bottom: var(--space-2);
}

.bash-command::before {
    content: '$ ';
    color: var(--text-tertiary);
}

.bash-output {
    white-space: pre-wrap;
    word-break: break-all;
    overflow-wrap: anywhere;
    min-width: 0;
    color: var(--text-secondary);
}

.bash-output.error {
    color: var(--accent-red);
}

.bash-output.system {
    color: var(--text-tertiary);
    font-style: italic;
}

.bash-exit-code {
    font-size: var(--text-xs);
    color: var(--text-tertiary);
    margin-top: var(--space-2);
}

.bash-exit-code.success {
    color: var(--accent-green);
}

.bash-exit-code.failure {
    color: var(--accent-red);
}

/* Dropdown Search */
.dropdown-items {
    max-height: 240px;
    overflow-y: auto;
}

.dropdown-empty {
    padding: var(--space-4);
    text-align: center;
    color: var(--text-tertiary);
    font-size: var(--text-sm);
}

.code-inline {
    font-family: var(--font-mono);
    font-size: var(--text-xs);
    background: var(--bg-tertiary);
    border: 1px solid var(--border-primary);
    padding: var(--space-1) var(--space-2);
    border-radius: var(--radius-sm);
}

/* History items */
.history-item {
    font-family: var(--font-mono);
    font-size: var(--text-xs);
    padding: var(--space-2);
    background: var(--bg-secondary);
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: var(--transition-colors);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.history-item:hover {
    background: var(--bg-tertiary);
}
</style>

<script>
(function() {
    'use strict';

    // Configuration from PHP
    const config = {
        logsWebsocketUrl: <?= json_encode($logsWebsocketUrl ?? null) ?>,
        sessionId: <?= json_encode($sessionId ?? '') ?>,
        applicationUuid: '<?= $application->uuid ?>'
    };

    // State
    const state = {
        containers: [],
        selectedContainer: null,
        history: [],
        maxHistory: 20,
        
        // WebSocket
        ws: null,
        wsConnected: false,
        wsReconnectTimeout: null,
        isConnecting: false,
        intentionalClose: false,
        keepAliveTimer: null,
        keepAliveIntervalMs: 25000
    };

    // DOM Elements
    const elements = {};

    function init() {
        cacheElements();
        bindEvents();
        
        if (!config.logsWebsocketUrl) {
            showStatus('disconnected', 'WebSocket not configured');
            return;
        }

        connectWebSocket();
    }

    function cacheElements() {
        elements.bashContainer = document.getElementById('bash-container');
        elements.bashContent = document.getElementById('bash-content');
        elements.bashEmpty = document.getElementById('bash-empty');
        elements.bashLoading = document.getElementById('bash-loading');
        elements.containerSelectBtn = document.getElementById('container-select-btn');
        elements.selectedContainerName = document.getElementById('selected-container-name');
        elements.containerDropdown = document.getElementById('container-dropdown');
        elements.containerDropdownMenu = document.getElementById('container-dropdown-menu');
        elements.containerSearch = document.getElementById('container-search');
        elements.containerList = document.getElementById('container-list');
        elements.statusDot = document.getElementById('status-dot');
        elements.statusText = document.getElementById('status-text');
        elements.clearBtn = document.getElementById('clear-btn');
        elements.reconnectBtn = document.getElementById('reconnect-btn');
        elements.containerInfoEmpty = document.getElementById('container-info-empty');
        elements.containerInfo = document.getElementById('container-info');
        elements.infoName = document.getElementById('info-name');
        elements.infoStatus = document.getElementById('info-status');
        elements.infoId = document.getElementById('info-id');
        elements.bashInput = document.getElementById('bash-input');
        elements.bashSendBtn = document.getElementById('bash-send-btn');
        elements.historyEmpty = document.getElementById('history-empty');
        elements.historyList = document.getElementById('history-list');
    }

    function bindEvents() {
        elements.containerDropdownMenu.addEventListener('dropdown:open', () => {
            setTimeout(() => {
                elements.containerSearch.focus();
            }, 0);
        });

        elements.containerSearch.addEventListener('input', (e) => {
            filterContainers(e.target.value);
        });

        elements.clearBtn.addEventListener('click', clearOutput);
        elements.reconnectBtn.addEventListener('click', reconnect);

        elements.bashInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendCommand();
            }
            // Arrow up for history
            if (e.key === 'ArrowUp' && state.history.length > 0) {
                e.preventDefault();
                elements.bashInput.value = state.history[state.history.length - 1];
            }
        });

        elements.bashSendBtn.addEventListener('click', sendCommand);
    }

    function normalizeContainerId(idRaw) {
        const id = String(idRaw || '').trim().toLowerCase();
        return id.length >= 12 ? id.slice(0, 12) : (id || null);
    }

    function showStatus(status, text) {
        elements.statusDot.className = 'status-dot ' + status;
        elements.statusText.textContent = text;
    }

    function showLoading() {
        elements.bashEmpty.classList.add('hidden');
        elements.bashLoading.classList.remove('hidden');
        elements.bashContent.classList.add('hidden');
    }

    function hideLoading() {
        elements.bashLoading.classList.add('hidden');
    }

    function showContent() {
        elements.bashEmpty.classList.add('hidden');
        elements.bashLoading.classList.add('hidden');
        elements.bashContent.classList.remove('hidden');
    }

    function clearOutput() {
        elements.bashContent.innerHTML = '';
        elements.bashEmpty.classList.remove('hidden');
        elements.bashContent.classList.add('hidden');
    }

    function updateControls() {
        const enabled = !!(state.wsConnected && state.selectedContainer);
        elements.bashInput.disabled = !enabled;
        elements.bashSendBtn.disabled = !enabled;
        
        if (enabled) {
            elements.bashInput.focus();
        }
    }

    function connectWebSocket() {
        if (state.ws && state.ws.readyState === WebSocket.OPEN) return;
        if (state.isConnecting) return;

        state.isConnecting = true;
        state.intentionalClose = false;
        showStatus('connecting', 'Connecting...');

        const url = new URL(config.logsWebsocketUrl);
        url.searchParams.set('session_id', config.sessionId);
        url.searchParams.set('application_uuid', config.applicationUuid);

        try {
            state.ws = new WebSocket(url.toString());
        } catch (e) {
            showStatus('disconnected', 'Failed to connect');
            state.isConnecting = false;
            scheduleReconnect();
            return;
        }

        state.ws.onopen = () => {
            state.isConnecting = false;
            state.wsConnected = true;
            showStatus('live', 'Connected');
            startKeepAlive();
            updateControls();
            // Request containers list after connecting
            try {
                state.ws.send(JSON.stringify({ type: 'containers:request' }));
            } catch (e) {}
        };

        state.ws.onmessage = (event) => {
            try {
                const data = JSON.parse(event.data);
                handleMessage(data);
            } catch (e) {
                // ignore parse errors
            }
        };

        state.ws.onclose = () => {
            state.isConnecting = false;
            state.wsConnected = false;
            stopKeepAlive();
            showStatus('disconnected', 'Disconnected');
            updateControls();
            if (!state.intentionalClose) {
                scheduleReconnect();
            }
        };

        state.ws.onerror = () => {
            state.isConnecting = false;
            showStatus('disconnected', 'Connection error');
        };
    }

    function scheduleReconnect() {
        if (state.wsReconnectTimeout) return;
        state.wsReconnectTimeout = setTimeout(() => {
            state.wsReconnectTimeout = null;
            connectWebSocket();
        }, 3000);
    }

    function reconnect() {
        state.intentionalClose = true;
        if (state.ws) {
            try { state.ws.close(); } catch {}
        }
        state.ws = null;
        if (state.wsReconnectTimeout) {
            clearTimeout(state.wsReconnectTimeout);
            state.wsReconnectTimeout = null;
        }
        connectWebSocket();
    }

    function startKeepAlive() {
        stopKeepAlive();
        state.keepAliveTimer = setInterval(() => {
            if (state.ws && state.ws.readyState === WebSocket.OPEN) {
                try {
                    state.ws.send(JSON.stringify({ type: 'ping' }));
                } catch {}
            }
        }, state.keepAliveIntervalMs);
    }

    function stopKeepAlive() {
        if (state.keepAliveTimer) {
            clearInterval(state.keepAliveTimer);
            state.keepAliveTimer = null;
        }
    }

    function handleMessage(data) {
        const type = data.type || '';

        switch (type) {
            case 'containers':
                handleContainersMessage(data);
                break;
            case 'exec:result':
                handleExecResultMessage(data);
                break;
            case 'exec:rejected':
                handleExecRejectedMessage(data);
                break;
            case 'pong':
                // keep-alive response
                break;
        }
    }

    function handleContainersMessage(payload) {
        const incoming = Array.isArray(payload.containers) ? payload.containers : [];
        const prettifyName = (rawName) => {
            const name = String(rawName || '').replace(/^\//, '');
            const appUuid = String(config.applicationUuid || '').trim();
            if (appUuid) {
                const a = `chap-${appUuid}-`;
                const b = `chap-${appUuid}_`;
                if (name.startsWith(a)) return name.slice(a.length);
                if (name.startsWith(b)) return name.slice(b.length);
            }
            const m = name.match(/^chap-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}[-_](.+)$/i);
            if (m && m[1]) return m[1];
            return name;
        };

        const normalized = incoming
            .map(c => ({
                id: normalizeContainerId(c.id || c.container_id),
                name: prettifyName(c.name || c.container || c.id || ''),
                status: String(c.status || 'running')
            }))
            .filter(c => c.id && c.name);

        state.containers = normalized;

        if (!state.selectedContainer && normalized.length > 0) {
            state.selectedContainer = normalized[0].id;
        } else if (state.selectedContainer && !normalized.find(c => c.id === state.selectedContainer)) {
            state.selectedContainer = normalized[0]?.id || null;
        }

        renderContainerList();
        updateContainerInfo();
        updateControls();
        hideLoading();
    }

    function handleExecResultMessage(payload) {
        // Find and update the pending entry if it exists
        const requestId = payload.request_id;
        const pendingEntry = requestId ? elements.bashContent.querySelector(`[data-request-id="${requestId}"]`) : null;
        
        if (pendingEntry) {
            // Remove the "Executing..." placeholder
            const loadingEl = pendingEntry.querySelector('[data-loading="true"]');
            if (loadingEl) loadingEl.remove();
            
            // Add results to existing entry
            appendResultToEntry(pendingEntry, payload);
        } else {
            // Create new entry if no pending one found
            showContent();
            
            const entry = document.createElement('div');
            entry.className = 'bash-entry';
            
            if (payload.command) {
                const cmdEl = document.createElement('div');
                cmdEl.className = 'bash-command';
                cmdEl.textContent = payload.command;
                entry.appendChild(cmdEl);
            }
            
            appendResultToEntry(entry, payload);
            elements.bashContent.appendChild(entry);
        }
        
        elements.bashContainer.scrollTop = elements.bashContainer.scrollHeight;
    }
    
    function appendResultToEntry(entry, payload) {
        const stdout = (payload.stdout || '').trimEnd();
        const stderr = (payload.stderr || '').trimEnd();
        const exitCode = payload.exit_code ?? null;
        const truncated = payload.truncated;
        const error = payload.error;

        if (error) {
            const errEl = document.createElement('div');
            errEl.className = 'bash-output error';
            errEl.textContent = error;
            entry.appendChild(errEl);
        }
        
        if (stdout) {
            const outEl = document.createElement('div');
            outEl.className = 'bash-output';
            outEl.textContent = stdout;
            entry.appendChild(outEl);
        }

        if (stderr) {
            const errEl = document.createElement('div');
            errEl.className = 'bash-output error';
            errEl.textContent = stderr;
            entry.appendChild(errEl);
        }

        if (truncated) {
            const truncEl = document.createElement('div');
            truncEl.className = 'bash-output system';
            truncEl.textContent = '[Output truncated]';
            entry.appendChild(truncEl);
        }

        if (exitCode !== null && exitCode !== undefined) {
            const exitEl = document.createElement('div');
            exitEl.className = 'bash-exit-code ' + (exitCode === 0 ? 'success' : 'failure');
            exitEl.textContent = 'Exit code: ' + exitCode;
            entry.appendChild(exitEl);
        }
    }

    function handleExecRejectedMessage(payload) {
        showContent();

        const entry = document.createElement('div');
        entry.className = 'bash-entry';

        const errEl = document.createElement('div');
        errEl.className = 'bash-output error';
        errEl.textContent = payload.error || 'Command rejected';
        entry.appendChild(errEl);

        elements.bashContent.appendChild(entry);
        elements.bashContainer.scrollTop = elements.bashContainer.scrollHeight;
    }

    function sendCommand() {
        const cmd = (elements.bashInput.value || '').trim();
        if (!cmd) return;
        if (!state.ws || state.ws.readyState !== WebSocket.OPEN) return;
        if (!state.wsConnected) return;
        if (!state.selectedContainer) return;

        // Add to history
        if (state.history[state.history.length - 1] !== cmd) {
            state.history.push(cmd);
            if (state.history.length > state.maxHistory) {
                state.history.shift();
            }
            updateHistoryUI();
        }

        const requestId = `bash_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;

        try {
            state.ws.send(JSON.stringify({
                type: 'exec:request',
                request_id: requestId,
                container_id: state.selectedContainer,
                command: cmd,
                timestamp: Date.now(),
            }));
        } catch (e) {
            showContent();
            const entry = document.createElement('div');
            entry.className = 'bash-entry';
            const errEl = document.createElement('div');
            errEl.className = 'bash-output error';
            errEl.textContent = 'Failed to send command: ' + e.message;
            entry.appendChild(errEl);
            elements.bashContent.appendChild(entry);
            return;
        }

        // Show the command immediately
        showContent();
        const entry = document.createElement('div');
        entry.className = 'bash-entry';
        entry.dataset.requestId = requestId;
        
        const cmdEl = document.createElement('div');
        cmdEl.className = 'bash-command';
        cmdEl.textContent = cmd;
        entry.appendChild(cmdEl);
        
        const loadingEl = document.createElement('div');
        loadingEl.className = 'bash-output system';
        loadingEl.textContent = 'Executing...';
        loadingEl.dataset.loading = 'true';
        entry.appendChild(loadingEl);
        
        elements.bashContent.appendChild(entry);
        elements.bashContainer.scrollTop = elements.bashContainer.scrollHeight;

        elements.bashInput.value = '';
        elements.bashInput.focus();
    }

    function renderContainerList() {
        const search = (elements.containerSearch.value || '').toLowerCase().trim();
        const filtered = state.containers.filter(c => 
            !search || c.name.toLowerCase().includes(search) || c.id.includes(search)
        );

        if (filtered.length === 0) {
            elements.containerList.innerHTML = '<div class="dropdown-empty">No containers found</div>';
            return;
        }

        elements.containerList.innerHTML = filtered.map(c => {
            const isSelected = c.id === state.selectedContainer;
            const statusBadge = c.status === 'running' ? 'badge-success' : 
                               c.status === 'restarting' ? 'badge-warning' : 'badge-neutral';
            return `
                <button type="button" class="dropdown-item ${isSelected ? 'active' : ''}" data-container-id="${c.id}">
                    <div class="flex items-center justify-between gap-4 w-full min-w-0">
                        <span class="truncate">${escapeHtml(c.name)}</span>
                        <span class="badge badge-sm ${statusBadge}">${escapeHtml(c.status)}</span>
                    </div>
                </button>
            `;
        }).join('');

        elements.containerList.querySelectorAll('.dropdown-item').forEach(item => {
            item.addEventListener('click', () => {
                const containerId = item.dataset.containerId;
                if (containerId) {
                    selectContainer(containerId);
                }
            });
        });
    }

    function selectContainer(containerId) {
        state.selectedContainer = containerId;
        renderContainerList();
        updateContainerInfo();
        updateControls();
        clearOutput();

        // Close dropdown
        elements.containerDropdownMenu.classList.remove('show');
    }

    function updateContainerInfo() {
        const container = state.containers.find(c => c.id === state.selectedContainer);
        
        if (!container) {
            elements.containerInfoEmpty.classList.remove('hidden');
            elements.containerInfo.classList.add('hidden');
            elements.selectedContainerName.textContent = 'Select container...';
            return;
        }

        elements.containerInfoEmpty.classList.add('hidden');
        elements.containerInfo.classList.remove('hidden');
        elements.selectedContainerName.textContent = container.name;
        elements.infoName.textContent = container.name;
        elements.infoId.textContent = container.id;
        
        const statusBadge = container.status === 'running' ? 'badge-success' :
                           container.status === 'restarting' ? 'badge-warning' : 'badge-neutral';
        elements.infoStatus.className = `badge badge-sm ${statusBadge}`;
        elements.infoStatus.textContent = container.status;
    }

    function filterContainers(search) {
        renderContainerList();
    }

    function updateHistoryUI() {
        if (state.history.length === 0) {
            elements.historyEmpty.classList.remove('hidden');
            elements.historyList.classList.add('hidden');
            return;
        }

        elements.historyEmpty.classList.add('hidden');
        elements.historyList.classList.remove('hidden');

        const reversed = [...state.history].reverse();
        elements.historyList.innerHTML = reversed.map((cmd, i) => `
            <div class="history-item" data-index="${state.history.length - 1 - i}" title="${escapeHtml(cmd)}">
                ${escapeHtml(cmd)}
            </div>
        `).join('');

        elements.historyList.querySelectorAll('.history-item').forEach(item => {
            item.addEventListener('click', () => {
                const idx = parseInt(item.dataset.index, 10);
                if (state.history[idx]) {
                    elements.bashInput.value = state.history[idx];
                    elements.bashInput.focus();
                }
            });
        });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
