<?php
/**
 * Application Live Logs View
 */
$statusColors = [
    'running' => 'bg-green-600',
    'stopped' => 'bg-gray-600',
    'building' => 'bg-yellow-600',
    'deploying' => 'bg-blue-600',
    'failed' => 'bg-red-600',
];
$statusColor = $statusColors[$application->status] ?? 'bg-gray-600';

// Containers are provided by the node logs WebSocket (WS/WSS)
$containersJson = '[]';
$firstContainerId = 'null';
?>
<div class="stack" x-data="liveLogs()">
    <!-- Breadcrumb & Header -->
    <div class="flex items-center justify-between">
        <div>
            <div class="flex items-center space-x-2 text-sm text-gray-400 mb-2">
                <a href="/projects" class="hover:text-white">Projects</a>
                <span>/</span>
                <a href="/projects/<?= $project->uuid ?>" class="hover:text-white"><?= e($project->name) ?></a>
                <span>/</span>
                <a href="/environments/<?= $environment->uuid ?>"
                    class="hover:text-white"><?= e($environment->name) ?></a>
                <span>/</span>
                <a href="/applications/<?= $application->uuid ?>"
                    class="hover:text-white"><?= e($application->name) ?></a>
                <span>/</span>
                <span>Live Logs</span>
            </div>
            <div class="flex items-center space-x-3">
                <h1 class="text-2xl font-bold">Live Logs</h1>
                <span class="px-2 py-1 text-xs rounded-full <?= $statusColor ?>">
                    <?= ucfirst($application->status) ?>
                </span>
            </div>
            <p class="text-gray-400 mt-1"><?= e($application->name) ?></p>
        </div>
        <div class="flex space-x-3">
            <a href="/applications/<?= $application->uuid ?>"
                class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                ← Back to Application
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <!-- Logs Panel -->
        <div class="lg:col-span-3">
            <div class="bg-gray-800 rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold">Container Logs</h2>
                    <div class="flex items-center space-x-4">
                        <!-- Container Dropdown -->
                        <div class="relative">
                            <button @click="dropdownOpen = !dropdownOpen" type="button"
                                class="bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white text-left flex justify-between items-center min-w-[200px] focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                <span x-text="selectedContainerName()"></span>
                                <svg class="w-4 h-4 ml-2 text-gray-400" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                            <div x-show="dropdownOpen" @click.away="dropdownOpen = false" x-cloak
                                class="absolute z-10 mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg shadow-lg max-h-60 overflow-auto">
                                <div class="p-2">
                                    <input type="text" x-model="containerSearch" placeholder="Search containers..."
                                        class="w-full bg-gray-700 border border-gray-600 rounded px-2 py-1 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm"
                                        autocomplete="off">
                                </div>
                                <template x-for="container in filteredContainers()" :key="container.id">
                                    <div @click="selectContainer(container)"
                                        class="px-4 py-2 cursor-pointer hover:bg-blue-600/20 text-white"
                                        :class="{'bg-blue-700/30': container.id === selectedContainer}">
                                        <div class="flex items-center justify-between">
                                            <span x-text="container.name" class="truncate"></span>
                                            <span class="text-xs px-2 py-0.5 rounded-full"
                                                :class="container.status === 'running' ? 'bg-green-600/30 text-green-400' : 'bg-gray-600/30 text-gray-400'"
                                                x-text="container.status"></span>
                                        </div>
                                    </div>
                                </template>
                                <div x-show="filteredContainers().length === 0" class="px-4 py-2 text-gray-400">No
                                    containers found</div>
                            </div>
                        </div>

                        <!-- Live indicator -->
                        <template x-if="isLive">
                            <div class="flex items-center space-x-2 text-sm text-gray-400">
                                <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                                <span x-text="wsConnected ? 'Live (WebSocket)' : 'Live'"></span>
                            </div>
                        </template>

                        <!-- Connection status -->
                        <template x-if="!isLive && !loading">
                            <div class="flex items-center space-x-2 text-sm text-gray-400">
                                <div class="w-2 h-2 bg-gray-500 rounded-full"></div>
                                <span>Disconnected</span>
                            </div>
                        </template>

                        <!-- Refresh -->
                        <button x-show="!!logsWebsocketUrl" @click="connectWebSocket()"
                            class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-2 rounded-lg text-sm">
                            <span>Reconnect</span>
                        </button>
                    </div>
                </div>

                <div id="logs-container" class="logs" x-ref="logsContainer">
                    <template x-if="logs.length === 0 && !loading">
                        <p class="text-gray-500">No logs available. Select a container to view logs.</p>
                    </template>
                    <template x-if="loading">
                        <p class="text-gray-500">Loading logs...</p>
                    </template>
                    <template x-for="(log, index) in logs" :key="index">
                        <div class="log-line" :class="logClass(log)">
                            <span class="log-line__ts" x-text="log.timestamp"></span>
                            <span class="log-line__msg" x-text="log.message"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Actions Card -->
            <div class="bg-gray-800 rounded-lg p-6">
                <h2 class="text-lg font-semibold mb-4">Actions</h2>
                <div class="space-y-3">
                    <form method="POST" action="/applications/<?= $application->uuid ?>/deploy">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                        <button type="submit"
                            class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                            Redeploy
                        </button>
                    </form>
                    <?php if ($application->status === 'running'): ?>
                        <form method="POST" action="/applications/<?= $application->uuid ?>/restart">
                            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                            <button type="submit"
                                class="w-full bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-lg">
                                Restart
                            </button>
                        </form>
                        <form method="POST" action="/applications/<?= $application->uuid ?>/stop">
                            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                            <button type="submit"
                                class="w-full bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                                Stop Application
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tail Size -->
            <div class="bg-gray-800 rounded-lg p-6">
                <h2 class="text-lg font-semibold mb-4">Settings</h2>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm text-gray-400 mb-2">Tail Lines</label>
                        <div x-data="{ openTail: false }" class="relative">
                            <input type="hidden" name="tailSize" x-model="tailSize">
                            <button type="button" @click="openTail = !openTail"
                                class="bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white w-full text-left flex justify-between items-center">
                                <span x-text="tailSize + ' lines'"></span>
                                <svg class="w-4 h-4 ml-2 text-gray-400" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                            <div x-show="openTail" @click.away="openTail = false" x-cloak
                                class="absolute z-10 mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg shadow-lg">
                                <div @click="tailSize='100'; openTail=false; rebuildVisibleLogs()"
                                    class="px-4 py-2 cursor-pointer hover:bg-blue-600/20 text-white">100 lines</div>
                                <div @click="tailSize='500'; openTail=false; rebuildVisibleLogs()"
                                    class="px-4 py-2 cursor-pointer hover:bg-blue-600/20 text-white">500 lines</div>
                                <div @click="tailSize='1000'; openTail=false; rebuildVisibleLogs()"
                                    class="px-4 py-2 cursor-pointer hover:bg-blue-600/20 text-white">1000 lines</div>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center space-x-2">
                        <input type="checkbox" x-model="autoScroll" id="autoScroll"
                            class="w-4 h-4 bg-gray-700 border-gray-600 rounded focus:ring-blue-500">
                        <label for="autoScroll" class="text-sm text-gray-300">Auto-scroll to bottom</label>
                    </div>
                </div>
            </div>

            <!-- Container Info -->
            <div class="bg-gray-800 rounded-lg p-6">
                <h2 class="text-lg font-semibold mb-4">Container Info</h2>
                <div class="space-y-3 text-sm">
                    <template x-if="selectedContainerInfo()">
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-gray-400">Name</span>
                                <span class="text-white truncate max-w-[150px]"
                                    x-text="selectedContainerInfo()?.name"></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-gray-400">Status</span>
                                <span class="px-2 py-1 text-xs rounded-full"
                                    :class="selectedContainerInfo()?.status === 'running' ? 'bg-green-600' : 'bg-gray-600'"
                                    x-text="selectedContainerInfo()?.status"></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-gray-400">ID</span>
                                <code class="text-xs bg-gray-700 px-2 py-1 rounded"
                                    x-text="(selectedContainerInfo()?.id || '').substring(0, 12)"></code>
                            </div>
                        </div>
                    </template>
                    <template x-if="!selectedContainerInfo()">
                        <p class="text-gray-500">Select a container</p>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function liveLogs() {
        return {
            containers: [],
            selectedContainer: null,
            containerSearch: '',
            dropdownOpen: false,
            logs: [],
            loading: false,
            paused: false,
            isLive: false,
            tailSize: '100',
            autoScroll: true,
            maxStoredLogsPerContainer: 5000,
            logsByContainer: {},
            seenKeysByContainer: {},
            rawMessages: [],
            maxRawMessages: 2000,
            applicationUuid: '<?= $application->uuid ?>',

            // WebSocket properties
            logsWebsocketUrl: <?= json_encode($logsWebsocketUrl ?? null) ?>,
            sessionId: <?= json_encode($sessionId ?? '') ?>,
            ws: null,
            wsConnected: false,
            wsReconnectTimeout: null,
            isConnecting: false,
            intentionalClose: false,
            keepAliveTimer: null,
            keepAliveIntervalMs: 25000,
            lastPongAt: null,

            init() {
                if (!this.logsWebsocketUrl) {
                    console.warn('[Logs] Live logs require WebSocket; no logsWebsocketUrl configured');
                    this.loading = false;
                    this.wsConnected = false;
                    this.isLive = false;
                    return;
                }

                this.connectWebSocket();
            },

            connectWebSocket() {
                if (!this.logsWebsocketUrl) return;
                // Cancel any scheduled reconnect attempts; this call is the new attempt.
                if (this.wsReconnectTimeout) {
                    clearTimeout(this.wsReconnectTimeout);
                    this.wsReconnectTimeout = null;
                }

                // Avoid overlapping connection attempts.
                if (this.isConnecting) return;

                // If a socket already exists, close it intentionally so onclose doesn't auto-reconnect.
                if (this.ws) {
                    this.intentionalClose = true;
                    try { this.ws.close(1000, 'Reconnect requested'); } catch (e) {}
                }

                this.stopKeepAlive();

                try {
                    this.isConnecting = true;
                    this.loading = true;
                    const ws = new WebSocket(this.logsWebsocketUrl);
                    this.ws = ws;

                    ws.onopen = () => {
                        if (ws !== this.ws) return;
                        this.intentionalClose = false;
                        ws.send(JSON.stringify({
                            type: 'auth',
                            session_id: this.sessionId,
                            application_uuid: this.applicationUuid,
                            // Ask the node to send some initial history too.
                            tail: Math.min(Math.max(parseInt(this.tailSize, 10) || 100, 0), 1000)
                        }));
                    };

                    ws.onmessage = (event) => {
                        if (ws !== this.ws) return;
                        try {
                            this.handleWsMessage(JSON.parse(event.data));
                        } catch (e) {
                            console.error('[Logs] Failed to parse WS message:', e);
                        }
                    };

                    ws.onclose = (event) => {
                        if (ws !== this.ws) return;
                        console.log('[Logs] WebSocket closed:', event.code, event.reason);
                        this.wsConnected = false;
                        this.isLive = false;
                        this.loading = false;
                        this.isConnecting = false;

                        this.stopKeepAlive();

                        // If we closed it intentionally (e.g. user clicked Reconnect), do not auto-reconnect.
                        if (this.intentionalClose) {
                            this.intentionalClose = false;
                            return;
                        }

                        if (event.code !== 1000) {
                            this.wsReconnectTimeout = setTimeout(() => {
                                this.connectWebSocket();
                            }, 3000);
                        }
                    };

                    ws.onerror = (error) => {
                        if (ws !== this.ws) return;
                        console.error('[Logs] WebSocket error:', error);
                        this.loading = false;
                        this.isConnecting = false;
                    };
                } catch (e) {
                    console.error('[Logs] Failed to create WebSocket:', e);
                    this.loading = false;
                    this.isConnecting = false;
                }
            },

            handleWsMessage(message) {
                this.storeRawMessage(message);

                switch (message.type) {
                    case 'auth:success':
                        this.wsConnected = true;
                        this.isLive = !this.paused;
                        this.loading = false;
                        this.isConnecting = false;
                        this.startKeepAlive();
                        break;

                    case 'auth:failed':
                        console.error('[Logs] Authentication failed:', message.error);
                        this.wsConnected = false;
                        this.isLive = false;
                        this.loading = false;
                        this.isConnecting = false;
                        break;

                    case 'containers':
                        this.handleContainersMessage(message);
                        break;

                    case 'log':
                        this.handleLogMessage(message);
                        break;

                    case 'pong':
                        this.lastPongAt = Date.now();
                        break;

                    case 'error':
                        console.error('[Logs] Server error:', message.error);
                        break;
                }
            },

            startKeepAlive() {
                this.stopKeepAlive();
                this.keepAliveTimer = setInterval(() => {
                    try {
                        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                            this.ws.send(JSON.stringify({ type: 'ping', timestamp: Date.now() }));
                        }
                    } catch (e) {
                        // Ignore keepalive send errors; onclose handler will reconnect.
                    }
                }, this.keepAliveIntervalMs);
            },

            stopKeepAlive() {
                if (this.keepAliveTimer) {
                    clearInterval(this.keepAliveTimer);
                    this.keepAliveTimer = null;
                }
            },

            storeRawMessage(message) {
                this.rawMessages.push(message);
                if (this.rawMessages.length > this.maxRawMessages) {
                    this.rawMessages = this.rawMessages.slice(-this.maxRawMessages);
                }
            },

            normalizeContainerId(value) {
                if (value === undefined || value === null) return null;
                return String(value);
            },

            handleContainersMessage(payload) {
                const incoming = Array.isArray(payload.containers) ? payload.containers : [];
                const normalized = incoming
                    .map(c => ({
                        id: this.normalizeContainerId(c.id || c.container_id),
                        name: String(c.name || c.container || c.id || ''),
                        status: String(c.status || 'running')
                    }))
                    .filter(c => c.id && c.name);

                const previous = this.selectedContainer;
                this.containers = normalized;

                if (!this.selectedContainer) {
                    this.selectedContainer = normalized[0]?.id || null;
                } else if (!normalized.find(c => c.id === this.selectedContainer)) {
                    this.selectedContainer = normalized[0]?.id || null;
                }

                if (previous !== this.selectedContainer) {
                    this.rebuildVisibleLogs();
                }
            },

            handleLogMessage(payload) {
                const stream = payload.stream || 'stdout';
                const content = payload.content ?? payload.data ?? '';
                if (!content) return;

                const containerId = this.normalizeContainerId(payload.container_id || payload.containerId) || '__system__';
                const containerName = payload.container || payload.container_name || payload.containerName || null;
                const timestampOverride = payload.timestamp || null;

                this.appendParsedLogLines(stream, content, containerId, containerName, timestampOverride);
            },

            appendParsedLogLines(stream, content, containerId, containerName = null, timestampOverride = null) {
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

                    if (!this.seenKeysByContainer[storeKey]) this.seenKeysByContainer[storeKey] = new Set();
                    const dedupeKey = `${timestamp}|${message}`;
                    if (this.seenKeysByContainer[storeKey].has(dedupeKey)) continue;
                    this.seenKeysByContainer[storeKey].add(dedupeKey);

                    if (!this.logsByContainer[storeKey]) this.logsByContainer[storeKey] = [];
                    const entry = {
                        timestamp,
                        message,
                        stream,
                        containerId: storeKey,
                        containerName: containerName ? String(containerName) : null
                    };

                    this.logsByContainer[storeKey].push(entry);
                    if (this.logsByContainer[storeKey].length > this.maxStoredLogsPerContainer) {
                        this.logsByContainer[storeKey] = this.logsByContainer[storeKey].slice(-this.maxStoredLogsPerContainer);
                    }

                    if (this.shouldIncludeLog(entry)) {
                        this.logs.push(entry);
                        const max = parseInt(this.tailSize, 10) || 100;
                        if (this.logs.length > max * 3) {
                            this.logs = this.logs.slice(-max * 3);
                        }
                        if (this.autoScroll && !this.paused) this.scrollToBottom();
                    }
                }
            },

            shouldIncludeLog(logEntry) {
                if (!this.selectedContainer) return logEntry.containerId === '__system__';
                return logEntry.containerId === this.selectedContainer;
            },

            rebuildVisibleLogs() {
                const key = this.selectedContainer || '__system__';
                this.logs = (this.logsByContainer[key] || []).slice();

                const max = parseInt(this.tailSize, 10) || 100;
                if (this.logs.length > max * 3) {
                    this.logs = this.logs.slice(-max * 3);
                }

                if (this.autoScroll && !this.paused) this.scrollToBottom();
            },

            filteredContainers() {
                if (!this.containerSearch) return this.containers;
                const term = this.containerSearch.toLowerCase();
                return this.containers.filter(c =>
                    (c.name || '').toLowerCase().includes(term)
                );
            },

            selectedContainerName() {
                const container = this.containers.find(c => c.id === this.selectedContainer);
                return container ? container.name : 'Select container...';
            },

            selectedContainerInfo() {
                return this.containers.find(c => c.id === this.selectedContainer) || null;
            },

            selectContainer(container) {
                if (!container) return;
                this.selectedContainer = this.normalizeContainerId(container.id || container.container_id);
                this.dropdownOpen = false;
                this.rebuildVisibleLogs();
            },

            scrollToBottom() {
                this.$nextTick(() => {
                    const container = this.$refs.logsContainer;
                    if (container) container.scrollTop = container.scrollHeight;
                });
            },

            togglePause() {
                this.paused = !this.paused;
                this.isLive = this.wsConnected && !this.paused;
            },

            logClass(log) {
                const message = (log.message || '').toLowerCase();
                if (message.includes('error') || message.includes('failed') || message.includes('exception')) {
                    return 'text-red-400';
                } else if (message.includes('warning') || message.includes('warn')) {
                    return 'text-yellow-400';
                } else if (message.includes('success') || message.includes('completed') || message.includes('✓') || message.includes('✅')) {
                    return 'text-green-400';
                }
                return 'text-gray-300';
            },

            destroy() {
                if (this.ws) this.ws.close(1000, 'Component destroyed');
                if (this.wsReconnectTimeout) clearTimeout(this.wsReconnectTimeout);
                this.stopKeepAlive();
            }
        };
    }
</script>
