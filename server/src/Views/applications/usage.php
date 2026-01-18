<?php
/**
 * Application Usage View
 * Real-time CPU/RAM/Network charts via the node browser WebSocket.
 */
/** @var \Chap\Models\Application $application */
?>

<div class="flex flex-col gap-6">
    <?php $activeTab = 'usage'; ?>
    <?php include __DIR__ . '/_header_tabs.php'; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 flex flex-col gap-6">
            <div class="card">
                <div class="card-header">
                    <div class="flex items-center justify-between gap-3 flex-wrap w-full">
                        <div>
                            <h2 class="card-title">Usage Charts</h2>
                            <p class="text-secondary text-sm">Live metrics from Docker stats (about 1s cadence).</p>
                        </div>
                        <div class="flex items-center gap-2 text-sm text-secondary" id="connection-status">
                            <span class="status-dot" id="status-dot"></span>
                            <span class="status-text" id="status-text">Connecting...</span>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="grid grid-cols-1 gap-6">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <p class="font-medium">CPU</p>
                                <p id="cpu-live" class="text-sm text-secondary">-</p>
                            </div>
                            <canvas id="cpu-chart" class="usage-chart" height="120"></canvas>
                        </div>

                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <p class="font-medium">RAM</p>
                                <p id="ram-live" class="text-sm text-secondary">-</p>
                            </div>
                            <canvas id="ram-chart" class="usage-chart" height="120"></canvas>
                        </div>

                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <p class="font-medium">Network</p>
                                <p id="net-live" class="text-sm text-secondary">-</p>
                            </div>
                            <canvas id="net-chart" class="usage-chart" height="120"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Settings</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Container</label>
                        <div class="dropdown" id="container-dropdown">
                            <button type="button" class="btn btn-secondary w-full" id="container-select-btn" data-dropdown-trigger="container-dropdown-menu" data-dropdown-placement="bottom-start">
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
                    </div>

                    <div class="form-group">
                        <label class="form-label">Timeframe</label>
                        <div class="dropdown" id="range-dropdown">
                            <button type="button" class="btn btn-secondary w-full" id="range-select-btn" data-dropdown-trigger="range-dropdown-menu" data-dropdown-placement="bottom-start">
                                <span id="range-value">Last 1 minute</span>
                                <svg class="icon dropdown-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div class="dropdown-menu" id="range-dropdown-menu" style="min-width: 220px;">
                                <button type="button" class="dropdown-item" data-seconds="60">Last 1 minute</button>
                                <button type="button" class="dropdown-item" data-seconds="300">Last 5 minutes</button>
                                <button type="button" class="dropdown-item" data-seconds="900">Last 15 minutes</button>
                            </div>
                        </div>
                    </div>

                    <button type="button" class="btn btn-secondary w-full" id="reconnect-btn">Reconnect</button>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Configured Limits</h3>
                </div>
                <div class="card-body">
                    <?php
                        $cpuCores = ($application->cpu_millicores_limit ?? -1) > 0 ? ((float)$application->cpu_millicores_limit / 1000.0) : null;
                        $cpuLabel = $cpuCores !== null ? rtrim(rtrim(number_format($cpuCores, 3, '.', ''), '0'), '.') . ' CPU' : 'Auto';

                        $ramLabel = ($application->ram_mb_limit ?? -1) > 0 ? ((int)$application->ram_mb_limit) . ' MB' : 'Auto';
                        $storageLabel = ($application->storage_mb_limit ?? -1) > 0 ? ((int)$application->storage_mb_limit) . ' MB' : 'Auto';
                        $bandwidthLabel = ($application->bandwidth_mbps_limit ?? -1) > 0 ? ((int)$application->bandwidth_mbps_limit) . ' Mbps' : 'Auto';
                        $pidsLabel = ($application->pids_limit ?? -1) > 0 ? (string)((int)$application->pids_limit) : 'Auto';
                    ?>

                    <div class="flex flex-col gap-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <span class="text-tertiary">CPU</span>
                            <span class="text-secondary"><?= e($cpuLabel) ?></span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-tertiary">RAM</span>
                            <span class="text-secondary"><?= e($ramLabel) ?></span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-tertiary">Bandwidth</span>
                            <span class="text-secondary"><?= e($bandwidthLabel) ?></span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-tertiary">Storage</span>
                            <span class="text-secondary"><?= e($storageLabel) ?></span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-tertiary">PIDs</span>
                            <span class="text-secondary"><?= e($pidsLabel) ?></span>
                        </div>
                    </div>

                    <p class="text-xs text-tertiary mt-3">"Auto" means this app is not assigned a fixed slice at the environment level.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Current</h3>
                </div>
                <div class="card-body">
                    <div id="current-empty">
                        <p class="text-secondary text-sm">Select a container</p>
                    </div>
                    <div id="current" class="hidden">
                        <div class="flex flex-col gap-4">
                            <div>
                                <div class="flex items-center justify-between gap-4 text-sm">
                                    <span class="text-tertiary">CPU</span>
                                    <span id="current-cpu" class="text-primary">-</span>
                                </div>
                                <div class="progress" aria-hidden="true"><div id="current-cpu-bar" class="progress-bar"></div></div>
                            </div>
                            <div>
                                <div class="flex items-center justify-between gap-4 text-sm">
                                    <span class="text-tertiary">RAM</span>
                                    <span id="current-ram" class="text-primary">-</span>
                                </div>
                                <div class="progress" aria-hidden="true"><div id="current-ram-bar" class="progress-bar"></div></div>
                            </div>
                            <div class="flex items-center justify-between gap-4 text-sm">
                                <span class="text-tertiary">Network</span>
                                <span id="current-net" class="text-primary">-</span>
                            </div>
                        </div>
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

.status-dot.live { background: var(--accent-green); animation: pulse 2s infinite; }
.status-dot.connecting { background: var(--accent-yellow); animation: pulse 1s infinite; }
.status-dot.disconnected { background: var(--accent-gray); }

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.dropdown-items { max-height: 240px; overflow-y: auto; }
.dropdown-empty { padding: var(--space-4); text-align: center; color: var(--text-tertiary); font-size: var(--text-sm); }

.progress {
    width: 100%;
    height: 8px;
    background: var(--bg-tertiary);
    border: 1px solid var(--border-primary);
    border-radius: 999px;
    overflow: hidden;
    margin-top: var(--space-2);
}

.progress-bar {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, var(--accent-blue), var(--accent-green));
    transition: width 150ms linear;
}

.progress-bar.warn { background: linear-gradient(90deg, var(--accent-yellow), var(--accent-red)); }

.usage-chart {
    width: 100%;
    display: block;
    background: var(--bg-primary);
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-lg);
}
</style>

<script>
(function() {
    'use strict';

    const config = {
        logsWebsocketUrl: <?= json_encode($logsWebsocketUrl ?? null) ?>,
        sessionId: <?= json_encode($sessionId ?? '') ?>,
        applicationUuid: '<?= $application->uuid ?>'
    };

    const state = {
        ws: null,
        wsConnected: false,
        isConnecting: false,
        intentionalClose: false,
        wsReconnectTimeout: null,
        keepAliveTimer: null,
        keepAliveIntervalMs: 25000,

        containers: [],
        selectedContainer: null,

        timeframeSec: 60,

        series: {
            cpu: [], // {t, v}
            ram: [], // {t, usedBytes, limitBytes}
            net: [], // {t, rxBps, txBps}
        }
    };

    const el = {};

    function init() {
        cache();
        bind();

        if (!config.logsWebsocketUrl) {
            showStatus('disconnected', 'WebSocket not configured');
            return;
        }

        connectWebSocket();
    }

    function cache() {
        el.statusDot = document.getElementById('status-dot');
        el.statusText = document.getElementById('status-text');

        el.reconnectBtn = document.getElementById('reconnect-btn');

        el.containerSelectBtn = document.getElementById('container-select-btn');
        el.selectedContainerName = document.getElementById('selected-container-name');
        el.containerDropdownMenu = document.getElementById('container-dropdown-menu');
        el.containerSearch = document.getElementById('container-search');
        el.containerList = document.getElementById('container-list');

        el.rangeValue = document.getElementById('range-value');
        el.rangeDropdownMenu = document.getElementById('range-dropdown-menu');

        el.currentEmpty = document.getElementById('current-empty');
        el.current = document.getElementById('current');
        el.currentCpu = document.getElementById('current-cpu');
        el.currentCpuBar = document.getElementById('current-cpu-bar');
        el.currentRam = document.getElementById('current-ram');
        el.currentRamBar = document.getElementById('current-ram-bar');
        el.currentNet = document.getElementById('current-net');

        el.cpuLive = document.getElementById('cpu-live');
        el.ramLive = document.getElementById('ram-live');
        el.netLive = document.getElementById('net-live');

        el.cpuCanvas = document.getElementById('cpu-chart');
        el.ramCanvas = document.getElementById('ram-chart');
        el.netCanvas = document.getElementById('net-chart');
    }

    function bind() {
        el.reconnectBtn.addEventListener('click', () => connectWebSocket());

        el.containerDropdownMenu.addEventListener('dropdown:open', () => {
            setTimeout(() => el.containerSearch.focus(), 0);
        });

        el.containerSearch.addEventListener('input', (e) => {
            renderContainerList(filterContainers(e.target.value));
        });

        el.rangeDropdownMenu.querySelectorAll('.dropdown-item').forEach((item) => {
            item.addEventListener('click', () => {
                const seconds = parseInt(item.dataset.seconds, 10);
                if (!Number.isFinite(seconds) || seconds <= 0) return;
                state.timeframeSec = seconds;
                el.rangeValue.textContent = item.textContent;
                trimSeries();
                redrawAll();
            });
        });

        window.addEventListener('beforeunload', destroy);
    }

    function showStatus(type, text) {
        el.statusDot.className = 'status-dot ' + type;
        el.statusText.textContent = text;
    }

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
                    tail: 0
                }));
            };

            ws.onmessage = (event) => {
                if (ws !== state.ws) return;
                try {
                    handleWsMessage(JSON.parse(event.data));
                } catch (e) {
                    console.error('[Usage] Failed to parse WS message:', e);
                }
            };

            ws.onclose = (event) => {
                if (ws !== state.ws) return;
                state.wsConnected = false;
                state.isConnecting = false;
                showStatus('disconnected', 'Disconnected');
                stopKeepAlive();

                if (state.intentionalClose) {
                    state.intentionalClose = false;
                    return;
                }
                if (event.code !== 1000) {
                    state.wsReconnectTimeout = setTimeout(connectWebSocket, 3000);
                }
            };

            ws.onerror = () => {
                if (ws !== state.ws) return;
                state.isConnecting = false;
                showStatus('disconnected', 'WebSocket error');
            };
        } catch (e) {
            state.isConnecting = false;
            showStatus('disconnected', 'Failed to connect');
        }
    }

    function handleWsMessage(message) {
        switch (message.type) {
            case 'auth:success':
                state.wsConnected = true;
                state.isConnecting = false;
                showStatus('live', 'Live (WebSocket)');
                startKeepAlive();
                subscribeStats();
                break;
            case 'auth:failed':
                state.wsConnected = false;
                state.isConnecting = false;
                showStatus('disconnected', 'Auth failed');
                break;
            case 'containers':
                handleContainers(message);
                break;
            case 'stats':
                handleStats(message);
                break;
            case 'pong':
                break;
        }
    }

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

    function subscribeStats() {
        if (!state.ws || state.ws.readyState !== WebSocket.OPEN) return;
        if (!state.wsConnected) return;
        if (!state.selectedContainer) return;
        try {
            state.ws.send(JSON.stringify({ type: 'stats:subscribe', container_id: state.selectedContainer, timestamp: Date.now() }));
        } catch (e) {}
    }

    function filterContainers(term) {
        if (!term) return state.containers;
        const lower = term.toLowerCase();
        return state.containers.filter((c) => (c.name || '').toLowerCase().includes(lower));
    }

    function renderContainerList(containers) {
        if (!containers.length) {
            el.containerList.innerHTML = '<div class="dropdown-empty">No containers found</div>';
            return;
        }
        el.containerList.innerHTML = containers.map((c) => `
            <button type="button" class="dropdown-item ${c.id === state.selectedContainer ? 'active' : ''}" data-id="${escapeHtml(c.id)}">
                <span class="truncate">${escapeHtml(c.name)}</span>
                <span class="badge badge-sm ${c.status === 'running' ? 'badge-success' : 'badge-neutral'}">${escapeHtml(c.status)}</span>
            </button>
        `).join('');

        el.containerList.querySelectorAll('.dropdown-item').forEach((item) => {
            item.addEventListener('click', () => selectContainer(item.dataset.id));
        });
    }

    function updateSelectedContainerName() {
        const c = state.containers.find((x) => x.id === state.selectedContainer);
        el.selectedContainerName.textContent = c ? c.name : 'Select container...';
    }

    function selectContainer(id) {
        state.selectedContainer = id;
        updateSelectedContainerName();
        showCurrent(true);
        // reset series so charts feel responsive to selection
        state.series.cpu = [];
        state.series.ram = [];
        state.series.net = [];
        redrawAll();
        subscribeStats();
        renderContainerList(filterContainers(el.containerSearch.value || ''));
    }

    function handleContainers(payload) {
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
        state.containers = incoming
            .map((c) => ({
                id: String(c.id || c.container_id || ''),
                name: prettifyName(c.name || c.container || c.id || ''),
                status: String(c.status || 'unknown')
            }))
            .filter((c) => c.id && c.name);

        if (!state.selectedContainer) {
            state.selectedContainer = state.containers[0]?.id || null;
        } else if (!state.containers.find((c) => c.id === state.selectedContainer)) {
            state.selectedContainer = state.containers[0]?.id || null;
        }

        updateSelectedContainerName();
        renderContainerList(filterContainers(el.containerSearch.value || ''));
        showCurrent(!!state.selectedContainer);
        subscribeStats();
    }

    function handleStats(payload) {
        const cid = String(payload.container_id || payload.containerId || '');
        if (!cid || cid !== state.selectedContainer) return;

        if (!payload.ok) {
            el.cpuLive.textContent = 'Unavailable';
            el.ramLive.textContent = 'Unavailable';
            el.netLive.textContent = payload.error ? String(payload.error) : 'Unavailable';
            el.currentCpu.textContent = 'Unavailable';
            el.currentRam.textContent = 'Unavailable';
            el.currentNet.textContent = payload.error ? String(payload.error) : 'Unavailable';
            setProgress(el.currentCpuBar, 0);
            setProgress(el.currentRamBar, 0);
            return;
        }

        const ts = Number(payload.timestamp) || Date.now();

        // CPU
        state.series.cpu.push({ t: ts, v: Number(payload.cpu_percent) || 0 });

        // RAM
        const used = payload.mem_usage_bytes;
        const lim = payload.mem_limit_bytes;
        state.series.ram.push({ t: ts, usedBytes: Number(used), limitBytes: Number(lim) });

        // Network
        const rx = payload.net_rx_bps;
        const tx = payload.net_tx_bps;
        state.series.net.push({ t: ts, rxBps: Number(rx), txBps: Number(tx) });

        trimSeries();

        // Current panel
        const cpuUsageCores = payload.cpu_usage_cores;
        const cpuLimitCores = payload.cpu_limit_cores;
        if (Number.isFinite(cpuUsageCores) && Number.isFinite(cpuLimitCores) && cpuLimitCores > 0) {
            el.currentCpu.textContent = `${cpuUsageCores.toFixed(2)} / ${cpuLimitCores.toFixed(2)} cores`;
            setProgress(el.currentCpuBar, cpuUsageCores / cpuLimitCores);
            el.cpuLive.textContent = `${(Number(payload.cpu_percent) || 0).toFixed(1)}%`;
        } else {
            el.currentCpu.textContent = `${(Number(payload.cpu_percent) || 0).toFixed(1)}%`;
            setProgress(el.currentCpuBar, (Number(payload.cpu_percent) || 0) / 100);
            el.cpuLive.textContent = el.currentCpu.textContent;
        }

        if (Number.isFinite(used) && Number.isFinite(lim) && lim > 0) {
            el.currentRam.textContent = `${formatBytes(used)} / ${formatBytes(lim)}`;
            setProgress(el.currentRamBar, used / lim);
            el.ramLive.textContent = `${Math.min(100, (used / lim) * 100).toFixed(1)}%`;
        } else if (Number.isFinite(used)) {
            el.currentRam.textContent = formatBytes(used);
            setProgress(el.currentRamBar, 0);
            el.ramLive.textContent = el.currentRam.textContent;
        } else {
            el.currentRam.textContent = '-';
            setProgress(el.currentRamBar, 0);
            el.ramLive.textContent = '-';
        }

        el.currentNet.textContent = `RX ${formatBps(rx)} • TX ${formatBps(tx)}`;
        el.netLive.textContent = el.currentNet.textContent;

        redrawAll();
    }

    function showCurrent(show) {
        if (show) {
            el.currentEmpty.classList.add('hidden');
            el.current.classList.remove('hidden');
        } else {
            el.currentEmpty.classList.remove('hidden');
            el.current.classList.add('hidden');
        }
    }

    function trimSeries() {
        const cutoff = Date.now() - (state.timeframeSec * 1000);
        state.series.cpu = state.series.cpu.filter((p) => p.t >= cutoff);
        state.series.ram = state.series.ram.filter((p) => p.t >= cutoff);
        state.series.net = state.series.net.filter((p) => p.t >= cutoff);
    }

    function redrawAll() {
        drawLineChart(el.cpuCanvas, state.series.cpu.map((p) => ({ t: p.t, v: p.v })), { minY: 0, maxY: 100, lines: [{ key: 'v', color: getCssVar('--accent-blue') || '#3b82f6' }] });

        // RAM chart uses usedBytes as the plotted value (MiB).
        const ramPoints = state.series.ram.map((p) => ({ t: p.t, v: Number.isFinite(p.usedBytes) ? p.usedBytes : 0 }));
        drawLineChart(el.ramCanvas, ramPoints, { lines: [{ key: 'v', color: getCssVar('--accent-green') || '#22c55e' }] });

        // Net chart: two lines (rx, tx)
        const netPoints = state.series.net.map((p) => ({ t: p.t, rx: Number.isFinite(p.rxBps) ? p.rxBps : 0, tx: Number.isFinite(p.txBps) ? p.txBps : 0 }));
        drawLineChart(el.netCanvas, netPoints, { lines: [
            { key: 'rx', color: getCssVar('--accent-blue') || '#3b82f6' },
            { key: 'tx', color: getCssVar('--accent-yellow') || '#f59e0b' },
        ]});
    }

    function drawLineChart(canvas, points, opts) {
        const ctx = canvas.getContext('2d');
        const dpr = window.devicePixelRatio || 1;
        const rect = canvas.getBoundingClientRect();

        const w = Math.max(200, Math.floor(rect.width));
        const h = Math.max(120, Math.floor(rect.height));
        canvas.width = Math.floor(w * dpr);
        canvas.height = Math.floor(h * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

        ctx.clearRect(0, 0, w, h);

        const bg = getCssVar('--bg-primary') || '#0b0f14';
        const grid = getCssVar('--border-primary') || '#1f2937';
        const text = getCssVar('--text-tertiary') || '#94a3b8';

        // Background
        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, w, h);

        // No data
        if (!points || points.length < 2) {
            ctx.fillStyle = text;
            ctx.font = '12px system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial';
            ctx.fillText('Waiting for data…', 12, 20);
            return;
        }

        const pad = 10;
        const x0 = pad;
        const y0 = pad;
        const x1 = w - pad;
        const y1 = h - pad;

        // X range: last timeframe
        const tMin = Date.now() - (state.timeframeSec * 1000);
        const tMax = Date.now();

        // Y range (auto)
        const lines = (opts && Array.isArray(opts.lines) && opts.lines.length)
            ? opts.lines
            : [{ key: 'v', color: getCssVar('--accent-blue') || '#3b82f6' }];

        let minY = (opts && Number.isFinite(opts.minY)) ? opts.minY : Infinity;
        let maxY = (opts && Number.isFinite(opts.maxY)) ? opts.maxY : -Infinity;

        if (!Number.isFinite(minY) || !Number.isFinite(maxY)) {
            for (const p of points) {
                for (const ln of lines) {
                    const v = Number(p[ln.key]);
                    if (!Number.isFinite(v)) continue;
                    minY = Math.min(minY, v);
                    maxY = Math.max(maxY, v);
                }
            }
            if (!Number.isFinite(minY) || !Number.isFinite(maxY)) {
                minY = 0;
                maxY = 1;
            }
        }

        if (minY === maxY) {
            maxY = minY + 1;
        }

        // Add headroom
        const span = maxY - minY;
        minY = minY - span * 0.05;
        maxY = maxY + span * 0.05;

        const toX = (t) => {
            const tt = Math.min(Math.max(t, tMin), tMax);
            const r = (tt - tMin) / Math.max(1, (tMax - tMin));
            return x0 + r * (x1 - x0);
        };
        const toY = (v) => {
            const r = (v - minY) / (maxY - minY);
            return y1 - r * (y1 - y0);
        };

        // Grid
        ctx.strokeStyle = grid;
        ctx.globalAlpha = 0.6;
        ctx.lineWidth = 1;
        for (let i = 0; i <= 4; i++) {
            const yy = y0 + (i / 4) * (y1 - y0);
            ctx.beginPath();
            ctx.moveTo(x0, yy);
            ctx.lineTo(x1, yy);
            ctx.stroke();
        }
        ctx.globalAlpha = 1;

        // Lines
        for (const ln of lines) {
            ctx.strokeStyle = ln.color;
            ctx.lineWidth = 2;
            ctx.beginPath();
            let started = false;
            for (const p of points) {
                if (!p || !Number.isFinite(p.t)) continue;
                const v = Number(p[ln.key]);
                if (!Number.isFinite(v)) continue;
                const x = toX(p.t);
                const y = toY(v);
                if (!started) {
                    ctx.moveTo(x, y);
                    started = true;
                } else {
                    ctx.lineTo(x, y);
                }
            }
            ctx.stroke();
        }
    }

    function setProgress(elBar, ratio) {
        const r = Number(ratio);
        const clamped = Number.isFinite(r) ? Math.min(Math.max(r, 0), 1) : 0;
        elBar.style.width = (clamped * 100).toFixed(1) + '%';
        elBar.classList.toggle('warn', clamped >= 0.85);
    }

    function formatBytes(bytes) {
        const n = Number(bytes);
        if (!Number.isFinite(n) || n < 0) return '-';
        const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        let v = n;
        let i = 0;
        while (v >= 1024 && i < units.length - 1) {
            v /= 1024;
            i++;
        }
        const dp = v >= 100 ? 0 : v >= 10 ? 1 : 2;
        return v.toFixed(dp) + ' ' + units[i];
    }

    function formatBps(bps) {
        const n = Number(bps);
        if (!Number.isFinite(n) || n < 0) return '-';
        return formatBytes(n) + '/s';
    }

    function getCssVar(name) {
        try {
            return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        } catch {
            return '';
        }
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = String(str || '');
        return div.innerHTML;
    }

    function destroy() {
        stopKeepAlive();
        try {
            if (state.ws && state.ws.readyState === WebSocket.OPEN) {
                state.ws.send(JSON.stringify({ type: 'stats:unsubscribe', timestamp: Date.now() }));
            }
        } catch (e) {}
        try { if (state.ws) state.ws.close(1000, 'Page unload'); } catch (e) {}
        if (state.wsReconnectTimeout) clearTimeout(state.wsReconnectTimeout);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
