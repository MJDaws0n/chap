<?php
/**
 * Projects Index View
 * Updated to use new design system
 */
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-header-title">Projects</h1>
                <p class="page-header-description">Organize your applications into projects</p>
            </div>
            <div class="page-header-actions">
                <a href="/projects/create" class="btn btn-primary">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    New Project
                </a>
            </div>
        </div>
    </div>

    <?php if (empty($projects)): ?>
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 100%;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                        </svg>
                    </div>
                    <p class="empty-state-title">No projects yet</p>
                    <p class="empty-state-description">Create your first project to start deploying applications</p>
                    <a href="/projects/create" class="btn btn-primary">Create Project</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($projects as $project): ?>
                <a href="/projects/<?= e($project->uuid) ?>" class="card card-clickable">
                    <div class="card-body">
                        <div class="flex items-start justify-between gap-4 mb-4">
                            <div class="icon-box icon-box-blue">
                                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                                </svg>
                            </div>
                            <div class="flex items-center gap-2">
                                <?php if (!empty($adminViewAll)): ?>
                                    <?php $team = $project->team(); ?>
                                    <?php if ($team): ?>
                                        <span class="badge badge-neutral"><?= e($team->name) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <span class="badge badge-info"><?= count($project->environments()) ?> environments</span>
                            </div>
                        </div>

                        <div class="min-w-0">
                            <h3 class="text-lg font-semibold truncate"><?= e($project->name) ?></h3>
                            <?php if (!empty($project->description)): ?>
                                <p class="text-sm text-secondary line-clamp-2 mt-2"><?= e($project->description) ?></p>
                            <?php else: ?>
                                <p class="text-sm text-tertiary mt-2">No description</p>
                            <?php endif; ?>
                        </div>

                        <div class="mt-6 pt-4 border-t">
                            <p class="text-sm text-tertiary">Created <?= time_ago($project->created_at) ?></p>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
