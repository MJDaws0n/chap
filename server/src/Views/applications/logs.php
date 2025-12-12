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

// Build container array - handle both object and array formats
$containersArray = [];
foreach ($containers as $c) {
    if (is_object($c)) {
        $containersArray[] = [
            'id' => $c->container_id ?? $c->id ?? '',
            'name' => $c->name ?? 'unknown',
            'status' => $c->status ?? 'unknown',
        ];
    } else {
        $containersArray[] = [
            'id' => $c['container_id'] ?? $c['id'] ?? '',
            'name' => $c['name'] ?? 'unknown',
            'status' => $c['status'] ?? 'unknown',
        ];
    }
}
$containersJson = json_encode($containersArray);
$firstContainerId = !empty($containersArray) ? json_encode($containersArray[0]['id']) : 'null';
?>
<div class="space-y-6" x-data="liveLogs()">
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
            <a href="/applications/<?= $application->uuid ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
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
                            <button @click="dropdownOpen = !dropdownOpen" type="button" class="bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white text-left flex justify-between items-center min-w-[200px] focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                <span x-text="selectedContainerName()"></span>
                                <svg class="w-4 h-4 ml-2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="dropdownOpen" @click.away="dropdownOpen = false" x-cloak class="absolute z-10 mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg shadow-lg max-h-60 overflow-auto">
                                <div class="p-2">
                                    <input type="text" x-model="containerSearch" placeholder="Search containers..." class="w-full bg-gray-700 border border-gray-600 rounded px-2 py-1 text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-sm" autocomplete="off">
                                </div>
                                <template x-for="container in filteredContainers()" :key="container.id">
                                    <div @click="selectContainer(container)" class="px-4 py-2 cursor-pointer hover:bg-blue-600/20 text-white" :class="{'bg-blue-700/30': container.id === selectedContainer}">
                                        <div class="flex items-center justify-between">
                                            <span x-text="container.name" class="truncate"></span>
                                            <span class="text-xs px-2 py-0.5 rounded-full" :class="container.status === 'running' ? 'bg-green-600/30 text-green-400' : 'bg-gray-600/30 text-gray-400'" x-text="container.status"></span>
                                        </div>
                                    </div>
                                </template>
                                <div x-show="filteredContainers().length === 0" class="px-4 py-2 text-gray-400">No containers found</div>
                            </div>
                        </div>

                        <!-- Live indicator -->
                        <template x-if="isLive">
                            <div class="flex items-center space-x-2 text-sm text-gray-400">
                                <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                                <span>Live</span>
                            </div>
                        </template>

                        <!-- Pause/Resume -->
                        <button @click="togglePause()" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-2 rounded-lg text-sm">
                            <span x-show="!paused">Pause</span>
                            <span x-show="paused">Resume</span>
                        </button>

                        <!-- Refresh -->
                        <button @click="fetchLogs()" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-2 rounded-lg text-sm">
                            Refresh
                        </button>
                    </div>
                </div>
                
                <div id="logs-container" class="bg-gray-900 rounded-lg p-4 h-[600px] overflow-y-auto font-mono text-sm" x-ref="logsContainer">
                    <template x-if="logs.length === 0 && !loading">
                        <p class="text-gray-500">No logs available. Select a container to view logs.</p>
                    </template>
                    <template x-if="loading">
                        <p class="text-gray-500">Loading logs...</p>
                    </template>
                    <template x-for="(log, index) in logs" :key="index">
                        <div class="flex items-start space-x-2 py-1" :class="logClass(log)">
                            <span class="text-gray-500 flex-shrink-0" x-text="log.timestamp"></span>
                            <span class="whitespace-pre-wrap" x-text="log.message"></span>
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
                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                            Redeploy
                        </button>
                    </form>
                    <?php if ($application->status === 'running'): ?>
                        <form method="POST" action="/applications/<?= $application->uuid ?>/restart">
                            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                            <button type="submit" class="w-full bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-lg">
                                Restart
                            </button>
                        </form>
                        <form method="POST" action="/applications/<?= $application->uuid ?>/stop">
                            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                            <button type="submit" class="w-full bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
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
                            <button type="button" @click="openTail = !openTail" class="bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white w-full text-left flex justify-between items-center">
                                <span x-text="tailSize + ' lines'"></span>
                                <svg class="w-4 h-4 ml-2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="openTail" @click.away="openTail = false" x-cloak class="absolute z-10 mt-1 w-full bg-gray-800 border border-gray-700 rounded-lg shadow-lg">
                                <div @click="tailSize='100'; openTail=false; fetchLogs()" class="px-4 py-2 cursor-pointer hover:bg-blue-600/20 text-white">100 lines</div>
                                <div @click="tailSize='500'; openTail=false; fetchLogs()" class="px-4 py-2 cursor-pointer hover:bg-blue-600/20 text-white">500 lines</div>
                                <div @click="tailSize='1000'; openTail=false; fetchLogs()" class="px-4 py-2 cursor-pointer hover:bg-blue-600/20 text-white">1000 lines</div>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center space-x-2">
                        <input type="checkbox" x-model="autoScroll" id="autoScroll" class="w-4 h-4 bg-gray-700 border-gray-600 rounded focus:ring-blue-500">
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
                                <span class="text-white truncate max-w-[150px]" x-text="selectedContainerInfo()?.name"></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-gray-400">Status</span>
                                <span class="px-2 py-1 text-xs rounded-full" :class="selectedContainerInfo()?.status === 'running' ? 'bg-green-600' : 'bg-gray-600'" x-text="selectedContainerInfo()?.status"></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-gray-400">ID</span>
                                <code class="text-xs bg-gray-700 px-2 py-1 rounded" x-text="(selectedContainerInfo()?.id || '').substring(0, 12)"></code>
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
        containers: <?= $containersJson ?>,
        selectedContainer: <?= $firstContainerId ?>,
        containerSearch: '',
        dropdownOpen: false,
        logs: [],
        loading: false,
        paused: false,
        isLive: true,
        tailSize: '100',
        autoScroll: true,
        pollInterval: null,
        applicationUuid: '<?= $application->uuid ?>',

        init() {
            // Fetch fresh container list on load
            this.fetchContainersAndLogs();
            this.startPolling();
        },
        
        async fetchContainersAndLogs() {
            // Used on initial load to fetch containers and recent logs
            if (this.fetchInProgress) return;
            this.fetchInProgress = true;
            this.loading = true;
            try {
                const response = await fetch(`/applications/${this.applicationUuid}/logs?container_id=${this.selectedContainer || ''}&tail=${this.tailSize}`, {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await response.json();

                // Update containers list if we got fresh data (preserve selection)
                if (data.containers && data.containers.length > 0) {
                    const prevSelected = this.selectedContainer;
                    this.containers = data.containers;
                    if (prevSelected) {
                        // make sure previously selected container still exists
                        const found = this.containers.find(c => (c.id || c.container_id) === prevSelected);
                        if (!found) this.selectedContainer = this.containers[0].id || this.containers[0].container_id;
                    } else if (this.containers.length > 0) {
                        this.selectedContainer = this.containers[0].id || this.containers[0].container_id;
                    }
                }

                // For initial load, seed logs and seen keys
                if (data.logs && data.logs.length > 0) {
                    this.logs = data.logs;
                    this.seenLogKeys = new Set(data.logs.map(l => (l.timestamp || '') + '|' + (l.message || '')));
                    this.scrollToBottom();
                }
            } catch (error) {
                console.error('Failed to fetch:', error);
            } finally {
                this.loading = false;
                this.fetchInProgress = false;
            }
        },

        filteredContainers() {
            if (!this.containerSearch) return this.containers;
            return this.containers.filter(c => 
                c.name.toLowerCase().includes(this.containerSearch.toLowerCase())
            );
        },

        selectedContainerName() {
            const container = this.containers.find(c => (c.id || c.container_id) === this.selectedContainer);
            return container ? container.name : 'Select container...';
        },

        selectedContainerInfo() {
            return this.containers.find(c => (c.id || c.container_id) === this.selectedContainer);
        },

        selectContainer(container) {
            this.selectedContainer = container.id || container.container_id;
            this.dropdownOpen = false;
            this.logs = [];
            this.fetchLogs();
        },

        async fetchLogs() {
            if (!this.selectedContainer) return;
            if (this.fetchInProgress) return; // avoid overlapping requests
            this.fetchInProgress = true;
            try {
                const response = await fetch(`/applications/${this.applicationUuid}/logs?container_id=${this.selectedContainer}&tail=${this.tailSize}`, {
                    headers: { 'Accept': 'application/json' }
                });
                const data = await response.json();

                // Update containers list quietly if returned (preserve selection)
                if (data.containers && data.containers.length > 0) {
                    const prevSelected = this.selectedContainer;
                    this.containers = data.containers;
                    if (prevSelected) {
                        const found = this.containers.find(c => (c.id || c.container_id) === prevSelected);
                        if (!found) this.selectedContainer = this.containers[0].id || this.containers[0].container_id;
                    }
                }

                // Append only new logs (dedupe)
                if (!this.seenLogKeys) this.seenLogKeys = new Set();
                const incoming = Array.isArray(data.logs) ? data.logs : [];
                const newLogs = [];
                for (const l of incoming) {
                    const key = (l.timestamp || '') + '|' + (l.message || '');
                    if (!this.seenLogKeys.has(key)) {
                        this.seenLogKeys.add(key);
                        newLogs.push(l);
                    }
                }

                if (newLogs.length > 0) {
                    // Append new logs
                    this.logs = this.logs.concat(newLogs);
                    // Trim to tail size to avoid unbounded growth
                    const max = parseInt(this.tailSize, 10) || 100;
                    if (this.logs.length > (max * 3)) {
                        // keep a bit more than tail to avoid chopping important context
                        this.logs = this.logs.slice(-max * 3);
                    }
                    if (this.autoScroll) this.scrollToBottom();
                }
            } catch (error) {
                console.error('Failed to fetch logs:', error);
            } finally {
                this.fetchInProgress = false;
            }
        },
        
        scrollToBottom() {
            if (this.autoScroll) {
                this.$nextTick(() => {
                    const container = this.$refs.logsContainer;
                    if (container) {
                        container.scrollTop = container.scrollHeight;
                    }
                });
            }
        },

        startPolling() {
            this.pollInterval = setInterval(() => {
                if (!this.paused && this.selectedContainer) {
                    this.fetchLogs();
                }
            }, 2000);
        },

        togglePause() {
            this.paused = !this.paused;
            this.isLive = !this.paused;
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
        }
    };
}
</script>
