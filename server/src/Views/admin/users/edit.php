<?php
/**
 * Admin Users Edit
 */

$errors = $_SESSION['_errors'] ?? [];
unset($_SESSION['_errors']);
$old = $_SESSION['_old_input'] ?? [];
unset($_SESSION['_old_input']);

/** @var \Chap\Models\User $editUser */
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <nav class="breadcrumb">
                    <span class="breadcrumb-item"><a href="/admin/users">Users</a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current"><?= e($editUser->username ?: $editUser->email) ?></span>
                </nav>

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-blue icon-box-lg">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="page-header-title truncate"><?= e(($editUser->name ?: $editUser->username) ?: 'User') ?></h1>
                        <p class="page-header-description truncate"><?= e($editUser->email) ?></p>
                    </div>
                </div>
            </div>
            <div class="page-header-actions">
                <span class="badge <?= (bool)$editUser->is_admin ? 'badge-success' : 'badge-default' ?>"><?= (bool)$editUser->is_admin ? 'Admin' : 'User' ?></span>
                <span class="badge <?= (bool)$editUser->two_factor_enabled ? 'badge-success' : 'badge-default' ?>"><?= (bool)$editUser->two_factor_enabled ? '2FA enabled' : '2FA off' ?></span>
                <a href="/admin/users" class="btn btn-secondary">Back</a>

                <form method="POST" action="/admin/users/<?= (int)$editUser->id ?>" id="delete-user-form" class="inline-block">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="button" class="btn btn-danger-ghost" id="delete-user-btn">Delete User</button>
                </form>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">User Information</h2>
            </div>

            <div class="card-body">
                <dl class="flex flex-col gap-4">
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <dt class="text-tertiary">User ID</dt>
                        <dd class="m-0"><code><?= (int)$editUser->id ?></code></dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <dt class="text-tertiary">Username</dt>
                        <dd class="m-0"><code><?= e($editUser->username) ?></code></dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <dt class="text-tertiary">Email</dt>
                        <dd class="m-0"><code class="break-all"><?= e($editUser->email) ?></code></dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <dt class="text-tertiary">Role</dt>
                        <dd class="m-0"><?= (bool)$editUser->is_admin ? '<span class="badge badge-success">Admin</span>' : '<span class="badge badge-default">User</span>' ?></dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <dt class="text-tertiary">Two-Factor Auth</dt>
                        <dd class="m-0"><?= (bool)$editUser->two_factor_enabled ? '<span class="badge badge-success">Enabled</span>' : '<span class="badge badge-default">Disabled</span>' ?></dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <dt class="text-tertiary">Email Verified</dt>
                        <dd class="m-0"><?= !empty($editUser->email_verified_at) ? '<span class="badge badge-success">Verified</span>' : '<span class="badge badge-default">Not verified</span>' ?></dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 text-sm">
                        <dt class="text-tertiary">Created</dt>
                        <dd class="m-0"><?= !empty($editUser->created_at) ? time_ago($editUser->created_at) : '-' ?></dd>
                    </div>
                </dl>
            </div>

            <div class="card-footer">
                <form method="POST" action="/admin/users/<?= (int)$editUser->id ?>" class="form" id="update-user-form" data-confirm-resource-limits="1">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="_method" value="PUT">

                    <div class="flex flex-col gap-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="form-group">
                                <label class="form-label" for="name">Name</label>
                                <input class="input <?= !empty($errors['name']) ? 'input-error' : '' ?>" id="name" name="name" type="text"
                                       value="<?= e($old['name'] ?? $editUser->name ?? '') ?>" required>
                                <?php if (!empty($errors['name'])): ?>
                                    <p class="form-error"><?= e($errors['name']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="username">Username</label>
                                <input class="input <?= !empty($errors['username']) ? 'input-error' : '' ?>" id="username" name="username" type="text"
                                       value="<?= e($old['username'] ?? $editUser->username ?? '') ?>" required>
                                <?php if (!empty($errors['username'])): ?>
                                    <p class="form-error"><?= e($errors['username']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="email">Email</label>
                            <input class="input <?= !empty($errors['email']) ? 'input-error' : '' ?>" id="email" name="email" type="email"
                                   value="<?= e($old['email'] ?? $editUser->email ?? '') ?>" required>
                            <?php if (!empty($errors['email'])): ?>
                                <p class="form-error"><?= e($errors['email']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="password">New Password <span class="text-tertiary">(optional)</span></label>
                            <input class="input <?= !empty($errors['password']) ? 'input-error' : '' ?>" id="password" name="password" type="password" placeholder="Leave blank to keep current">
                            <?php if (!empty($errors['password'])): ?>
                                <p class="form-error"><?= e($errors['password']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <?php $checked = ($old['is_admin'] ?? null) !== null
                                ? (($old['is_admin'] ?? '') === 'on')
                                : (bool)$editUser->is_admin;
                            ?>
                            <label class="checkbox">
                                <input type="checkbox" name="is_admin" <?= $checked ? 'checked' : '' ?>>
                                <span>Admin</span>
                            </label>
                            <?php if (!empty($errors['is_admin'])): ?>
                                <p class="form-error"><?= e($errors['is_admin']) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="divider"></div>

                        <div class="form-group">
                            <label class="form-label">User Resource Maximums</label>
                            <p class="form-hint">Hard caps for this user. All team/project/environment/application limits must fit within these totals.</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="form-group">
                                <label class="form-label" for="max_cpu_millicores">CPU Max (millicores)</label>
                                <input class="input <?= !empty($errors['max_cpu_millicores']) ? 'input-error' : '' ?>" id="max_cpu_millicores" name="max_cpu_millicores" type="number" min="-1"
                                       value="<?= e($old['max_cpu_millicores'] ?? (string)($editUser->max_cpu_millicores ?? 2000)) ?>" required>
                                <p class="form-hint">1000 = 1 CPU core. Use <strong>-1</strong> for unlimited.</p>
                                <?php if (!empty($errors['max_cpu_millicores'])): ?>
                                    <p class="form-error"><?= e($errors['max_cpu_millicores']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="max_ram_mb">RAM Max (MB)</label>
                                <input class="input <?= !empty($errors['max_ram_mb']) ? 'input-error' : '' ?>" id="max_ram_mb" name="max_ram_mb" type="number" min="-1"
                                       value="<?= e($old['max_ram_mb'] ?? (string)($editUser->max_ram_mb ?? 4096)) ?>" required>
                                <p class="form-hint">Use <strong>-1</strong> for unlimited.</p>
                                <?php if (!empty($errors['max_ram_mb'])): ?>
                                    <p class="form-error"><?= e($errors['max_ram_mb']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="max_storage_mb">Storage Max (MB)</label>
                                <input class="input <?= !empty($errors['max_storage_mb']) ? 'input-error' : '' ?>" id="max_storage_mb" name="max_storage_mb" type="number" min="-1"
                                       value="<?= e($old['max_storage_mb'] ?? (string)($editUser->max_storage_mb ?? 20480)) ?>" required>
                                <p class="form-hint">Use <strong>-1</strong> for unlimited.</p>
                                <?php if (!empty($errors['max_storage_mb'])): ?>
                                    <p class="form-error"><?= e($errors['max_storage_mb']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="max_ports">Ports Max (count)</label>
                                <input class="input <?= !empty($errors['max_ports']) ? 'input-error' : '' ?>" id="max_ports" name="max_ports" type="number" min="-1"
                                       value="<?= e($old['max_ports'] ?? (string)($editUser->max_ports ?? 50)) ?>" required>
                                <p class="form-hint">Use <strong>-1</strong> for unlimited.</p>
                                <?php if (!empty($errors['max_ports'])): ?>
                                    <p class="form-error"><?= e($errors['max_ports']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="max_bandwidth_mbps">Bandwidth Max (Mbps)</label>
                                <input class="input <?= !empty($errors['max_bandwidth_mbps']) ? 'input-error' : '' ?>" id="max_bandwidth_mbps" name="max_bandwidth_mbps" type="number" min="-1"
                                       value="<?= e($old['max_bandwidth_mbps'] ?? (string)($editUser->max_bandwidth_mbps ?? 100)) ?>" required>
                                <p class="form-hint">Use <strong>-1</strong> for unlimited.</p>
                                <?php if (!empty($errors['max_bandwidth_mbps'])): ?>
                                    <p class="form-error"><?= e($errors['max_bandwidth_mbps']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="max_pids">Process Limit (PIDs)</label>
                                <input class="input <?= !empty($errors['max_pids']) ? 'input-error' : '' ?>" id="max_pids" name="max_pids" type="number" min="-1"
                                       value="<?= e($old['max_pids'] ?? (string)($editUser->max_pids ?? 1024)) ?>" required>
                                <p class="form-hint">Use <strong>-1</strong> for unlimited.</p>
                                <?php if (!empty($errors['max_pids'])): ?>
                                    <p class="form-error"><?= e($errors['max_pids']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="divider"></div>

                        <div class="form-group">
                            <label class="form-label" for="node_access">Node Access</label>
                            <?php $nodeAccessMode = $old['node_access_mode'] ?? ($nodeAccessMode ?? ($editUser->node_access_mode ?? 'allow_selected')); ?>
                            <p class="form-hint">Choose how to interpret the node list. Users can only further restrict node access at lower levels.</p>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-3">
                                <div class="form-group">
                                    <label class="form-label" for="node_access_mode">Mode</label>
                                    <select class="select" id="node_access_mode" name="node_access_mode" required>
                                        <option value="allow_selected" <?= ($nodeAccessMode === 'allow_selected') ? 'selected' : '' ?>>Allow only selected nodes</option>
                                        <option value="allow_all_except" <?= ($nodeAccessMode === 'allow_all_except') ? 'selected' : '' ?>>Allow all nodes except selected</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">&nbsp;</label>
                                    <p class="form-hint" id="node-access-mode-hint"></p>
                                </div>
                            </div>

                            <div class="dropdown" id="user-node-access-dropdown">
                                <button type="button" class="btn btn-secondary w-full dropdown-trigger" id="user-node-access-btn" data-dropdown-trigger="user-node-access-menu" data-dropdown-placement="bottom-start" aria-expanded="false">
                                    <span id="user-node-access-label">Select nodes...</span>
                                    <svg class="icon dropdown-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>
                                <div class="dropdown-menu w-full" id="user-node-access-menu">
                                    <div class="dropdown-search">
                                        <input type="text" class="input input-sm" placeholder="Search nodes..." id="user-node-access-search" autocomplete="off">
                                    </div>
                                    <div class="dropdown-items" id="user-node-access-items"></div>
                                </div>
                            </div>

                            <select class="hidden" id="node_access" name="node_access[]" multiple size="8" hidden>
                                <?php
                                /** @var \Chap\Models\Node[] $nodes */
                                $nodes = $nodes ?? [];
                                $selectedNodeIds = $selectedNodeIds ?? [];
                                ?>
                                <?php foreach ($nodes as $n): ?>
                                    <option value="<?= (int)$n->id ?>" <?= in_array((int)$n->id, $selectedNodeIds, true) ? 'selected' : '' ?>>
                                        <?= e($n->name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-hint" id="node-access-list-hint">Click to toggle nodes. Search to filter.</p>
                        </div>

                        <div class="flex items-center gap-3">
                            <button type="submit" class="btn btn-primary">Update</button>
                            <a href="/admin/users" class="btn btn-secondary">Cancel</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Security & Actions</h2>
            </div>
            <div class="card-body">
                <p class="text-secondary text-sm mb-4">Manage authentication and security-related actions for this user.</p>

                <div class="bg-tertiary border border-primary rounded-lg p-4">
                    <p class="text-xs text-tertiary mb-2">Two-Factor Authentication</p>
                    <p class="text-sm m-0">
                        <?= (bool)$editUser->two_factor_enabled
                            ? '<span class="badge badge-success">Enabled</span>'
                            : '<span class="badge badge-default">Disabled</span>' ?>
                    </p>
                    <?php if ((bool)$editUser->two_factor_enabled): ?>
                        <p class="text-xs text-tertiary mt-2">If the user loses access to their authenticator app, you can reset MFA so they can set it up again.</p>
                    <?php else: ?>
                        <p class="text-xs text-tertiary mt-2">The user can enable MFA from their profile.</p>
                    <?php endif; ?>
                </div>

                <?php if ((bool)$editUser->two_factor_enabled): ?>
                    <div class="mt-4">
                        <form method="POST" action="/admin/users/<?= (int)$editUser->id ?>/mfa/reset" onsubmit="return confirm('Reset MFA for this user? They will need to set up MFA again.');">
                            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                            <button type="submit" class="btn btn-secondary w-full">Reset MFA</button>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="divider"></div>

                <div class="bg-tertiary border border-primary rounded-lg p-4">
                    <p class="text-xs text-tertiary mb-2">Password Changes</p>
                    <p class="text-xs text-tertiary m-0">Setting a new password will immediately replace the current one.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const modeSelect = document.getElementById('node_access_mode');
    const modeHint = document.getElementById('node-access-mode-hint');
    const listHint = document.getElementById('node-access-list-hint');
    const select = document.getElementById('node_access');
    const label = document.getElementById('user-node-access-label');
    const itemsEl = document.getElementById('user-node-access-items');
    const searchEl = document.getElementById('user-node-access-search');
    const menuEl = document.getElementById('user-node-access-menu');

    if (!select || !label || !itemsEl || !searchEl || !menuEl) return;

    const options = Array.from(select.options).map(o => ({
        value: o.value,
        label: (o.textContent || '').trim(),
        selected: !!o.selected,
        disabled: !!o.disabled,
    }));

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeAttr(str) {
        return String(str).replace(/"/g, '&quot;');
    }

    function getMode() {
        return (modeSelect && modeSelect.value) ? modeSelect.value : 'allow_selected';
    }

    function updateHints() {
        const mode = getMode();
        if (modeHint) {
            modeHint.textContent = mode === 'allow_all_except'
                ? 'Selected nodes are blocked; all others are allowed.'
                : 'Selected nodes are allowed; all others are blocked.';
        }
        if (listHint) {
            listHint.textContent = mode === 'allow_all_except'
                ? 'Click to block/unblock nodes. Search to filter.'
                : 'Click to allow/disallow nodes. Search to filter.';
        }
    }

    function updateLabel() {
        const selected = options.filter(o => o.selected);
        if (selected.length === 0) {
            label.textContent = 'Select nodes...';
            return;
        }
        if (selected.length === 1) {
            label.textContent = selected[0].label;
            return;
        }
        label.textContent = `${selected.length} nodes selected`;
    }

    function syncSelect() {
        options.forEach((opt, idx) => {
            const o = select.options[idx];
            if (o) o.selected = opt.selected;
        });
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function render(query = '') {
        const q = query.trim().toLowerCase();
        const visible = options.filter(o => !q || o.label.toLowerCase().includes(q));

        if (visible.length === 0) {
            itemsEl.innerHTML = '<div class="dropdown-empty">No nodes found</div>';
            return;
        }

        const mode = getMode();
        itemsEl.innerHTML = visible.map(o => {
            const active = o.selected ? 'active' : '';
            const disabled = o.disabled ? 'disabled' : '';
            const prefix = o.selected ? (mode === 'allow_all_except' ? '✕ ' : '✓ ') : '';
            return `<button type="button" class="dropdown-item ${active} ${disabled}" data-value="${escapeAttr(o.value)}">${prefix}${escapeHtml(o.label)}</button>`;
        }).join('');

        itemsEl.querySelectorAll('.dropdown-item').forEach(btn => {
            btn.addEventListener('click', () => {
                if (btn.classList.contains('disabled')) return;
                const value = btn.getAttribute('data-value');
                const opt = options.find(x => x.value === value);
                if (!opt) return;
                opt.selected = !opt.selected;
                syncSelect();
                updateLabel();
                render(searchEl.value);
            });
        });
    }

    updateHints();
    updateLabel();
    render('');

    if (modeSelect) {
        modeSelect.addEventListener('change', () => {
            updateHints();
            updateLabel();
            render(searchEl.value);
        });
    }

    searchEl.addEventListener('input', () => render(searchEl.value));
    menuEl.addEventListener('dropdown:open', () => {
        searchEl.value = '';
        render('');
        setTimeout(() => searchEl.focus(), 0);
    });
})();

document.getElementById('delete-user-btn')?.addEventListener('click', function() {
    Modal.confirmDelete('Are you sure you want to delete this user? This action cannot be undone.')
        .then(confirmed => {
            if (confirmed) {
                document.getElementById('delete-user-form')?.submit();
            }
        });
});
</script>
