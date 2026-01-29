<?php
/**
 * Settings Index View
 * Updated to use new design system
 */
$errors = $_SESSION['_errors'] ?? [];
$old = $_SESSION['_old_input'] ?? [];
unset($_SESSION['_errors'], $_SESSION['_old_input']);

$notificationSettings = $notificationSettings ?? [];

$notifyDeployEnabled = array_key_exists('notify_deployments_enabled', $old)
    ? ((string)$old['notify_deployments_enabled'] === '1')
    : (bool)($notificationSettings['deployments']['enabled'] ?? false);
$notifyDeployMode = array_key_exists('notify_deployments_mode', $old)
    ? (string)$old['notify_deployments_mode']
    : (string)($notificationSettings['deployments']['mode'] ?? 'all');
$notifyGeneralEnabled = array_key_exists('notify_general_enabled', $old)
    ? ((string)$old['notify_general_enabled'] === '1')
    : (bool)($notificationSettings['general']['enabled'] ?? false);
$notifyEmailEnabled = array_key_exists('notify_channel_email', $old)
    ? ((string)$old['notify_channel_email'] === '1')
    : (bool)($notificationSettings['channels']['email'] ?? true);
$notifyWebhookEnabled = array_key_exists('notify_channel_webhook', $old)
    ? ((string)$old['notify_channel_webhook'] === '1')
    : (bool)($notificationSettings['channels']['webhook']['enabled'] ?? false);
$notifyWebhookUrl = array_key_exists('notify_webhook_url', $old)
    ? (string)$old['notify_webhook_url']
    : (string)($notificationSettings['channels']['webhook']['url'] ?? '');
?>

<div class="page-header">
    <div class="page-header-top">
        <div>
            <h1 class="page-header-title">Settings</h1>
            <p class="page-header-description">Configure your application preferences</p>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 flex flex-col gap-6">
        <!-- General Settings -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">General Settings</h2>
                    <p class="text-secondary text-sm">Configure general application settings.</p>
                </div>
            </div>
            <div class="card-body">
                <form action="/settings" method="POST">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

                    <div class="form-group">
                        <label for="default_node" class="form-label">Default Node</label>
                        <select name="default_node" id="default_node" class="select">
                            <option value="">Select a default node...</option>
                            <!-- Nodes would be populated here -->
                        </select>
                        <p class="form-hint">New applications will be deployed to this node by default.</p>
                    </div>

                    <?php if (!empty($canWriteSettings)): ?>
                        <div class="flex items-center justify-end">
                            <button type="submit" class="btn btn-primary">Save Settings</button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- API Tokens -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">API Tokens</h2>
                    <p class="text-secondary text-sm">Manage your API access tokens.</p>
                </div>
            </div>
            <div class="card-body">
                <?php
                $availableScopes = [
                    'teams:read','teams:write',
                    'projects:read','projects:write',
                    'environments:read','environments:write',
                    'applications:read','applications:write','applications:deploy',
                    'deployments:read','deployments:write','deployments:cancel','deployments:rollback',
                    'logs:read','logs:stream',
                    'templates:read','templates:deploy',
                    'services:read','services:write',
                    'databases:read','databases:write',
                    'nodes:read','nodes:session:mint',
                    'files:read','files:write','files:delete',
                    'exec:run',
                    'volumes:read','volumes:write',
                    'activity:read',
                    'webhooks:read','webhooks:write',
                    'git_sources:read','git_sources:write',
                    'settings:read','settings:write',
                ];
                $availableScopesB64 = base64_encode(json_encode(array_values($availableScopes)));
                $defaultScopesB64 = base64_encode(json_encode(array_values($availableScopes)));
                ?>
                <p class="text-secondary text-sm mb-4">Use the docs for examples and scope reference:</p>
                <p class="text-sm mb-4">
                    <a class="link" href="https://mjdaws0n.github.io/chap/api/client-api.html" target="_blank" rel="noreferrer">https://mjdaws0n.github.io/chap/api/client-api.html</a>
                </p>

                <div class="bg-tertiary border border-primary rounded-lg p-4 mb-4">
                    <p class="text-xs text-tertiary mb-2">Your API endpoint</p>
                    <code><?= e(url('/api/v2')) ?></code>
                </div>

                <?php if (!empty($newApiToken) && !empty($newApiToken['token'])): ?>
                    <div class="bg-success/10 border border-success rounded-lg p-4 mb-4">
                        <p class="text-sm font-medium mb-2">New token created</p>
                        <p class="text-xs text-secondary mb-2">Copy it now — it will not be shown again.</p>
                        <div class="flex flex-col gap-2">
                            <div class="text-xs text-tertiary">Token</div>
                            <code class="break-all" id="new-token"><?= e((string)$newApiToken['token']) ?></code>
                            <div class="flex items-center gap-2 mt-2">
                                <button type="button" class="btn btn-secondary" id="copy-new-token-btn">Copy token</button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($canWriteSettings)): ?>
                    <button type="button" class="btn btn-secondary" id="generate-token-btn">Generate New Token</button>

                    <div id="create-token-inline" class="mt-4 hidden">
                        <div class="border border-primary rounded-lg p-4 bg-tertiary">
                            <div class="flex items-center justify-between gap-3 mb-3">
                                <div>
                                    <div class="text-sm font-medium">Create API token</div>
                                    <div class="text-xs text-secondary">Token will be shown once after creation.</div>
                                </div>
                                <button type="button" class="btn btn-secondary btn-sm" id="cancel-create-token-btn">Cancel</button>
                            </div>

                            <form action="/settings/api-tokens" method="POST" class="flex flex-col gap-4">
                                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

                                <div class="form-group">
                                    <label class="form-label">Name</label>
                                    <input class="input" name="name" placeholder="my-cli" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Expires</label>
                                    <select class="select" name="expires_in">
                                        <option value="6h">6 hours</option>
                                        <option value="1d">1 day</option>
                                        <option value="7d">7 days</option>
                                        <option value="30d">30 days</option>
                                        <option value="60d">60 days</option>
                                        <option value="90d">90 days</option>
                                        <option value="1y">1 year</option>
                                        <option value="never" selected>No expiry</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <div class="flex items-center justify-between gap-3">
                                        <label class="form-label m-0">Scopes</label>
                                        <button type="button" class="btn btn-secondary btn-sm" data-scope-picker-open>Show scopes</button>
                                    </div>

                                    <div class="mt-2" data-scope-picker data-available-scopes-b64="<?= e($availableScopesB64) ?>" data-default-scopes-b64="<?= e($defaultScopesB64) ?>">
                                        <div class="flex items-center justify-between gap-3">
                                            <p class="text-sm text-secondary m-0">
                                                Selected: <span class="font-medium" data-scope-count>0</span>
                                            </p>
                                            <button type="button" class="btn btn-ghost btn-sm" data-scope-clear>Clear</button>
                                        </div>
                                        <div class="flex flex-wrap gap-2 mt-2" data-scope-preview></div>
                                        <div data-scope-inputs></div>
                                    </div>
                                </div>

                                <div class="flex items-center justify-end gap-2">
                                    <button type="submit" class="btn btn-primary">Create token</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="mt-6">
                    <h3 class="text-sm font-medium mb-2">Your tokens</h3>
                    <?php if (empty($apiTokens)): ?>
                        <p class="text-secondary text-sm">No tokens yet.</p>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Scopes</th>
                                        <th>Created</th>
                                        <th>Last used</th>
                                        <th>Expires</th>
                                        <?php if (!empty($canWriteSettings)): ?><th></th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($apiTokens as $t): ?>
                                        <tr>
                                            <td>
                                                <div class="font-medium"><?= e((string)$t['name']) ?></div>
                                            </td>
                                            <td>
                                                <?php
                                                $scopesList = (array)($t['scopes'] ?? []);
                                                $scopesB64 = base64_encode(json_encode(array_values($scopesList)));
                                                ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-ghost btn-sm"
                                                    data-token-scopes-b64="<?= e($scopesB64) ?>"
                                                    data-token-name="<?= e((string)$t['name']) ?>">
                                                    Show scopes (<?= count($scopesList) ?>)
                                                </button>
                                            </td>
                                            <td class="text-xs text-secondary">
                                                <?= !empty($t['created_at']) ? e(time_ago((string)$t['created_at'])) : '—' ?>
                                            </td>
                                            <td class="text-xs text-secondary">
                                                <?= !empty($t['last_used_at']) ? e(time_ago((string)$t['last_used_at'])) : '—' ?>
                                            </td>
                                            <td class="text-xs text-secondary">
                                                <?= !empty($t['expires_at']) ? e(time_ago((string)$t['expires_at'])) : 'Never' ?>
                                            </td>
                                            <?php if (!empty($canWriteSettings)): ?>
                                                <td class="text-right">
                                                    <form method="POST" action="/settings/api-tokens/<?= e((string)$t['token_id']) ?>/revoke" data-revoke-token-form data-token-name="<?= e((string)$t['name']) ?>">
                                                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                                                    </form>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="flex flex-col gap-6">
        <!-- About -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">About</h2>
                    <p class="text-secondary text-sm">Version information for this Chap instance.</p>
                </div>
            </div>
            <div class="card-body">
                <div class="flex items-center justify-between gap-4">
                    <span class="text-secondary text-sm">Server Version</span>
                    <code><?= e(\Chap\Config::serverVersion()) ?></code>
                </div>
            </div>
        </div>

        <!-- Notifications -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Notifications</h2>
                    <p class="text-secondary text-sm">Configure how you receive notifications.</p>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" action="/settings" class="flex flex-col gap-6">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="notify_settings_form" value="1">

                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="font-medium">Deployment Notifications</p>
                            <p class="text-secondary text-sm">Get notified when deployments finish (success or failed).</p>
                        </div>
                        <label class="toggle" aria-label="Deployment Notifications">
                            <input type="hidden" name="notify_deployments_enabled" value="0">
                            <input type="checkbox" name="notify_deployments_enabled" value="1" <?= $notifyDeployEnabled ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="notify_deployments_mode">Notify on</label>
                        <select class="select" id="notify_deployments_mode" name="notify_deployments_mode">
                            <option value="all" <?= $notifyDeployMode === 'all' ? 'selected' : '' ?>>All deployments</option>
                            <option value="failed" <?= $notifyDeployMode === 'failed' ? 'selected' : '' ?>>Failed deployments only</option>
                        </select>
                    </div>

                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="font-medium">General Notifications</p>
                            <p class="text-secondary text-sm">Team membership changes, resource limit updates, and node down alerts.</p>
                        </div>
                        <label class="toggle" aria-label="General Notifications">
                            <input type="hidden" name="notify_general_enabled" value="0">
                            <input type="checkbox" name="notify_general_enabled" value="1" <?= $notifyGeneralEnabled ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="border-t border-primary pt-4">
                        <p class="font-medium mb-2">Delivery</p>
                        <div class="flex flex-col gap-3">
                            <label class="flex items-center gap-2">
                                <input type="hidden" name="notify_channel_email" value="0">
                                <input type="checkbox" name="notify_channel_email" value="1" class="checkbox" <?= $notifyEmailEnabled ? 'checked' : '' ?>>
                                <span>Email</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="hidden" name="notify_channel_webhook" value="0">
                                <input type="checkbox" name="notify_channel_webhook" value="1" class="checkbox" <?= $notifyWebhookEnabled ? 'checked' : '' ?>>
                                <span>Webhook</span>
                            </label>
                            <div class="form-group">
                                <label class="form-label" for="notify_webhook_url">Webhook URL</label>
                                <input type="url" id="notify_webhook_url" name="notify_webhook_url" class="input <?= !empty($errors['notify_webhook_url']) ? 'input-error' : '' ?>" placeholder="https://example.com/chap-webhook" value="<?= e($notifyWebhookUrl) ?>">
                                <?php if (!empty($errors['notify_webhook_url'])): ?>
                                    <p class="form-error"><?= e($errors['notify_webhook_url']) ?></p>
                                <?php else: ?>
                                    <p class="form-hint">We will POST JSON with event payloads.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-primary">Save Notification Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const generateBtn = document.getElementById('generate-token-btn');
if (generateBtn) {
    generateBtn.addEventListener('click', function() {
        const panel = document.getElementById('create-token-inline');
        if (panel) {
            panel.classList.remove('hidden');
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
}

const cancelCreateBtn = document.getElementById('cancel-create-token-btn');
if (cancelCreateBtn) {
    cancelCreateBtn.addEventListener('click', function() {
        const panel = document.getElementById('create-token-inline');
        if (panel) panel.classList.add('hidden');
    });
}

const copyBtn = document.getElementById('copy-new-token-btn');
if (copyBtn) {
    copyBtn.addEventListener('click', async () => {
        try {
            const el = document.getElementById('new-token');
            const text = el ? el.textContent : '';
            await navigator.clipboard.writeText(text || '');
            if (window.Modal && typeof window.Modal.success === 'function') {
                window.Modal.success('Copied', 'Token copied to clipboard.');
            } else {
                alert('Token copied to clipboard.');
            }
        } catch {
            if (window.Modal && typeof window.Modal.error === 'function') {
                window.Modal.error('Copy failed', 'Could not copy token. Please copy manually.');
            } else {
                alert('Could not copy token. Please copy manually.');
            }
        }
    });
}

(function() {
    function decodeB64Json(b64, fallback) {
        try {
            return JSON.parse(atob(b64 || ''));
        } catch (e) {
            return fallback;
        }
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function setHiddenScopes(container, scopes) {
        if (!container) return;
        container.innerHTML = '';
        scopes.forEach((s) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'scopes[]';
            input.value = s;
            container.appendChild(input);
        });
    }

    function renderScopePreview(previewEl, scopes) {
        if (!previewEl) return;
        const list = Array.isArray(scopes) ? scopes : [];
        previewEl.innerHTML = '';
        const shown = list.slice(0, 6);
        shown.forEach((s) => {
            const badge = document.createElement('span');
            badge.className = 'badge badge-neutral';
            badge.textContent = s;
            previewEl.appendChild(badge);
        });
        if (list.length > shown.length) {
            const more = document.createElement('span');
            more.className = 'badge badge-neutral';
            more.textContent = `+${list.length - shown.length}`;
            previewEl.appendChild(more);
        }
    }

    function initScopePicker(wrapper) {
        if (!wrapper) return;

        const available = decodeB64Json(wrapper.dataset.availableScopesB64, []);
        const defaults = decodeB64Json(wrapper.dataset.defaultScopesB64, []);

        const countEl = wrapper.querySelector('[data-scope-count]');
        const previewEl = wrapper.querySelector('[data-scope-preview]');
        const inputsEl = wrapper.querySelector('[data-scope-inputs]');

        let selected = defaults.slice();
        if (!selected.length && available.length) {
            selected = available.slice();
        }

        function sync() {
            if (countEl) countEl.textContent = String(selected.length);
            renderScopePreview(previewEl, selected);
            setHiddenScopes(inputsEl, selected);
        }

        sync();

        const clearBtn = wrapper.querySelector('[data-scope-clear]');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                selected = [];
                sync();
            });
        }

        const openBtn = wrapper.parentElement?.querySelector('[data-scope-picker-open]') || wrapper.querySelector('[data-scope-picker-open]');
        if (!openBtn) return;

        openBtn.addEventListener('click', async () => {
            if (!window.Modal || typeof window.Modal.show !== 'function') return;

            let working = new Set(selected);

            const scopesSorted = [...available].map(String).filter(Boolean);
            const byGroup = new Map();
            scopesSorted.forEach((s) => {
                const key = (s.split(':')[0] || 'Other');
                if (!byGroup.has(key)) byGroup.set(key, []);
                byGroup.get(key).push(s);
            });
            const groupOrder = Array.from(byGroup.keys()).sort((a, b) => a.localeCompare(b));

            const html = `
                <div class="flex flex-col gap-3" style="max-height: 70vh; min-height: 0;">
                    <div class="flex items-center gap-2">
                        <input class="input w-full" type="text" placeholder="Search scopes…" data-scope-search>
                        <button type="button" class="btn btn-secondary" data-scope-select-all>Select all</button>
                        <button type="button" class="btn btn-ghost" data-scope-clear-all>Clear</button>
                    </div>
                    <div style="flex: 1 1 auto; min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding-right: 4px;">
                        ${groupOrder.map((g) => `
                            <div class="border border-primary rounded-lg p-3 bg-tertiary" data-scope-group>
                                <div class="text-xs text-tertiary mb-2">${escapeHtml(g)}</div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    ${byGroup.get(g).map((s) => `
                                        <label class="flex items-center gap-2 text-sm" data-scope-item data-scope="${escapeHtml(s)}">
                                            <input type="checkbox" class="checkbox" data-scope-checkbox value="${escapeHtml(s)}">
                                            <span class="min-w-0" style="word-break: break-word;">${escapeHtml(s)}</span>
                                        </label>
                                    `).join('')}
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;

            const promise = window.Modal.show({
                type: 'info',
                title: 'Select scopes',
                html,
                showCancel: true,
                confirmText: 'Done',
                cancelText: 'Cancel',
                closeOnBackdrop: false
            });

            requestAnimationFrame(() => {
                const backdrops = document.querySelectorAll('.modal-backdrop');
                const backdrop = backdrops[backdrops.length - 1];
                if (!backdrop) return;

                const search = backdrop.querySelector('[data-scope-search]');
                const selectAll = backdrop.querySelector('[data-scope-select-all]');
                const clearAll = backdrop.querySelector('[data-scope-clear-all]');
                const items = Array.from(backdrop.querySelectorAll('[data-scope-item]'));
                const checkboxes = Array.from(backdrop.querySelectorAll('[data-scope-checkbox]'));

                function syncCheckboxes() {
                    checkboxes.forEach((cb) => {
                        cb.checked = working.has(cb.value);
                    });
                }
                syncCheckboxes();

                items.forEach((row) => {
                    row.addEventListener('click', (e) => {
                        if (e.target && e.target.matches('input')) return;
                        const cb = row.querySelector('input[type="checkbox"]');
                        if (!cb) return;
                        cb.checked = !cb.checked;
                        cb.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                });

                checkboxes.forEach((cb) => {
                    cb.addEventListener('change', () => {
                        if (cb.checked) working.add(cb.value);
                        else working.delete(cb.value);
                        syncCheckboxes();
                    });
                });

                if (selectAll) {
                    selectAll.addEventListener('click', () => {
                        scopesSorted.forEach((s) => working.add(s));
                        syncCheckboxes();
                    });
                }
                if (clearAll) {
                    clearAll.addEventListener('click', () => {
                        working = new Set();
                        syncCheckboxes();
                    });
                }

                if (search) {
                    search.addEventListener('input', () => {
                        const q = String(search.value || '').trim().toLowerCase();
                        items.forEach((row) => {
                            const scope = String(row.dataset.scope || '').toLowerCase();
                            row.classList.toggle('hidden', q !== '' && !scope.includes(q));
                        });
                    });
                }
            });

            const res = await promise;
            if (!res || !res.confirmed) return;
            selected = Array.from(working);
            const ordering = new Map(scopesSorted.map((s, i) => [s, i]));
            selected.sort((a, b) => (ordering.get(a) ?? 9999) - (ordering.get(b) ?? 9999));
            sync();
        });
    }

    document.querySelectorAll('[data-scope-picker]')
        .forEach(initScopePicker);

    document.querySelectorAll('[data-token-scopes-b64]')
        .forEach((btn) => {
            btn.addEventListener('click', () => {
                if (!window.Modal || typeof window.Modal.show !== 'function') return;
                const scopes = decodeB64Json(btn.dataset.tokenScopesB64, []);
                const name = btn.dataset.tokenName || 'Token';
                const html = `
                    <div style="max-height: 70vh; overflow-y: auto; overscroll-behavior: contain; padding-right: 4px;">
                        <div class="flex flex-wrap gap-2">
                            ${(scopes || []).map((s) => `<span class="badge badge-neutral">${escapeHtml(s)}</span>`).join('') || '<span class="text-secondary text-sm">No scopes</span>'}
                        </div>
                    </div>
                `;
                window.Modal.show({
                    type: 'info',
                    title: `Scopes for ${name}`,
                    html,
                    confirmText: 'Close'
                });
            });
        });

    document.querySelectorAll('form[data-revoke-token-form]')
        .forEach((form) => {
            form.addEventListener('submit', async (e) => {
                if (form.dataset.submitting === '1') return;
                e.preventDefault();

                const tokenName = form.dataset.tokenName || 'this token';
                const fallback = () => {
                    if (confirm('Revoke this token?')) {
                        form.dataset.submitting = '1';
                        form.submit();
                    }
                };

                if (!window.Modal || typeof window.Modal.show !== 'function') {
                    fallback();
                    return;
                }

                const res = await window.Modal.show({
                    type: 'danger',
                    title: 'Revoke token',
                    message: `Revoke ${tokenName}? This will immediately disable it.`,
                    showCancel: true,
                    confirmText: 'Revoke',
                    cancelText: 'Cancel',
                    closeOnBackdrop: false
                });

                if (res && res.confirmed) {
                    form.dataset.submitting = '1';
                    form.submit();
                }
            });
        });
})();
</script>
