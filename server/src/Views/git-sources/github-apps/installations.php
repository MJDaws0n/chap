<?php
/**
 * Finish GitHub App setup by selecting an installation.
 */
$source = $source ?? null;
$installations = $installations ?? [];
$installUrl = $installUrl ?? null;
$appInfo = $appInfo ?? [];
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div>
            <nav class="breadcrumb">
                <a href="/git-sources?tab=github-apps">Git Sources</a>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-current">Finish setup</span>
            </nav>
            <h1 class="page-title">Finish GitHub App Setup</h1>
            <p class="page-header-description">Install the app on GitHub, then select the installation ID to use for this team.</p>
        </div>
        <div class="page-header-actions">
            <a class="btn btn-secondary" href="/git-sources?tab=github-apps">Back</a>
        </div>
    </div>

    <div class="card card-glass">
        <div class="card-body flex flex-col gap-4">
            <div>
                <div class="text-sm text-secondary">App</div>
                <div class="font-medium"><?= e($source?->name ?? 'GitHub App') ?></div>
            </div>

            <?php if (!empty($installUrl)): ?>
                <div class="flex items-center gap-3">
                    <a class="btn btn-primary" href="<?= e($installUrl) ?>" target="_blank" rel="noreferrer">Install on GitHub</a>
                    <a class="btn btn-secondary" href="/git-sources/github-apps/<?= e($source->uuid ?? (string)$source->id) ?>/installations">Refresh</a>
                </div>
            <?php else: ?>
                <div class="flex items-center gap-3">
                    <a class="btn btn-secondary" href="/git-sources/github-apps/<?= e($source->uuid ?? (string)$source->id) ?>/installations">Refresh</a>
                </div>
            <?php endif; ?>

            <?php if (!empty($installations)): ?>
                <form method="POST" action="/git-sources/github-apps/<?= e($source->uuid ?? (string)$source->id) ?>/installations" class="flex flex-col gap-4">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">

                    <div class="form-group">
                        <label class="form-label">Choose installation</label>
                        <div class="flex flex-col gap-2">
                            <?php foreach ($installations as $inst): ?>
                                <?php
                                    $instId = isset($inst['id']) ? (string)$inst['id'] : '';
                                    $account = is_array($inst['account'] ?? null) ? ($inst['account']['login'] ?? '') : '';
                                    $targetType = (string)($inst['target_type'] ?? '');
                                    $label = trim($account . ($targetType ? ' (' . $targetType . ')' : ''));
                                ?>
                                <label class="flex items-center gap-2">
                                    <input type="radio" name="installation_id" value="<?= e($instId) ?>" <?= (!empty($source->github_app_installation_id) && (string)$source->github_app_installation_id === $instId) ? 'checked' : '' ?>>
                                    <span><?= e($label !== '' ? $label : ('Installation #' . $instId)) ?></span>
                                    <code class="ml-auto"><?= e($instId) ?></code>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button class="btn btn-primary" type="submit">Save installation</button>
                        <a class="btn btn-secondary" href="/git-sources?tab=github-apps">Cancel</a>
                    </div>
                </form>
            <?php else: ?>
                <div class="empty-state">
                    <p class="empty-state-title">No installations found yet</p>
                    <p class="empty-state-description">Install the app on GitHub, then hit Refresh.</p>
                </div>

                <div class="card card-glass">
                    <div class="card-body">
                        <h3 class="card-title">Manual installation ID</h3>
                        <p class="text-secondary text-sm">If you already know the installation ID, you can paste it here.</p>

                        <form method="POST" action="/git-sources/github-apps/<?= e($source->uuid ?? (string)$source->id) ?>/installations" class="mt-4 flex flex-col gap-4">
                            <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                            <div class="form-group">
                                <label class="form-label" for="installation_id">Installation ID</label>
                                <input class="input" id="installation_id" name="installation_id" type="text" placeholder="12345678">
                            </div>
                            <button class="btn btn-primary" type="submit">Save installation</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
