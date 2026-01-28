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
                ?>
                <p class="text-secondary text-sm mb-4">
                    API tokens allow you to authenticate with the Chap API. Keep your tokens secure and never share them.
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
                                    <label class="form-label">Expires at (optional)</label>
                                    <input class="input" name="expires_at" placeholder="2026-06-01T00:00:00Z">
                                    <p class="form-hint">RFC3339 timestamp. Leave blank for no expiry.</p>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Scopes</label>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-64 overflow-auto border border-primary rounded-lg p-3 bg-secondary">
                                        <?php foreach ($availableScopes as $s): ?>
                                            <label class="flex items-center gap-2 text-sm">
                                                <input type="checkbox" name="scopes[]" value="<?= e($s) ?>" class="checkbox" checked>
                                                <span><?= e($s) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="form-hint">Scopes define what this token can do.</p>
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
                        <div class="overflow-x-auto">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Scopes</th>
                                        <th>Expires</th>
                                        <th>Last used</th>
                                        <?php if (!empty($canWriteSettings)): ?><th></th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($apiTokens as $t): ?>
                                        <tr>
                                            <td>
                                                <div class="font-medium"><?= e((string)$t['name']) ?></div>
                                                <div class="text-xs text-tertiary"><code><?= e((string)$t['token_id']) ?></code></div>
                                            </td>
                                            <td class="text-xs text-secondary">
                                                <?= e(implode(', ', (array)($t['scopes'] ?? []))) ?>
                                            </td>
                                            <td class="text-xs text-secondary">
                                                <?= !empty($t['expires_at']) ? e((string)$t['expires_at']) : 'Never' ?>
                                            </td>
                                            <td class="text-xs text-secondary">
                                                <?= !empty($t['last_used_at']) ? e((string)$t['last_used_at']) : '—' ?>
                                            </td>
                                            <?php if (!empty($canWriteSettings)): ?>
                                                <td class="text-right">
                                                    <form method="POST" action="/settings/api-tokens/<?= e((string)$t['token_id']) ?>/revoke" onsubmit="return confirm('Revoke this token?');">
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
</script>
