<?php
/**
 * Admin API view
 */

$availableScopes = $availableScopes ?? [];
$availableScopesB64 = base64_encode(json_encode(array_values($availableScopes)));
$defaultScopes = array_values(array_filter($availableScopes, fn($s) => (string)$s !== '*'));
$defaultScopesB64 = base64_encode(json_encode($defaultScopes));
?>

<div class="page-header">
    <div class="page-header-top">
        <div>
            <nav class="breadcrumb">
                <span class="breadcrumb-item"><a href="/admin/api">Platform API</a></span>
            </nav>

            <div class="flex items-center gap-4 mt-4">
                <div class="icon-box icon-box-purple icon-box-lg">
                    <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a4 4 0 00-4 4v1H7a2 2 0 00-2 2v5a2 2 0 002 2h10a2 2 0 002-2v-5a2 2 0 00-2-2h-1v-1a4 4 0 00-4-4z"></path>
                    </svg>
                </div>
                <div class="min-w-0">
                    <h1 class="page-header-title truncate">Platform API</h1>
                    <p class="page-header-description truncate">Generate platform API keys for automation and integrations.</p>
                </div>
            </div>
        </div>
        <div class="page-header-actions">
            <a class="btn btn-secondary" href="https://mjdaws0n.github.io/chap/api/admin-api.html" target="_blank" rel="noreferrer">Platform API Docs</a>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 flex flex-col gap-6">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Platform API Keys</h2>
                    <p class="text-secondary text-sm">Platform keys are not user-attached and can be constrained/scoped for platform-wide automation.</p>
                </div>
            </div>
            <div class="card-body">
                <div class="bg-tertiary border border-primary rounded-lg p-4 mb-4">
                    <p class="text-xs text-tertiary mb-2">Platform API base path</p>
                    <code><?= e(url('/api/v2/platform')) ?></code>
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

                <div class="border border-primary rounded-lg p-4 bg-tertiary">
                    <div class="text-sm font-medium mb-1">Create API token</div>
                    <div class="text-xs text-secondary mb-4">Token will be shown once after creation.</div>

                    <form action="/admin/api-tokens" method="POST" class="flex flex-col gap-4">
                        <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

                        <div class="form-group">
                            <label class="form-label">Name</label>
                            <input class="input" name="name" placeholder="admin-cli" required>
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
                            <label class="form-label">Constraints</label>
                            <select class="select" name="constraint_mode">
                                <option value="none" selected>None (platform-wide)</option>
                                <option value="current_team">Current team only</option>
                            </select>
                            <p class="form-hint">Use "None" for platform-wide automation; use team constraints for safer keys.</p>
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
                                        <th></th>
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
                                            <td class="text-xs text-secondary"><?= !empty($t['created_at']) ? e(time_ago((string)$t['created_at'])) : '—' ?></td>
                                            <td class="text-xs text-secondary"><?= !empty($t['last_used_at']) ? e(time_ago((string)$t['last_used_at'])) : '—' ?></td>
                                            <td class="text-xs text-secondary"><?= !empty($t['expires_at']) ? e(time_ago((string)$t['expires_at'])) : 'Never' ?></td>
                                            <td class="text-right">
                                                <form method="POST" action="/admin/api-tokens/<?= e((string)$t['token_id']) ?>/revoke" data-revoke-token-form data-token-name="<?= e((string)$t['name']) ?>">
                                                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                                                </form>
                                            </td>
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
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Notes</h2>
                    <p class="text-secondary text-sm">Use the official docs for scopes and examples.</p>
                </div>
            </div>
            <div class="card-body text-sm text-secondary">
                <p class="m-0">
                    Documentation: <a class="link break-all" href="https://mjdaws0n.github.io/chap/api/admin-api.html" target="_blank" rel="noreferrer">https://mjdaws0n.github.io/chap/api/admin-api.html</a>
                </p>
            </div>
        </div>
    </div>
</div>

<script>
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
                selected = available.filter((s) => s && s !== '*');
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

                // Keep selection outside modal DOM so we can read it after close.
                let working = new Set(selected);

                const scopesSorted = [...available].map(String).filter(Boolean);
                const hasStar = scopesSorted.includes('*');

                const byGroup = new Map();
                scopesSorted.forEach((s) => {
                    const key = (s === '*') ? 'All' : (s.split(':')[0] || 'Other');
                    if (!byGroup.has(key)) byGroup.set(key, []);
                    byGroup.get(key).push(s);
                });

                const groupOrder = Array.from(byGroup.keys()).sort((a, b) => {
                    if (a === 'All') return -1;
                    if (b === 'All') return 1;
                    return a.localeCompare(b);
                });

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
                        <p class="text-xs text-secondary m-0">Tip: Selecting <code>*</code> grants full access.</p>
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

                    function applyStarRules() {
                        const starSelected = working.has('*');
                        if (!hasStar) return;
                        checkboxes.forEach((cb) => {
                            const v = cb.value;
                            if (v === '*') {
                                cb.disabled = false;
                                return;
                            }
                            cb.disabled = starSelected;
                        });
                    }

                    function syncCheckboxes() {
                        checkboxes.forEach((cb) => {
                            cb.checked = working.has(cb.value);
                        });
                        applyStarRules();
                    }

                    syncCheckboxes();

                    items.forEach((row) => {
                        row.addEventListener('click', (e) => {
                            if (e.target && e.target.matches('input')) return;
                            const cb = row.querySelector('input[type="checkbox"]');
                            if (!cb || cb.disabled) return;
                            cb.checked = !cb.checked;
                            cb.dispatchEvent(new Event('change', { bubbles: true }));
                        });
                    });

                    checkboxes.forEach((cb) => {
                        cb.addEventListener('change', () => {
                            const v = cb.value;
                            if (cb.checked) {
                                if (v === '*') {
                                    working = new Set(['*']);
                                } else {
                                    working.delete('*');
                                    working.add(v);
                                }
                            } else {
                                working.delete(v);
                            }
                            syncCheckboxes();
                        });
                    });

                    if (selectAll) {
                        selectAll.addEventListener('click', () => {
                            if (hasStar) {
                                working.delete('*');
                            }
                            scopesSorted.forEach((s) => {
                                if (s === '*') return;
                                working.add(s);
                            });
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
                // If not using '*', keep stable ordering matching available list.
                if (!selected.includes('*')) {
                    const ordering = new Map(scopesSorted.map((s, i) => [s, i]));
                    selected.sort((a, b) => (ordering.get(a) ?? 9999) - (ordering.get(b) ?? 9999));
                }
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
                    // Avoid double-submit if user confirms multiple times.
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

    const btn = document.getElementById('copy-new-token-btn');
    const tokenEl = document.getElementById('new-token');
    if (!btn || !tokenEl) return;

    btn.addEventListener('click', async () => {
      const text = tokenEl.textContent.trim();
      try {
        await navigator.clipboard.writeText(text);
        btn.textContent = 'Copied';
        setTimeout(() => (btn.textContent = 'Copy token'), 1200);
      } catch (e) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        btn.textContent = 'Copied';
        setTimeout(() => (btn.textContent = 'Copy token'), 1200);
      }
    });
  })();
</script>
