<?php
/**
 * Settings Index View
 * Updated to use new design system
 */
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
                <p class="text-secondary text-sm mb-4">
                    API tokens allow you to authenticate with the Chap API. Keep your tokens secure and never share them.
                </p>

                <div class="bg-tertiary border border-primary rounded-lg p-4 mb-4">
                    <p class="text-xs text-tertiary mb-2">Your API endpoint</p>
                    <code><?= e(url('/api/v1')) ?></code>
                </div>

                <button type="button" class="btn btn-secondary" id="generate-token-btn">Generate New Token</button>
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
                    <code><?= e(\Chap\Config::SERVER_VERSION) ?></code>
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
                <div class="flex flex-col gap-4">
                    <div class="flex items-center justify-between gap-4">
                        <div class="min-w-0">
                            <p class="font-medium">Deployment Notifications</p>
                            <p class="text-secondary text-sm">Get notified when deployments complete or fail</p>
                        </div>
                        <label class="toggle" aria-label="Deployment Notifications">
                            <input type="checkbox" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="flex items-center justify-between gap-4">
                        <div class="min-w-0">
                            <p class="font-medium">Node Status Alerts</p>
                            <p class="text-secondary text-sm">Get notified when nodes go offline</p>
                        </div>
                        <label class="toggle" aria-label="Node Status Alerts">
                            <input type="checkbox" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('generate-token-btn').addEventListener('click', function() {
    Modal.show({
        title: 'Not Implemented',
        content: 'Token generation is not implemented yet.',
        confirmText: 'OK'
    });
});
</script>
