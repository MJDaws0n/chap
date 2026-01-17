<?php
/**
 * Project Show View
 * Updated to use new design system
 */
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <nav class="breadcrumb">
                    <span class="breadcrumb-item">
                        <a href="/projects">Projects</a>
                    </span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current"><?= e($project->name) ?></span>
                </nav>

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-lg icon-box-blue">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="page-header-title"><?= e($project->name) ?></h1>
                        <?php if (!empty($project->description)): ?>
                            <p class="page-header-description line-clamp-2"><?= e($project->description) ?></p>
                        <?php else: ?>
                            <p class="page-header-description">No description</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="page-header-actions">
                <a href="/projects/<?= e($project->uuid) ?>/edit" class="btn btn-secondary">Edit</a>
                <form action="/projects/<?= e($project->uuid) ?>" method="POST" id="delete-project-form">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="button" class="btn btn-danger-ghost" id="delete-project-btn">Delete</button>
                </form>
            </div>
        </div>
    </div>

    <div class="flex items-center justify-between flex-wrap gap-4">
        <h2 class="text-xl font-semibold text-primary">Environments</h2>
        <a href="/projects/<?= e($project->uuid) ?>/environments/create" class="btn btn-primary">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            New Environment
        </a>
    </div>

    <?php if (empty($environments)): ?>
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 100%;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                        </svg>
                    </div>
                    <p class="empty-state-title">No environments yet</p>
                    <p class="empty-state-description">Create your first environment (e.g., Production, Staging, Development)</p>
                    <a href="/projects/<?= e($project->uuid) ?>/environments/create" class="btn btn-primary">Create Environment</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($environments as $environment): ?>
                <?php $apps = $environment->applications(); ?>
                <a href="/environments/<?= e($environment->uuid) ?>" class="card card-clickable">
                    <div class="card-body">
                        <div class="flex items-start justify-between gap-4 mb-4">
                            <div class="icon-box icon-box-md icon-box-green">
                                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                                </svg>
                            </div>
                            <span class="badge badge-neutral"><?= count($apps) ?> app<?= count($apps) !== 1 ? 's' : '' ?></span>
                        </div>

                        <div class="min-w-0">
                            <h3 class="text-lg font-semibold truncate"><?= e($environment->name) ?></h3>
                            <?php if (!empty($environment->description)): ?>
                                <p class="text-sm text-secondary line-clamp-2 mt-2"><?= e($environment->description) ?></p>
                            <?php else: ?>
                                <p class="text-sm text-tertiary mt-2">No description</p>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($apps)): ?>
                            <div class="mt-6 pt-4 border-t">
                                <div class="flex flex-wrap gap-2 items-center">
                                    <?php foreach (array_slice($apps, 0, 3) as $app): ?>
                                        <?php
                                        $statusBadge = [
                                            'running' => 'badge-success',
                                            'restarting' => 'badge-warning',
                                            'stopped' => 'badge-neutral',
                                            'deploying' => 'badge-warning',
                                            'error' => 'badge-danger',
                                        ][$app->status] ?? 'badge-neutral';
                                        ?>
                                        <span class="badge <?= $statusBadge ?> badge-sm"><?= e($app->name) ?></span>
                                    <?php endforeach; ?>
                                    <?php if (count($apps) > 3): ?>
                                        <span class="text-xs text-tertiary">+<?= count($apps) - 3 ?> more</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Project Information</h2>
        </div>
        <div class="card-body">
            <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <dt class="text-sm text-tertiary">UUID</dt>
                    <dd class="mt-1"><code class="code-inline"><?= e($project->uuid) ?></code></dd>
                </div>
                <div>
                    <dt class="text-sm text-tertiary">Created</dt>
                    <dd class="mt-1 text-primary"><?= $project->created_at ? time_ago($project->created_at) : '-' ?></dd>
                </div>
                <div>
                    <dt class="text-sm text-tertiary">Environments</dt>
                    <dd class="mt-1 text-primary"><?= count($environments) ?></dd>
                </div>
                <div>
                    <dt class="text-sm text-tertiary">Total Applications</dt>
                    <dd class="mt-1 text-primary">
                        <?php
                        $totalApps = 0;
                        foreach ($environments as $env) {
                            $totalApps += count($env->applications());
                        }
                        echo $totalApps;
                        ?>
                    </dd>
                </div>
            </dl>
        </div>
    </div>
</div>

<style>
.code-inline {
    font-family: var(--font-mono);
    font-size: var(--text-sm);
    background-color: var(--bg-tertiary);
    padding: var(--space-1) var(--space-2);
    border-radius: var(--radius-sm);
    word-break: break-all;
}

.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>

<script>
document.getElementById('delete-project-btn').addEventListener('click', function() {
    Modal.confirmDelete('Are you sure you want to delete this project? All environments and applications will be deleted.')
        .then(confirmed => {
            if (confirmed) {
                document.getElementById('delete-project-form').submit();
            }
        });
});
</script>
