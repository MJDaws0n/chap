<?php
/**
 * Teams Index View
 */
?>

<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-header-title">Teams</h1>
                <p class="page-header-description">Manage your teams and members</p>
            </div>
            <div class="page-header-actions">
                <a href="/teams/create" class="btn btn-primary">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    New Team
                </a>
            </div>
        </div>
    </div>

    <?php if (empty($teams)): ?>
        <div class="card">
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 100%;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </div>
                    <p class="empty-state-title">No teams yet</p>
                    <p class="empty-state-description">Create a team to collaborate and manage projects</p>
                    <a href="/teams/create" class="btn btn-primary">Create Team</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($teams as $team): ?>
                <div class="card">
                    <div class="card-body">
                        <div class="flex items-start justify-between gap-4 mb-4">
                            <div class="icon-box icon-box-blue">
                                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                                </svg>
                            </div>
                            <?php if (!empty($currentTeam) && (int)$currentTeam['id'] === (int)$team->id): ?>
                                <span class="badge badge-success">Current</span>
                            <?php else: ?>
                                <span class="badge badge-neutral"><?= e($team->role ?? 'member') ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="min-w-0">
                            <h3 class="text-lg font-semibold truncate"><?= e($team->name) ?></h3>
                            <?php if (!empty($team->description)): ?>
                                <p class="text-sm text-secondary line-clamp-2 mt-2"><?= e($team->description) ?></p>
                            <?php else: ?>
                                <p class="text-sm text-tertiary mt-2">No description</p>
                            <?php endif; ?>
                        </div>

                        <div class="mt-6 pt-4 border-t flex items-center justify-between gap-3">
                            <a href="/teams/<?= (int)$team->id ?>" class="btn btn-secondary">Open</a>

                            <?php if (empty($currentTeam) || (int)$currentTeam['id'] !== (int)$team->id): ?>
                                <form action="/teams/<?= (int)$team->id ?>/switch" method="POST">
                                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                                    <button type="submit" class="btn btn-ghost">Switch</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
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
