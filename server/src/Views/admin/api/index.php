<?php
/**
 * Admin API view
 */
?>

<div class="page-header">
    <div class="page-header-top">
        <div>
            <h1 class="page-header-title">Platform API</h1>
            <p class="page-header-description">Generate platform API keys for automation and access the Platform API reference.</p>
        </div>
        <div class="flex items-center gap-2">
            <a class="btn btn-secondary" href="/docs/client-api.html" target="_blank" rel="noreferrer">Client API Docs</a>
            <a class="btn btn-secondary" href="/docs/admin-api.html" target="_blank" rel="noreferrer">Platform API Docs</a>
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
                            <div class="text-xs text-tertiary">Token ID</div>
                            <code class="break-all"><?= e((string)$newApiToken['token_id']) ?></code>
                            <div class="text-xs text-tertiary mt-2">Token</div>
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
                            <label class="form-label">Expires at (optional)</label>
                            <input class="input" name="expires_at" placeholder="2026-06-01T00:00:00Z">
                            <p class="form-hint">RFC3339 timestamp. Leave blank for no expiry.</p>
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
                            <label class="form-label">Scopes</label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-64 overflow-auto border border-primary rounded-lg p-3 bg-secondary">
                                <?php foreach (($availableScopes ?? []) as $s): ?>
                                    <label class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" name="scopes[]" value="<?= e($s) ?>" class="checkbox" <?= $s === '*' ? '' : 'checked' ?>>
                                        <span><?= e($s) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="form-hint">Include <code>deployments:write</code> for cancel/rollback and <code>templates:deploy</code> to deploy from templates.</p>
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
                        <div class="overflow-x-auto">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Scopes</th>
                                        <th>Expires</th>
                                        <th>Last used</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($apiTokens as $t): ?>
                                        <tr>
                                            <td>
                                                <div class="font-medium"><?= e((string)$t['name']) ?></div>
                                                <div class="text-xs text-tertiary"><code><?= e((string)$t['token_id']) ?></code></div>
                                            </td>
                                            <td class="text-xs text-secondary"><?= e(implode(', ', (array)($t['scopes'] ?? []))) ?></td>
                                            <td class="text-xs text-secondary"><?= !empty($t['expires_at']) ? e((string)$t['expires_at']) : 'Never' ?></td>
                                            <td class="text-xs text-secondary"><?= !empty($t['last_used_at']) ? e((string)$t['last_used_at']) : '—' ?></td>
                                            <td class="text-right">
                                                <form method="POST" action="/admin/api-tokens/<?= e((string)$t['token_id']) ?>/revoke" onsubmit="return confirm('Revoke this token?');">
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
                    <p class="text-secondary text-sm">Common gotchas when creating tokens.</p>
                </div>
            </div>
            <div class="card-body text-sm text-secondary">
                <ul class="list-disc pl-5">
                    <li>Platform API keys are not user-attached; they're for automation/integrations.</li>
                    <li>Some client-plane actions require scopes (e.g. <code>deployments:write</code>, <code>templates:deploy</code>).</li>
                    <li>Team constraints can block cross-team access; use platform-wide tokens for admin inventory.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
  (function() {
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
