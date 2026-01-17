<?php
/**
 * Template Show View
 */

/** @var \Chap\Models\Template $template */

$projects = $projects ?? [];
$environments = $environments ?? [];
$nodes = $nodes ?? [];
$initialEnvB64 = $initialEnvB64 ?? base64_encode('');

$errors = $_SESSION['_errors'] ?? [];

$selectedProjectUuid = trim((string) old('project_uuid', (string) ($_GET['project_uuid'] ?? '')));
$selectedEnvironmentUuid = trim((string) old('environment_uuid', (string) ($_GET['environment_uuid'] ?? '')));
$selectedNodeUuid = trim((string) old('node_uuid', ''));

$envOptions = [];
foreach ($environments as $env) {
    $proj = $env->project();
    if (!$proj) continue;
    $envOptions[] = [
        'uuid' => (string) $env->uuid,
        'name' => (string) $env->name,
        'project_uuid' => (string) $proj->uuid,
        'project_name' => (string) $proj->name,
    ];
}
$envOptionsJson = json_encode($envOptions);
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="page-header-title"><?= e($template->name) ?></h1>
                    <?php if (!empty($template->is_official)): ?>
                        <span class="badge badge-success">Official</span>
                    <?php endif; ?>
                    <?php if (!empty($template->version)): ?>
                        <span class="badge badge-neutral">v<?= e($template->version) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($template->description)): ?>
                    <p class="page-header-description"><?= e($template->description) ?></p>
                <?php endif; ?>
            </div>
            <div class="page-header-actions">
                <a href="/templates" class="btn btn-ghost">Back</a>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <form method="POST" action="/templates/<?= e($template->slug) ?>/deploy" id="templateDeployForm" class="flex flex-col gap-6">
                <?= csrf_field() ?>
                <input type="hidden" name="port_reservation_uuid" id="port_reservation_uuid" value="<?= e(old('port_reservation_uuid', '')) ?>">

                <div class="card card-glass">
                    <div class="card-header">
                        <h2 class="card-title">Basic Information</h2>
                    </div>
                    <div class="card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="label" for="name">Application Name <span class="text-danger">*</span></label>
                                <input class="input w-full" type="text" id="name" name="name" required value="<?= e(old('name', $template->name)) ?>">
                                <?php if (!empty($errors['name'])): ?>
                                    <p class="form-error"><?= e($errors['name']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div>
                                <label class="label" for="node_uuid">Deploy to Node <span class="text-danger">*</span></label>
                                <select class="select w-full" name="node_uuid" id="node_uuid" required>
                                    <option value="">Select a node…</option>
                                    <?php foreach ($nodes as $n): ?>
                                        <option value="<?= e($n->uuid) ?>" <?= ($selectedNodeUuid !== '' && $selectedNodeUuid === (string)$n->uuid) ? 'selected' : '' ?>>
                                            <?= e($n->name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!empty($errors['node_uuid'])): ?>
                                    <p class="form-error"><?= e($errors['node_uuid']) ?></p>
                                <?php endif; ?>
                                <p class="form-hint">Node access is validated when you submit.</p>
                            </div>

                            <div>
                                <label class="label" for="project_uuid">Project <span class="text-danger">*</span></label>
                                <select class="select w-full" name="project_uuid" id="project_uuid" required>
                                    <option value="">Select a project…</option>
                                    <?php foreach ($projects as $p): ?>
                                        <option value="<?= e($p->uuid) ?>" <?= ($selectedProjectUuid !== '' && $selectedProjectUuid === (string)$p->uuid) ? 'selected' : '' ?>>
                                            <?= e($p->name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!empty($errors['project_uuid'])): ?>
                                    <p class="form-error"><?= e($errors['project_uuid']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div>
                                <label class="label" for="environment_uuid">Environment <span class="text-danger">*</span></label>
                                <select class="select w-full" name="environment_uuid" id="environment_uuid" required>
                                    <option value="">Select an environment…</option>
                                </select>
                                <?php if (!empty($errors['environment_uuid'])): ?>
                                    <p class="form-error"><?= e($errors['environment_uuid']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="md:col-span-2">
                                <label class="label" for="description">Description</label>
                                <textarea class="textarea w-full" id="description" name="description" rows="2" placeholder="Optional description"><?= e(old('description', $template->description ?? '')) ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card card-glass">
                    <div class="card-header flex items-center justify-between gap-4 flex-wrap">
                        <h2 class="card-title">Ports</h2>
                        <button type="button" class="btn btn-secondary btn-sm" id="add-port-btn">New Port</button>
                    </div>
                    <div class="card-body">
                        <div id="ports-list" class="flex flex-col gap-2"></div>
                        <div id="ports-empty" class="text-muted text-sm">No ports allocated.</div>
                        <p class="form-hint mt-md">Use <code>{port[0]}</code>, <code>{port[1]}</code>, … in environment variables.</p>
                    </div>
                </div>

                <div class="card card-glass">
                    <div class="card-header flex items-center justify-between gap-4 flex-wrap">
                        <h2 class="card-title">Environment Variables</h2>
                        <div class="flex items-center gap-2 flex-wrap">
                            <button type="button" class="btn btn-ghost btn-sm" id="bulk-edit-btn">Bulk Edit</button>
                            <button type="button" class="btn btn-secondary btn-sm" id="add-env-btn">Add Variable</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <input type="hidden" name="environment_variables" id="env-serialized" value="">
                        <?php if (!empty($errors['environment_variables'])): ?>
                            <p class="form-error mb-3"><?= e($errors['environment_variables']) ?></p>
                        <?php endif; ?>
                        <div id="env-rows" class="flex flex-col gap-3"></div>
                        <div id="env-empty" class="text-muted text-sm">No environment variables configured.</div>
                        <p class="form-hint mt-md">Values are hidden by default.</p>
                    </div>
                </div>

                <div class="card card-glass">
                    <div class="card-header">
                        <h2 class="card-title">Resource Limits</h2>
                    </div>
                    <div class="card-body">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="label" for="memory_limit">Memory Limit</label>
                                <input class="input w-full" type="text" name="memory_limit" id="memory_limit" value="<?= e(old('memory_limit', '512m')) ?>" placeholder="512m">
                                <?php if (!empty($errors['memory_limit'])): ?>
                                    <p class="form-error"><?= e($errors['memory_limit']) ?></p>
                                <?php endif; ?>
                                <p class="form-hint">Examples: 512m, 1g, 2048m</p>
                            </div>

                            <div>
                                <label class="label" for="cpu_limit">CPU Limit</label>
                                <input class="input w-full" type="text" name="cpu_limit" id="cpu_limit" value="<?= e(old('cpu_limit', '1')) ?>" placeholder="1">
                                <?php if (!empty($errors['cpu_limit'])): ?>
                                    <p class="form-error"><?= e($errors['cpu_limit']) ?></p>
                                <?php endif; ?>
                                <p class="form-hint">Examples: 0.5, 1, 2</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3">
                    <a href="/templates" class="btn btn-ghost">Cancel</a>
                    <button class="btn btn-primary" type="submit">Create Application</button>
                </div>
            </form>
        </div>

        <div class="flex flex-col gap-6">
            <div class="card">
                <div class="card-body">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm text-tertiary">Category</p>
                            <p class="font-medium"><?= e($template->category ?? 'Other') ?></p>
                        </div>
                        <?php if (!empty($template->documentation)): ?>
                            <a class="btn btn-secondary" href="<?= e($template->documentation) ?>" target="_blank" rel="noopener">Documentation</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h2 class="text-lg font-medium mb-2">Details</h2>
                    <div class="flex flex-col gap-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <span class="text-tertiary">Slug</span>
                            <span class="text-secondary"><?= e($template->slug) ?></span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-tertiary">Active</span>
                            <span class="text-secondary"><?= !empty($template->is_active) ? 'Yes' : 'No' ?></span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-tertiary">Extra files</span>
                            <span class="text-secondary"><?= count($template->getExtraFiles()) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php unset($_SESSION['_errors'], $_SESSION['_old_input']); ?>

<style>
.form-error {
    font-size: var(--text-sm);
    color: var(--red-400);
    margin-top: var(--space-xs);
}
.form-hint {
    font-size: var(--text-xs);
    color: var(--text-tertiary);
    margin-top: var(--space-xs);
}
.env-key {
    flex: 0 0 180px;
    min-width: 120px;
}
</style>

<script>
(function() {
    'use strict';

    const els = {
        project: document.getElementById('project_uuid'),
        env: document.getElementById('environment_uuid'),
        envRows: document.getElementById('env-rows'),
        envEmpty: document.getElementById('env-empty'),
        envSerialized: document.getElementById('env-serialized'),
        addEnvBtn: document.getElementById('add-env-btn'),
        bulkEditBtn: document.getElementById('bulk-edit-btn'),
    };

    // Filter env dropdown by project
    function applyEnvFilter() {
        if (!els.project || !els.env) return;
        const p = (els.project.value || '').trim();
        let firstVisible = '';
        Array.from(els.env.options).forEach((opt) => {
            if (!opt.value) return;
            const proj = opt.getAttribute('data-project') || '';
            const visible = !p || proj === p;
            opt.hidden = !visible;
            if (visible && !firstVisible) firstVisible = opt.value;
        });

        const selected = (els.env.value || '').trim();
        const selectedOpt = selected ? els.env.querySelector('option[value="' + CSS.escape(selected) + '"]') : null;
        if (selectedOpt && selectedOpt.hidden) {
            els.env.value = firstVisible || '';
        }
    }

    if (els.project) {
        els.project.addEventListener('change', applyEnvFilter);
        applyEnvFilter();
    }

    // Environment Variables editor (copied from application create UX)
    const state = { envRows: [] };
    const config = { initialEnvB64: '<?= e($initialEnvB64) ?>' };

    function updateEnvSerialized() {
        const serialized = state.envRows
            .filter(r => r.key)
            .map(r => r.key + '=' + r.value)
            .join('\n');
        if (els.envSerialized) {
            els.envSerialized.value = serialized;
        }
    }

    function escapeAttr(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function renderEnvRows() {
        if (!els.envRows || !els.envEmpty) return;

        if (state.envRows.length === 0) {
            els.envEmpty.classList.remove('hidden');
            els.envRows.innerHTML = '';
            updateEnvSerialized();
            return;
        }

        els.envEmpty.classList.add('hidden');
        els.envRows.innerHTML = state.envRows.map((row, idx) => `
            <div class="env-row flex flex-wrap items-center gap-2 p-2 rounded-md" data-index="${idx}">
                <input type="text" class="input input-sm env-key" placeholder="KEY" value="${escapeAttr(row.key)}" data-field="key">
                <input type="${row.revealed ? 'text' : 'password'}" class="input input-sm flex-1" placeholder="value" value="${escapeAttr(row.value)}" data-field="value">
                <button type="button" class="btn btn-ghost btn-sm toggle-reveal">${row.revealed ? 'Hide' : 'Show'}</button>
                <button type="button" class="btn btn-danger-ghost btn-sm remove-row">Remove</button>
            </div>
        `).join('');

        els.envRows.querySelectorAll('.env-row').forEach(row => {
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
                state.envRows.splice(idx, 1);
                renderEnvRows();
            });
        });

        updateEnvSerialized();
    }

    function parseEnvString(str) {
        state.envRows = [];
        if (!str) {
            updateEnvSerialized();
            return;
        }

        const lines = String(str).split(/\r?\n/);
        for (const line of lines) {
            const trimmed = String(line || '').trim();
            if (!trimmed || trimmed.startsWith('#')) continue;

            const idx = trimmed.indexOf('=');
            if (idx === -1) continue;

            const key = trimmed.substring(0, idx).trim();
            const value = trimmed.substring(idx + 1);
            if (!key) continue;
            state.envRows.push({ key, value, revealed: false });
        }

        updateEnvSerialized();
    }

    function openBulkEditor() {
        const currentValue = state.envRows
            .filter(r => r.key)
            .map(r => r.key + '=' + r.value)
            .join('\n');

        if (!window.Modal || typeof window.Modal.prompt !== 'function') {
            // Fallback: no modal available.
            const v = window.prompt('Bulk Edit Environment Variables (KEY=VALUE per line)', currentValue);
            if (v === null) return;
            parseEnvString(v || '');
            renderEnvRows();
            return;
        }

        window.Modal.prompt('Bulk Edit Environment Variables', {
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

    function addEnvRow(key = '', value = '') {
        state.envRows.push({ key, value, revealed: false });
        renderEnvRows();
    }

    // Init env from template
    try {
        const str = atob(config.initialEnvB64 || '');
        parseEnvString(str);
    } catch {
        parseEnvString('');
    }
    renderEnvRows();

    if (els.addEnvBtn) {
        els.addEnvBtn.addEventListener('click', () => addEnvRow('', ''));
    }
    if (els.bulkEditBtn) {
        els.bulkEditBtn.addEventListener('click', openBulkEditor);
    }
})();
</script>
