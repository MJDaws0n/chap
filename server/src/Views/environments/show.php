<?php
/**
 * Environment Show View
 * Updated to use new design system
 */
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <nav class="breadcrumb">
                    <span class="breadcrumb-item">
                        <a href="/projects/<?= e($project->uuid) ?>"><?= e($project->name) ?></a>
                    </span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current"><?= e($environment->name) ?></span>
                </nav>

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-lg icon-box-green">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="page-header-title"><?= e($environment->name) ?></h1>
                        <?php if (!empty($environment->description)): ?>
                            <p class="page-header-description line-clamp-2"><?= e($environment->description) ?></p>
                        <?php else: ?>
                            <p class="page-header-description">No description</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="page-header-actions">
                <a href="/environments/<?= e($environment->uuid) ?>/edit" class="btn btn-secondary">Edit</a>
                <form action="/environments/<?= e($environment->uuid) ?>" method="POST" id="delete-environment-form">
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="button" class="btn btn-danger-ghost" id="delete-environment-btn">Delete</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Applications Section -->
    <section class="flex flex-col gap-4">
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-3 min-w-0">
                <h2 class="text-xl font-semibold text-primary truncate">Applications</h2>
                <span class="badge badge-neutral"><?= count($applications) ?></span>
            </div>
            <a href="/environments/<?= e($environment->uuid) ?>/applications/create" class="btn btn-primary w-full sm:w-auto">+ New Application</a>
        </div>

        <?php if (empty($applications)): ?>
            <div class="card">
                <div class="card-body">
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 100%;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                            </svg>
                        </div>
                        <p class="empty-state-title">No applications yet</p>
                        <p class="empty-state-description">Create your first application to get started</p>
                        <a href="/environments/<?= e($environment->uuid) ?>/applications/create" class="btn btn-primary">Create Application</a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="table-container">
                    <table class="table table-clickable">
                        <thead>
                            <tr>
                                <th>Application</th>
                                <th>Status</th>
                                <th>Branch</th>
                                <th>Last Deployed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($applications as $app): ?>
                                <?php
                                $statusBadge = [
                                    'running' => 'badge-success',
                                    'restarting' => 'badge-warning',
                                    'stopped' => 'badge-neutral',
                                    'deploying' => 'badge-warning',
                                    'error' => 'badge-danger',
                                ][$app->status] ?? 'badge-neutral';
                                ?>
                                <tr onclick="window.location='/applications/<?= e($app->uuid) ?>'">
                                    <td>
                                        <span class="font-medium truncate"><?= e($app->name) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $statusBadge ?>"><?= ucfirst($app->status) ?></span>
                                    </td>
                                    <td>
                                        <code class="code-inline"><?= e($app->git_branch) ?></code>
                                    </td>
                                    <td class="text-secondary">
                                        <?php $latest = $app->latestDeployment(); ?>
                                        <?= $latest ? time_ago($latest->created_at) : 'Never' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </section>
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
document.getElementById('delete-environment-btn').addEventListener('click', function() {
    Modal.confirmDelete('Are you sure you want to delete this environment? All applications will be deleted.')
        .then(confirmed => {
            if (confirmed) {
                document.getElementById('delete-environment-form').submit();
            }
        });
});
</script>
