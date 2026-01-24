<?php
/**
 * Git Sources Index View
 * Manage Git connections for the current team
 */
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-header-title">Git Sources</h1>
                <p class="page-header-description">Manage GitHub Apps and other authentication methods</p>
            </div>
            <div class="page-header-actions">
                <?php if (($tab ?? 'github-apps') === 'github-apps'): ?>
                    <a href="/git-sources/github-apps/manifest/create" class="btn btn-secondary">Auto-create GitHub App</a>
                    <a href="/git-sources/github-apps/create" class="btn btn-primary">Add GitHub App</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="tabs">
        <a class="tab <?= ($tab ?? 'github-apps') === 'github-apps' ? 'active' : '' ?>" href="/git-sources?tab=github-apps">GitHub Apps</a>
        <a class="tab <?= ($tab ?? '') === 'oauth' ? 'active' : '' ?>" href="/git-sources?tab=oauth">OAuth</a>
        <a class="tab <?= ($tab ?? '') === 'deploy-keys' ? 'active' : '' ?>" href="/git-sources?tab=deploy-keys">Deploy Keys</a>
    </div>

    <?php if (($tab ?? 'github-apps') === 'github-apps'): ?>
        <div class="card">

            <?php if (empty($githubApps)): ?>
                <div class="card-body">
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 18a4 4 0 00-8 0m8 0a4 4 0 01-8 0m8 0v-5a4 4 0 00-8 0v5m4-11V3m0 4h.01"/>
                            </svg>
                        </div>
                        <p class="empty-state-title">No GitHub Apps added</p>
                        <p class="empty-state-description">Add one so Chap can access private repositories.</p>
                        <div class="flex items-center gap-3">
                            <a href="/git-sources/github-apps/manifest/create" class="btn btn-secondary btn-sm">Auto-create</a>
                            <a href="/git-sources/github-apps/create" class="btn btn-primary btn-sm">Add manually</a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>App ID</th>
                                <th>Installation ID</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($githubApps as $app): ?>
                                <tr>
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <div class="icon-box icon-box-blue icon-box-sm">
                                                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 18a4 4 0 00-8 0m8 0a4 4 0 01-8 0m8 0v-5a4 4 0 00-8 0v5m4-11V3m0 4h.01"/>
                                                </svg>
                                            </div>
                                            <span class="font-medium"><?= e($app->name) ?></span>
                                        </div>
                                    </td>
                                    <td><code><?= e((string)($app->github_app_id ?? '')) ?></code></td>
                                    <td>
                                        <?php if (!empty($app->github_app_installation_id)): ?>
                                            <code><?= e((string)($app->github_app_installation_id ?? '')) ?></code>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Not installed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <?php if (empty($app->github_app_installation_id)): ?>
                                            <a href="/git-sources/github-apps/<?= e($app->uuid ?? (string)$app->id) ?>/installations" class="btn btn-secondary-ghost">Finish setup</a>
                                        <?php endif; ?>
                                        <form method="POST" action="/git-sources/github-apps/<?= e($app->uuid ?? (string)$app->id) ?>" class="inline-block">
                                            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="_method" value="DELETE">
                                            <button type="submit" class="btn btn-danger-ghost">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif (($tab ?? '') === 'oauth'): ?>
        <div class="card">
            <div class="card-body">
                <p class="text-secondary">OAuth connections are not available yet.</p>
            </div>
        </div>

    <?php else: ?>
        <div class="card">
            <div class="card-body">
                <p class="text-secondary">Deploy keys are not available yet.</p>
            </div>
        </div>
    <?php endif; ?>
</div>
