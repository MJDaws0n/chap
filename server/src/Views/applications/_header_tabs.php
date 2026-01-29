<?php
/**
 * Application Header + Tabs partial
 *
 * Expected variables:
 * - $application, $project, $environment
 * - $activeTab (string)
 * - $latestDeployment (optional)
 */

$activeTab = $activeTab ?? 'logs';

$statusColors = [
    'running' => 'badge-success',
    'restarting' => 'badge-warning',
    'stopped' => 'badge-neutral',
    'building' => 'badge-warning',
    'deploying' => 'badge-info',
    'failed' => 'badge-danger',
];
$statusColor = $statusColors[$application->status] ?? 'badge-default';

$isDeploying = method_exists($application, 'isDeploying')
    ? $application->isDeploying()
    : (($application->status ?? null) === 'deploying');

$latestDeployment = $latestDeployment ?? null;
$latestDeploymentUuid = $latestDeployment ? (string)($latestDeployment->uuid ?? '') : '';
$latestDeploymentStatus = $latestDeployment ? (string)($latestDeployment->status ?? '') : '';
$canCancel = $latestDeployment && $latestDeploymentUuid !== '' && method_exists($latestDeployment, 'canBeCancelled')
    ? (bool)$latestDeployment->canBeCancelled()
    : in_array($latestDeploymentStatus, ['queued', 'building', 'deploying', 'running'], true);

$deployLabel = $isDeploying
    ? 'Deploying…'
    : (in_array(($application->status ?? ''), ['running', 'restarting'], true) ? 'Redeploy' : 'Deploy');

$showCancel = $isDeploying && $canCancel;
$showStop = !$showCancel && in_array(($application->status ?? ''), ['running', 'restarting'], true);

$logsHref = '/applications/' . e($application->uuid) . '/logs';
$usageHref = '/applications/' . e($application->uuid) . '/usage';
$volumesHref = '/applications/' . e($application->uuid) . '/volumes';
$filesHref = '/applications/' . e($application->uuid) . '/files';
$deployHref = '/applications/' . e($application->uuid) . '?tab=deploy';
$configHref = '/applications/' . e($application->uuid) . '?tab=config';
?>

<div class="page-header">
    <div class="page-header-top">
        <div class="min-w-0">
            <nav class="breadcrumb">
                <span class="breadcrumb-item"><a href="/projects">Projects</a></span>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-item"><a href="/projects/<?= e($project->uuid) ?>"><?= e($project->name) ?></a></span>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-item"><a href="/environments/<?= e($environment->uuid) ?>"><?= e($environment->name) ?></a></span>
                <span class="breadcrumb-separator">/</span>
                <span class="breadcrumb-current"><?= e($application->name) ?></span>
            </nav>

            <div class="flex items-center gap-4 mt-4 min-w-0">
                <div class="icon-box icon-box-lg icon-box-blue flex-shrink-0">
                    <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>

                <div class="min-w-0">
                    <div class="flex items-center flex-wrap gap-3">
                        <h1 class="page-header-title"><?= e($application->name) ?></h1>
                        <span class="badge <?= e($statusColor) ?>"><?= e(ucfirst((string)$application->status)) ?></span>
                    </div>
                    <?php if (!empty($application->description)): ?>
                        <p class="page-header-description line-clamp-2" title="<?= e($application->description) ?>"><?= e($application->description) ?></p>
                    <?php else: ?>
                        <p class="page-header-description">No description</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="page-header-actions flex-wrap" id="app-actions" data-app-actions data-latest-deployment-uuid="<?= e($latestDeploymentUuid) ?>">
            <form method="POST" action="/applications/<?= e($application->uuid) ?>/deploy" class="inline-block" data-deploy-form data-inline-deploy>
                <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                <button type="submit" class="btn btn-primary" <?= $isDeploying ? 'disabled aria-disabled="true"' : '' ?>>
                    <?= e($deployLabel) ?>
                </button>
            </form>

            <?php if ($showCancel): ?>
                <form method="POST" action="/deployments/<?= e($latestDeploymentUuid) ?>/cancel" class="inline-block" data-cancel-deploy-form>
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" class="btn btn-secondary">Cancel</button>
                </form>
            <?php elseif ($showStop): ?>
                <form method="POST" action="/applications/<?= e($application->uuid) ?>/stop" class="inline-block" data-stop-form>
                    <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <button type="submit" class="btn btn-secondary">Stop</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-4 overflow-x-auto">
        <div class="tabs tabs-scroll app-tabs" role="tablist" aria-label="Application navigation">
            <a class="tab <?= $activeTab === 'logs' ? 'active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'logs' ? 'true' : 'false' ?>" href="<?= e($logsHref) ?>">Live Logs</a>
            <a class="tab <?= $activeTab === 'volume-files' ? 'active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'volume-files' ? 'true' : 'false' ?>" href="<?= e($volumesHref) ?>">Volume Files</a>
            <a class="tab <?= $activeTab === 'container-filesystem' ? 'active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'container-filesystem' ? 'true' : 'false' ?>" href="<?= e($filesHref) ?>">Container filesystem</a>
            <a class="tab <?= $activeTab === 'usage' ? 'active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'usage' ? 'true' : 'false' ?>" href="<?= e($usageHref) ?>">Usage</a>
            <a class="tab <?= $activeTab === 'deploy' ? 'active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'deploy' ? 'true' : 'false' ?>" href="<?= e($deployHref) ?>">Deploy</a>
            <a class="tab <?= $activeTab === 'config' ? 'active' : '' ?>" role="tab" aria-selected="<?= $activeTab === 'config' ? 'true' : 'false' ?>" href="<?= e($configHref) ?>">Configuration</a>
        </div>
    </div>
</div>
