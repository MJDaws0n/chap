<?php
/**
 * Application Live Logs View
 * Updated to use new design system with vanilla JavaScript
 */
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
                    <span class="breadcrumb-item"><a href="/applications/<?= e($application->uuid) ?>"><?= e($application->name) ?></a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current">Live Logs</span>
                </nav>

                <div class="flex items-center flex-wrap gap-3 mt-4">
                    <h1 class="page-header-title">Live Logs</h1>
                    <span class="badge <?= $statusColor ?>"><?= ucfirst($application->status) ?></span>
                </div>
                <p class="page-header-description truncate"><?= e($application->name) ?></p>
            </div>

            <div class="page-header-actions">
                <a href="/applications/<?= e($application->uuid) ?>" class="btn btn-secondary">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Back to Application
                </a>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Container Logs</h2>
                    <div class="flex items-center gap-3 flex-wrap">
                        <!-- Container Dropdown -->
                        <div class="dropdown" id="container-dropdown">
                            <button type="button" class="btn btn-secondary" id="container-select-btn" data-dropdown-trigger="container-dropdown-menu" data-dropdown-placement="bottom-start">
                                <span id="selected-container-name">Select container...</span>
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

                        <!-- Pause/Resume Button -->
                        <button type="button" class="btn btn-ghost btn-icon" id="pause-btn" title="Pause/Resume">
                            <svg class="icon icon-pause" viewBox="0 0 24 24" fill="currentColor">
                                <rect x="6" y="4" width="4" height="16" rx="1"/>
                                <rect x="14" y="4" width="4" height="16" rx="1"/>
                            </svg>
                            <svg class="icon icon-play hidden" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M8 5v14l11-7z"/>
                            </svg>
                        </button>

                        <!-- Reconnect Button -->
                        <button type="button" class="btn btn-secondary btn-sm" id="reconnect-btn">
                            Reconnect
                        </button>
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="logs-container" id="logs-container">
                        <div class="flex flex-col items-center justify-center h-full gap-3" id="logs-empty">
                            <p class="text-secondary text-sm">No logs available. Select a container to view logs.</p>
                        </div>
                        <div class="flex flex-col items-center justify-center h-full gap-3 hidden" id="logs-loading">
                            <div class="spinner"></div>
                            <p class="text-secondary text-sm">Loading logs…</p>
                        </div>
                        <div class="logs-content" id="logs-content"></div>
                    </div>

                    <div class="border-t border-primary p-4">
                        <div class="flex items-center gap-3">
                            <input
                                type="text"
                                class="input input-sm flex-1"
                                id="exec-input"
                                placeholder="Send command to container console…"
                                autocomplete="off"
                                spellcheck="false"
                            >
                            <button type="button" class="btn btn-secondary btn-sm" id="exec-send-btn">
                                Run
                            </button>
                        </div>
                        <p class="text-tertiary text-xs mt-2">
                            Sends input to the container's main process (stdin) via the node WebSocket.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-6">
            <!-- Actions Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Actions</h3>
                </div>
                <div class="card-body">
                    <div class="flex flex-col gap-3">
                        <form method="POST" action="/applications/<?= $application->uuid ?>/deploy" data-deploy-form>
                            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                            <button type="submit" class="btn btn-primary w-full" <?= $isDeploying ? 'disabled aria-disabled="true"' : '' ?>>
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                                <?= $isDeploying ? 'Redeploying…' : (in_array(($application->status ?? ''), ['running', 'restarting'], true) ? 'Redeploy' : 'Deploy') ?>
                            </button>
                        </form>
                        <?php if (in_array(($application->status ?? ''), ['running', 'restarting'], true)): ?>
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

            <!-- Settings Card -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Settings</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Tail Lines</label>
                        <div class="dropdown" id="tail-dropdown">
                            <button type="button" class="btn btn-secondary w-full" id="tail-select-btn" data-dropdown-trigger="tail-dropdown-menu" data-dropdown-placement="bottom-start">
                                <span id="tail-value">100 lines</span>
                                <svg class="icon dropdown-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div class="dropdown-menu" id="tail-dropdown-menu" style="min-width: 220px;">
                                <button type="button" class="dropdown-item" data-value="100">100 lines</button>
                                <button type="button" class="dropdown-item" data-value="500">500 lines</button>
                                <button type="button" class="dropdown-item" data-value="1000">1000 lines</button>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="checkbox">
                            <input type="checkbox" id="auto-scroll" checked>
                            <span>Auto-scroll to bottom</span>
                        </label>
                    </div>
                </div>
            </div>

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

.logs-content {
    display: flex;
    flex-direction: column;
}

/* Log Entry */
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
    min-width: 0;
    flex: 1;
    color: var(--text-secondary);
}

/* Log severity colors */
.log-entry.log-error .log-message {
    color: var(--accent-red);
}

.log-entry.log-warning .log-message {
    color: var(--accent-yellow);
}

.log-entry.log-success .log-message {
    color: var(--accent-green);
}

.log-entry.log-info .log-message {
    color: var(--text-secondary);
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

/* Pause button icons */
#pause-btn .icon-play { display: none; }
#pause-btn .icon-pause { display: block; }
#pause-btn.paused .icon-play { display: block; }
#pause-btn.paused .icon-pause { display: none; }
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
        logs: [],
        logsByContainer: {},
        seenKeysByContainer: {},
        loading: false,
        paused: false,
        isLive: false,
        tailSize: 100,
        autoScroll: true,
        maxStoredLogsPerContainer: 5000,
        
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
            hideLoading();
            return;
        }

        connectWebSocket();
    }

    function cacheElements() {
        elements.logsContainer = document.getElementById('logs-container');
        elements.logsContent = document.getElementById('logs-content');
        elements.logsEmpty = document.getElementById('logs-empty');
        elements.logsLoading = document.getElementById('logs-loading');
        elements.containerSelectBtn = document.getElementById('container-select-btn');
        elements.selectedContainerName = document.getElementById('selected-container-name');
        elements.containerDropdown = document.getElementById('container-dropdown');
        elements.containerDropdownMenu = document.getElementById('container-dropdown-menu');
        elements.containerSearch = document.getElementById('container-search');
        elements.containerList = document.getElementById('container-list');
        elements.statusDot = document.getElementById('status-dot');
        elements.statusText = document.getElementById('status-text');
        elements.pauseBtn = document.getElementById('pause-btn');
        elements.reconnectBtn = document.getElementById('reconnect-btn');
        elements.tailSelectBtn = document.getElementById('tail-select-btn');
        elements.tailValue = document.getElementById('tail-value');
        elements.tailDropdown = document.getElementById('tail-dropdown');
        elements.tailDropdownMenu = document.getElementById('tail-dropdown-menu');
        elements.autoScrollCheckbox = document.getElementById('auto-scroll');
        elements.containerInfoEmpty = document.getElementById('container-info-empty');
        elements.containerInfo = document.getElementById('container-info');
        elements.infoName = document.getElementById('info-name');
        elements.infoStatus = document.getElementById('info-status');
        elements.infoId = document.getElementById('info-id');

        // Exec
        elements.execInput = document.getElementById('exec-input');
        elements.execSendBtn = document.getElementById('exec-send-btn');
    }

    function bindEvents() {
        // Dropdowns are handled by the shared dropdown system (server/public/js/dropdown.js).
        // Keep page-specific behavior like focusing the search field.
        elements.containerDropdownMenu.addEventListener('dropdown:open', () => {
            // Defer to ensure menu is visible/positioned
            setTimeout(() => {
                elements.containerSearch.focus();
            }, 0);
        });

        elements.containerSearch.addEventListener('input', (e) => {
            filterContainers(e.target.value);
        });

        elements.tailDropdownMenu.querySelectorAll('.dropdown-item').forEach(item => {
            item.addEventListener('click', () => {
                const value = parseInt(item.dataset.value, 10);
                state.tailSize = value;
                elements.tailValue.textContent = value + ' lines';
                rebuildVisibleLogs();
            });
        });

        // Pause button
        elements.pauseBtn.addEventListener('click', togglePause);

        // Reconnect button
        elements.reconnectBtn.addEventListener('click', () => {
            connectWebSocket();
        });

        // Auto-scroll checkbox
        elements.autoScrollCheckbox.addEventListener('change', (e) => {
            state.autoScroll = e.target.checked;
        });

        // Cleanup on page unload
        window.addEventListener('beforeunload', destroy);

        // Exec
        elements.execInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                sendExecFromInput();
            }
        });
        elements.execSendBtn.addEventListener('click', () => {
            sendExecFromInput();
        });

        updateExecControls();
    }

    // WebSocket functions
    function connectWebSocket() {
        if (!config.logsWebsocketUrl) return;

        if (state.wsReconnectTimeout) {
            clearTimeout(state.wsReconnectTimeout);
            state.wsReconnectTimeout = null;
        }

        if (state.isConnecting) return;

        if (state.ws) {
            state.intentionalClose = true;
            try { state.ws.close(1000, 'Reconnect requested'); } catch (e) {}
        }

        stopKeepAlive();

        try {
            state.isConnecting = true;
            showLoading();
            showStatus('connecting', 'Connecting...');

            const ws = new WebSocket(config.logsWebsocketUrl);
            state.ws = ws;

            ws.onopen = () => {
                if (ws !== state.ws) return;
                state.intentionalClose = false;
                ws.send(JSON.stringify({
                    type: 'auth',
                    session_id: config.sessionId,
                    application_uuid: config.applicationUuid,
                    tail: Math.min(Math.max(state.tailSize, 0), 1000)
                }));
            };

            ws.onmessage = (event) => {
                if (ws !== state.ws) return;
                try {
                    handleWsMessage(JSON.parse(event.data));
                } catch (e) {
                    console.error('[Logs] Failed to parse WS message:', e);
                }
            };

            ws.onclose = (event) => {
                if (ws !== state.ws) return;
                console.log('[Logs] WebSocket closed:', event.code, event.reason);
                state.wsConnected = false;
                state.isLive = false;
                state.isConnecting = false;
                hideLoading();
                showStatus('disconnected', 'Disconnected');
                stopKeepAlive();
                updateExecControls();

                if (state.intentionalClose) {
                    state.intentionalClose = false;
                    return;
                }

                if (event.code !== 1000) {
                    state.wsReconnectTimeout = setTimeout(connectWebSocket, 3000);
                }
            };

            ws.onerror = (error) => {
                if (ws !== state.ws) return;
                console.error('[Logs] WebSocket error:', error);
                state.isConnecting = false;
                hideLoading();
                updateExecControls();
            };
        } catch (e) {
            console.error('[Logs] Failed to create WebSocket:', e);
            state.isConnecting = false;
            hideLoading();
            updateExecControls();
        }
    }

    function handleWsMessage(message) {
        switch (message.type) {
            case 'auth:success':
                state.wsConnected = true;
                state.isLive = !state.paused;
                state.isConnecting = false;
                hideLoading();
                showStatus('live', 'Live (WebSocket)');
                startKeepAlive();
                updateExecControls();
                break;

            case 'auth:failed':
                console.error('[Logs] Authentication failed:', message.error);
                state.wsConnected = false;
                state.isLive = false;
                state.isConnecting = false;
                hideLoading();
                showStatus('disconnected', 'Auth failed');
                updateExecControls();
                break;

            case 'containers':
                handleContainersMessage(message);
                break;

            case 'log':
                handleLogMessage(message);
                break;

            case 'pong':
                // Keep-alive response received
                break;

            case 'error':
                console.error('[Logs] Server error:', message.error);
                break;

            case 'exec:result':
                handleExecResultMessage(message);
                break;

            case 'exec:rejected':
                handleExecRejectedMessage(message);
                break;

            case 'console:output':
                handleConsoleOutputMessage(message);
                break;

            case 'console:rejected':
                handleConsoleRejectedMessage(message);
                break;

            case 'console:error':
                handleConsoleErrorMessage(message);
                break;

            case 'console:status':
                handleConsoleStatusMessage(message);
                break;
        }
    }

    function getSelectedContainerName() {
        const container = state.containers.find(c => c.id === state.selectedContainer);
        return container ? container.name : null;
    }

    function updateExecControls() {
        const enabled = !!(state.wsConnected && !state.paused && state.selectedContainer);
        elements.execInput.disabled = !enabled;
        elements.execSendBtn.disabled = !enabled;
    }

    function sendExecFromInput() {
        const cmd = (elements.execInput.value || '').trim();
        if (!cmd) return;
        if (!state.ws || state.ws.readyState !== WebSocket.OPEN) return;
        if (!state.wsConnected) return;
        if (state.paused) return;
        if (!state.selectedContainer) return;

        const requestId = `console_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
        const containerId = state.selectedContainer;
        const containerName = getSelectedContainerName();

        // Show the command immediately in the log stream.
        appendParsedLogLines('system', `$ ${cmd}`, containerId, containerName, null);

        try {
            state.ws.send(JSON.stringify({
                type: 'console:input',
                request_id: requestId,
                container_id: containerId,
                command: cmd,
                timestamp: Date.now(),
            }));
        } catch (e) {
            appendParsedLogLines('system', `[console] Failed to send: ${e.message}`, containerId, containerName, null);
            return;
        }

        elements.execInput.value = '';
        elements.execInput.focus();
    }

    function handleExecRejectedMessage(payload) {
        const containerId = normalizeContainerId(payload.container_id || payload.containerId) || state.selectedContainer || '__system__';
        const containerName = payload.container || payload.container_name || payload.containerName || getSelectedContainerName();
        const error = payload.error || 'Exec rejected';
        appendParsedLogLines('system', `[exec] ${error}`, containerId, containerName, null);
    }

    function handleExecResultMessage(payload) {
        const containerId = normalizeContainerId(payload.container_id || payload.containerId) || state.selectedContainer || '__system__';
        const containerName = payload.container || payload.container_name || payload.containerName || getSelectedContainerName();

        if (payload.error) {
            appendParsedLogLines('system', `[exec] ${payload.error}`, containerId, containerName, null);
        }

        const stdout = (payload.stdout || '').trimEnd();
        const stderr = (payload.stderr || '').trimEnd();
        if (stdout) appendParsedLogLines('system', stdout, containerId, containerName, null);
        if (stderr) appendParsedLogLines('system', stderr, containerId, containerName, null);

        if (payload.truncated) {
            appendParsedLogLines('system', '[exec] Output truncated', containerId, containerName, null);
        }

        if (payload.exit_code !== undefined && payload.exit_code !== null) {
            appendParsedLogLines('system', `[exec] Exit code: ${payload.exit_code}`, containerId, containerName, null);
        }
    }

    function handleConsoleOutputMessage(payload) {
        const stream = payload.stream || 'stdout';
        const content = payload.content ?? payload.data ?? '';
        if (!content) return;
        const containerId = normalizeContainerId(payload.container_id || payload.containerId) || '__system__';
        const containerName = payload.container || payload.container_name || payload.containerName || null;
        appendParsedLogLines(stream, content, containerId, containerName, payload.timestamp || null);
    }

    function handleConsoleRejectedMessage(payload) {
        const containerId = normalizeContainerId(payload.container_id || payload.containerId) || state.selectedContainer || '__system__';
        const containerName = payload.container || payload.container_name || payload.containerName || getSelectedContainerName();
        const error = payload.error || 'Console input rejected';
        appendParsedLogLines('system', `[console] ${error}`, containerId, containerName, null);
    }

    function handleConsoleErrorMessage(payload) {
        const containerId = normalizeContainerId(payload.container_id || payload.containerId) || state.selectedContainer || '__system__';
        const containerName = payload.container || payload.container_name || payload.containerName || getSelectedContainerName();
        const error = payload.error || 'Console error';
        appendParsedLogLines('system', `[console] ${error}`, containerId, containerName, null);
    }

    function handleConsoleStatusMessage(payload) {
        const containerId = normalizeContainerId(payload.container_id || payload.containerId) || state.selectedContainer || '__system__';
        const containerName = payload.container || payload.container_name || payload.containerName || getSelectedContainerName();
        const status = payload.status || 'unknown';
        appendParsedLogLines('system', `[console] ${status}`, containerId, containerName, null);
    }

    function handleContainersMessage(payload) {
        const incoming = Array.isArray(payload.containers) ? payload.containers : [];
        const normalized = incoming
            .map(c => ({
                id: normalizeContainerId(c.id || c.container_id),
                name: String(c.name || c.container || c.id || ''),
                status: String(c.status || 'running')
            }))
            .filter(c => c.id && c.name);

        const previous = state.selectedContainer;
        state.containers = normalized;

        if (!state.selectedContainer) {
            state.selectedContainer = normalized[0]?.id || null;
        } else if (!normalized.find(c => c.id === state.selectedContainer)) {
            state.selectedContainer = normalized[0]?.id || null;
        }

        renderContainerList();
        updateContainerInfo();

        if (previous !== state.selectedContainer) {
            rebuildVisibleLogs();
        }

        updateExecControls();
    }

    function handleLogMessage(payload) {
        const stream = payload.stream || 'stdout';
        const content = payload.content ?? payload.data ?? '';
        if (!content) return;

        const containerId = normalizeContainerId(payload.container_id || payload.containerId) || '__system__';
        const containerName = payload.container || payload.container_name || payload.containerName || null;
        const timestampOverride = payload.timestamp || null;

        appendParsedLogLines(stream, content, containerId, containerName, timestampOverride);
    }

    function appendParsedLogLines(stream, content, containerId, containerName, timestampOverride) {
        const raw = typeof content === 'string' ? content : String(content);
        const lines = raw.split('\n').filter(l => l.trim());
        const storeKey = containerId || '__system__';

        for (const line of lines) {
            let timestamp = '';
            let message = line;

            const timestampMatch = line.match(/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z?)\s+(.*)/);
            if (timestampMatch) {
                const ts = new Date(timestampMatch[1]);
                timestamp = isNaN(ts.getTime()) ? '' : ts.toLocaleTimeString();
                message = timestampMatch[2];
            }

            if (!timestamp) {
                if (timestampOverride) {
                    const overrideTs = new Date(timestampOverride);
                    timestamp = isNaN(overrideTs.getTime()) ? new Date().toLocaleTimeString() : overrideTs.toLocaleTimeString();
                } else {
                    timestamp = new Date().toLocaleTimeString();
                }
            }

            if (!state.seenKeysByContainer[storeKey]) state.seenKeysByContainer[storeKey] = new Set();
            const dedupeKey = `${timestamp}|${message}`;
            if (state.seenKeysByContainer[storeKey].has(dedupeKey)) continue;
            state.seenKeysByContainer[storeKey].add(dedupeKey);

            if (!state.logsByContainer[storeKey]) state.logsByContainer[storeKey] = [];
            const entry = {
                timestamp,
                message,
                stream,
                containerId: storeKey,
                containerName: containerName ? String(containerName) : null
            };

            state.logsByContainer[storeKey].push(entry);
            if (state.logsByContainer[storeKey].length > state.maxStoredLogsPerContainer) {
                state.logsByContainer[storeKey] = state.logsByContainer[storeKey].slice(-state.maxStoredLogsPerContainer);
            }

            if (shouldIncludeLog(entry)) {
                state.logs.push(entry);
                const max = state.tailSize;
                if (state.logs.length > max * 3) {
                    state.logs = state.logs.slice(-max * 3);
                }
                appendLogEntry(entry);
                if (state.autoScroll && !state.paused) scrollToBottom();
            }
        }
    }

    function shouldIncludeLog(logEntry) {
        if (!state.selectedContainer) return logEntry.containerId === '__system__';
        return logEntry.containerId === state.selectedContainer;
    }

    function rebuildVisibleLogs() {
        const key = state.selectedContainer || '__system__';
        state.logs = (state.logsByContainer[key] || []).slice();

        const max = state.tailSize;
        if (state.logs.length > max * 3) {
            state.logs = state.logs.slice(-max * 3);
        }

        renderLogs();
        if (state.autoScroll && !state.paused) scrollToBottom();
    }

    // UI Functions
    function renderContainerList() {
        const filtered = filterContainersBySearch('');
        renderFilteredContainers(filtered);
        updateSelectedContainerName();
    }

    function filterContainers(searchTerm) {
        const filtered = filterContainersBySearch(searchTerm);
        renderFilteredContainers(filtered);
    }

    function filterContainersBySearch(term) {
        if (!term) return state.containers;
        const lowerTerm = term.toLowerCase();
        return state.containers.filter(c => 
            (c.name || '').toLowerCase().includes(lowerTerm)
        );
    }

    function renderFilteredContainers(containers) {
        if (containers.length === 0) {
            elements.containerList.innerHTML = '<div class="dropdown-empty">No containers found</div>';
            return;
        }

        elements.containerList.innerHTML = containers.map(c => `
            <button type="button" class="dropdown-item ${c.id === state.selectedContainer ? 'active' : ''}" data-id="${escapeHtml(c.id)}">
                <span class="truncate">${escapeHtml(c.name)}</span>
                <span class="badge badge-sm ${c.status === 'running' ? 'badge-success' : 'badge-neutral'}">${escapeHtml(c.status)}</span>
            </button>
        `).join('');

        // Bind click events
        elements.containerList.querySelectorAll('.dropdown-item').forEach(item => {
            item.addEventListener('click', () => {
                const id = item.dataset.id;
                selectContainer(id);
            });
        });
    }

    function selectContainer(id) {
        state.selectedContainer = id;
        updateSelectedContainerName();
        updateContainerInfo();
        rebuildVisibleLogs();
        renderContainerList();
    }

    function updateSelectedContainerName() {
        const container = state.containers.find(c => c.id === state.selectedContainer);
        elements.selectedContainerName.textContent = container ? container.name : 'Select container...';
    }

    function updateContainerInfo() {
        const container = state.containers.find(c => c.id === state.selectedContainer);
        if (container) {
            elements.containerInfoEmpty.classList.add('hidden');
            elements.containerInfo.classList.remove('hidden');
            elements.infoName.textContent = container.name;
            elements.infoStatus.textContent = container.status;
            elements.infoStatus.className = 'badge badge-sm ' + (container.status === 'running' ? 'badge-success' : 'badge-neutral');
            elements.infoId.textContent = (container.id || '').substring(0, 12);
        } else {
            elements.containerInfoEmpty.classList.remove('hidden');
            elements.containerInfo.classList.add('hidden');
        }
    }

    function renderLogs() {
        if (state.logs.length === 0) {
            elements.logsEmpty.classList.remove('hidden');
            elements.logsContent.innerHTML = '';
            return;
        }

        elements.logsEmpty.classList.add('hidden');
        elements.logsContent.innerHTML = state.logs.map(log => createLogEntryHtml(log)).join('');
    }

    function appendLogEntry(log) {
        elements.logsEmpty.classList.add('hidden');
        elements.logsContent.insertAdjacentHTML('beforeend', createLogEntryHtml(log));
    }

    function createLogEntryHtml(log) {
        const severityClass = getLogSeverityClass(log.message);
        return `
            <div class="log-entry ${severityClass}">
                <span class="log-timestamp">${escapeHtml(log.timestamp)}</span>
                <span class="log-message">${escapeHtml(log.message)}</span>
            </div>
        `;
    }

    function getLogSeverityClass(message) {
        const lower = (message || '').toLowerCase();
        if (lower.includes('error') || lower.includes('failed') || lower.includes('exception')) {
            return 'log-error';
        } else if (lower.includes('warning') || lower.includes('warn')) {
            return 'log-warning';
        } else if (lower.includes('success') || lower.includes('completed') || lower.includes('✓') || lower.includes('✅')) {
            return 'log-success';
        }
        return 'log-info';
    }

    function scrollToBottom() {
        requestAnimationFrame(() => {
            elements.logsContainer.scrollTop = elements.logsContainer.scrollHeight;
        });
    }

    function showLoading() {
        state.loading = true;
        elements.logsLoading.classList.remove('hidden');
        elements.logsEmpty.classList.add('hidden');
    }

    function hideLoading() {
        state.loading = false;
        elements.logsLoading.classList.add('hidden');
        if (state.logs.length === 0) {
            elements.logsEmpty.classList.remove('hidden');
        }
    }

    function showStatus(type, text) {
        elements.statusDot.className = 'status-dot ' + type;
        elements.statusText.textContent = text;
    }

    function togglePause() {
        state.paused = !state.paused;
        state.isLive = state.wsConnected && !state.paused;
        elements.pauseBtn.classList.toggle('paused', state.paused);
        
        if (state.paused) {
            showStatus('disconnected', 'Paused');
        } else if (state.wsConnected) {
            showStatus('live', 'Live (WebSocket)');
        }

        updateExecControls();
    }

    // Keep-alive
    function startKeepAlive() {
        stopKeepAlive();
        state.keepAliveTimer = setInterval(() => {
            try {
                if (state.ws && state.ws.readyState === WebSocket.OPEN) {
                    state.ws.send(JSON.stringify({ type: 'ping', timestamp: Date.now() }));
                }
            } catch (e) {}
        }, state.keepAliveIntervalMs);
    }

    function stopKeepAlive() {
        if (state.keepAliveTimer) {
            clearInterval(state.keepAliveTimer);
            state.keepAliveTimer = null;
        }
    }

    // Utilities
    function normalizeContainerId(value) {
        if (value === undefined || value === null) return null;
        return String(value);
    }

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function destroy() {
        if (state.ws) state.ws.close(1000, 'Page unload');
        if (state.wsReconnectTimeout) clearTimeout(state.wsReconnectTimeout);
        stopKeepAlive();
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
