<?php
/**
 * Application Show View
 */
$statusColors = [
    'running' => 'bg-green-600',
    'stopped' => 'bg-gray-600',
    'building' => 'bg-yellow-600',
    'deploying' => 'bg-blue-600',
    'failed' => 'bg-red-600',
];
$statusColor = $statusColors[$application->status] ?? 'bg-gray-600';
?>
<div class="space-y-6">
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
                <span><?= e($application->name) ?></span>
            </div>
            <div class="flex items-center space-x-3">
                <h1 class="text-2xl font-bold"><?= e($application->name) ?></h1>
                <span class="px-2 py-1 text-xs rounded-full <?= $statusColor ?>">
                    <?= ucfirst($application->status) ?>
                </span>
            </div>
            <?php if ($application->description): ?>
                <p class="text-gray-400 mt-1"><?= e($application->description) ?></p>
            <?php endif; ?>
        </div>
        <div class="flex space-x-3">
            <a href="/applications/<?= $application->uuid ?>/logs" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center space-x-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span>Live Logs</span>
            </a>
            <?php if ($application->status === 'running'): ?>
                <form method="POST" action="/applications/<?= $application->uuid ?>/restart" class="inline">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-lg">
                        Restart
                    </button>
                </form>
                <form method="POST" action="/applications/<?= $application->uuid ?>/stop" class="inline">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                        Stop
                    </button>
                </form>
            <?php endif; ?>
            <form method="POST" action="/applications/<?= $application->uuid ?>/deploy" class="inline">
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                    Deploy
                </button>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Deployments -->
            <div class="bg-gray-800 rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold">Recent Deployments</h2>
                </div>
                <?php if (empty($deployments)): ?>
                    <p class="text-gray-400 text-center py-8">No deployments yet. Click "Deploy" to start your first deployment.</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($deployments as $deployment): ?>
                            <?php
                            $depStatusColors = [
                                'queued' => 'bg-gray-600',
                                'building' => 'bg-yellow-600',
                                'deploying' => 'bg-blue-600',
                                'running' => 'bg-green-600',
                                'success' => 'bg-green-600',
                                'failed' => 'bg-red-600',
                                'cancelled' => 'bg-gray-600',
                            ];
                            $depColor = $depStatusColors[$deployment->status] ?? 'bg-gray-600';
                            ?>
                            <a href="/deployments/<?= $deployment->uuid ?>" 
                               class="block bg-gray-700 rounded-lg p-4 hover:bg-gray-650 transition">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-3">
                                        <span class="px-2 py-1 text-xs rounded-full <?= $depColor ?>">
                                            <?= ucfirst($deployment->status) ?>
                                        </span>
                                        <div>
                                            <p class="font-medium"><?= e($deployment->commit_message ?? 'Manual deployment') ?></p>
                                            <p class="text-sm text-gray-400">
                                                <?= $deployment->commit_sha ? substr($deployment->commit_sha, 0, 7) : 'N/A' ?>
                                                • <?= time_ago($deployment->created_at) ?>
                                            </p>
                                        </div>
                                    </div>
                                    <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Configuration -->
            <div class="bg-gray-800 rounded-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold">Configuration</h2>
                </div>

                <form method="POST" action="/applications/<?= $application->uuid ?>" id="config-form" class="space-y-6">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="_method" value="PUT">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Name</label>
                            <input type="text" name="name" value="<?= e($application->name) ?>"
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white">
                        </div>

                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Node</label>
                            <select name="node_uuid" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white">
                                <?php foreach ($nodes as $node): ?>
                                    <option value="<?= $node->uuid ?>" <?= $application->node_id === $node->id ? 'selected' : '' ?>>
                                        <?= e($node->name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Git Repository</label>
                            <input type="text" name="git_repository" value="<?= e($application->git_repository) ?>"
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white">
                        </div>

                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Branch</label>
                            <input type="text" name="git_branch" value="<?= e($application->git_branch) ?>"
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white">
                        </div>

                        <div>
                            <label class="block text-sm text-gray-400 mb-1">Build Pack</label>
                            <select name="build_pack" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white">
                                <option value="dockerfile" <?= $application->build_pack === 'dockerfile' ? 'selected' : '' ?>>Dockerfile</option>
                                <option value="nixpacks" <?= $application->build_pack === 'nixpacks' ? 'selected' : '' ?>>Nixpacks</option>
                                <option value="static" <?= $application->build_pack === 'static' ? 'selected' : '' ?>>Static Site</option>
                                <option value="docker-compose" <?= $application->build_pack === 'docker-compose' ? 'selected' : '' ?>>Docker Compose</option>
                            </select>
                        </div>
                    </div>

                    <div id="save-buttons" class="flex justify-end space-x-4 pt-4 border-t border-gray-700">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">Save Changes</button>
                    </div>
                </form>
            </div>

            <!-- Environment Variables -->
            <div class="bg-gray-800 rounded-lg p-6">
                <?php $envArr = $application->getEnvironmentVariables();
                      $envVarsRaw = '';
                      if (!empty($envArr)) {
                          foreach ($envArr as $k => $v) {
                              $envVarsRaw .= $k . '=' . $v . "\n";
                          }
                          $envVarsRaw = rtrim($envVarsRaw, "\n");
                      }
                ?>
                <div x-data="envEditor()" x-ref="envEditor" data-initial-b64="<?= base64_encode($envVarsRaw) ?>" x-init="if($el.dataset.initialB64){ try{ $data.parseEnvString(atob($el.dataset.initialB64)); }catch(e){console.warn(e);} }" class="bg-gray-800 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold">Environment Variables</h2>
                        <button type="button" @click="toggleManual();" x-text="manualMode ? 'Hide' : 'Show'" class="text-blue-400 hover:text-blue-300 text-sm"></button>
                    </div>

                <?php
                    $envArr = $application->getEnvironmentVariables();
                    $envVarsRaw = '';
                    if (!empty($envArr)) {
                        foreach ($envArr as $k => $v) {
                            $envVarsRaw .= $k . '=' . $v . "\n";
                        }
                        $envVarsRaw = rtrim($envVarsRaw, "\n");
                    }
                ?>
                <form method="POST" action="/applications/<?= $application->uuid ?>" id="env-form">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="_method" value="PUT">

                    <div x-data="envEditor()" x-ref="envEditor" data-initial-b64="<?= base64_encode($envVarsRaw) ?>" x-init="if($el.dataset.initialB64){ try{ parseEnvString(atob($el.dataset.initialB64)); }catch(e){console.warn(e);} }" class="space-y-2">
                                <div class="flex items-center space-x-2">
                                                <div class="text-xs text-gray-400">Environment Variables</div>
                                                <div class="ml-auto flex items-center space-x-2">
                                                    <button type="button" @click="openBulkEditor()" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded text-sm">Bulk Edit</button>
                                                    <button type="button" @click="addRow()" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded text-sm">New Variable</button>
                                                </div>
                                            </div>

                        <div class="space-y-2">
                            <template x-for="(row, idx) in rows" :key="idx">
                                    <div class="rounded-lg p-3">
                                        <div class="flex items-center space-x-3">
                                            <input x-model="row.key" placeholder="KEY" class="w-1/3 bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white text-sm" @input="updateSerialized()">
                                            <div class="flex-1 relative" @mouseenter="onEnter(idx)" @mouseleave="onLeave(idx)">
                                                <input :type="(row.manual || manualMode) ? 'text' : (row.revealed ? 'text' : 'password')" x-model="row.value" placeholder="value" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white text-sm" @input="updateSerialized()">
                                                <div class="absolute right-2 top-1/2 transform -translate-y-1/2">
                                                    <button type="button" @click="removeRow(idx)" class="text-red-400 px-2">Remove</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            <div x-show="rows.length === 0" class="text-gray-400 text-sm">No environment variables configured.</div>
                        </div>

                        <textarea name="environment_variables" x-model="serialized" class="hidden"></textarea>

                        <div class="flex justify-end space-x-4 mt-4">
                            <button type="button" onclick="document.getElementById('env-form').reset();" class="px-4 py-2 text-gray-400 hover:text-white">Cancel</button>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">Save Variables</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Status Card -->
            <div class="bg-gray-800 rounded-lg p-6">
                <h2 class="text-lg font-semibold mb-4">Status</h2>
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">Status</span>
                        <span class="px-2 py-1 text-xs rounded-full <?= $statusColor ?>">
                            <?= ucfirst($application->status) ?>
                        </span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">Node</span>
                        <span><?= $application->node() ? e($application->node()->name) : 'Not assigned' ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">Memory</span>
                        <span><?= e($application->memory_limit ?? '512m') ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">CPU</span>
                        <span><?= e($application->cpu_limit ?? '1') ?> CPU</span>
                    </div>
                </div>
            </div>

            <!-- URLs Card -->
            <div class="bg-gray-800 rounded-lg p-6">
                <h2 class="text-lg font-semibold mb-4">URLs</h2>
                <?php if (!empty($application->domains)): ?>
                    <div class="space-y-2">
                        <?php foreach (explode(',', $application->domains) as $domain): ?>
                            <a href="https://<?= e(trim($domain)) ?>" target="_blank" 
                               class="flex items-center justify-between bg-gray-700 px-3 py-2 rounded hover:bg-gray-600">
                                <span class="text-sm truncate"><?= e(trim($domain)) ?></span>
                                <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                </svg>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-gray-400 text-sm">No custom domains configured.</p>
                <?php endif; ?>
            </div>

            <!-- Danger Zone -->
            <div class="bg-gray-800 rounded-lg p-6 border border-red-600/30">
                <h2 class="text-lg font-semibold text-red-400 mb-4">Danger Zone</h2>
                <form method="POST" action="/applications/<?= $application->uuid ?>" 
                      onsubmit="event.preventDefault(); chapSwal({title: 'Are you sure?', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', cancelButtonText: 'Cancel'}).then((result) => { if(result.isConfirmed) this.submit(); }); return false;">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                        Delete Application
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function envEditor(opts={}){
    const initial = opts.initial || '';
    return {
        rows: [],
        serialized: '',
        manualMode: false,
        init() {
            this.parseEnvString(initial);
            this.updateSerialized();
            window.addEventListener('mouseout', (e) => {
                try {
                    if (!e.relatedTarget) {
                        for (let r of this.rows) r.revealed = false;
                    }
                } catch (err) {}
            });
            window.addEventListener('blur', () => { for (let r of this.rows) r.revealed = false; });
        },
        openBulkEditor() {
            const self = this;
            chapSwal({
                title: 'Bulk Edit Environment Variables',
                html: '<textarea id="swal-env" rows="12" class="w-full bg-gray-800 text-white p-2 rounded-lg" placeholder="KEY=VALUE\nANOTHER=VAL"></textarea>',
                showCancelButton: true,
                confirmButtonText: 'Apply',
                cancelButtonText: 'Cancel',
                didOpen: () => {
                    const ta = document.getElementById('swal-env');
                    if (ta) { ta.value = self.serialized || ''; ta.focus(); }
                },
                preConfirm: () => {
                    const ta = document.getElementById('swal-env');
                    return ta ? ta.value : '';
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const txt = result.value || '';
                    self.parseEnvString(txt);
                    self.updateSerialized();
                }
            });
        },
        addRow(key = '', value = '', manual = false) {
            this.rows.push({ key: key, value: value, revealed: false, manual: manual });
            this.updateSerialized();
        },
        toggleManual() {
            this.manualMode = !this.manualMode;
            for (let r of this.rows) r.manual = this.manualMode;
            this.updateSerialized();
        },
        onEnter(i) {
            if (!this.rows[i]) return;
            if (this.rows[i]._revealTimeout) { clearTimeout(this.rows[i]._revealTimeout); this.rows[i]._revealTimeout = null; }
            this.rows[i].revealed = true;
        },
        onLeave(i) {
            if (!this.rows[i]) return;
            this.rows[i]._revealTimeout = setTimeout(()=>{ if(this.rows[i]) this.rows[i].revealed = false; this.rows[i]._revealTimeout = null; }, 120);
        },
        removeRow(i) {
            this.rows.splice(i, 1);
            this.updateSerialized();
        },
        updateSerialized() {
            this.serialized = this.rows.map(r => (r.key ? r.key + '=' + r.value : '')).filter(Boolean).join('\n');
        },
        parseEnvString(str) {
            this.rows = [];
            if (!str) return;
            const lines = str.split(/\r?\n/);
            for (let line of lines) {
                line = line.trim();
                if (!line || line.startsWith('#')) continue;
                const idx = line.indexOf('=');
                if (idx === -1) continue;
                const key = line.substring(0, idx).trim();
                const value = line.substring(idx+1);
                this.addRow(key, value, false);
            }
        }
    }
}
</script>
