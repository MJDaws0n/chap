<?php
/**
 * Application Volume Files View
 * Volume filesystem browser (WebSocket-only, browser connects to node).
 */
$wsUrl = $browserWebsocketUrl ?? '';
?>

<div class="flex flex-col gap-6" id="volume-file-manager"
     data-ws-url="<?= e($wsUrl) ?>"
     data-session-id="<?= e($sessionId ?? '') ?>"
     data-application-uuid="<?= e($application->uuid) ?>"
     data-volume-name="<?= e($volumeName ?? '') ?>">

    <div class="page-header">
        <div class="page-header-top">
            <div>
                <nav class="breadcrumb">
                    <span class="breadcrumb-item"><a href="/projects">Projects</a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-item"><a href="/projects/<?= e($project->uuid) ?>"><?= e($project->name) ?></a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-item"><a href="/environments/<?= e($environment->uuid) ?>"><?= e($environment->name) ?></a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-item"><a href="/applications/<?= e($application->uuid) ?>"><?= e($application->name) ?></a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-item"><a href="/applications/<?= e($application->uuid) ?>/volumes">Volumes</a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current">Filesystem</span>
                </nav>

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-lg icon-box-blue">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="page-header-title">Volume filesystem</h1>
                        <p class="page-header-description truncate">Browse and edit files inside <code><?= e($volumeName ?? '') ?></code> (no running container required).</p>
                    </div>
                </div>
            </div>

            <div class="page-header-actions flex-wrap">
                <a href="/applications/<?= e($application->uuid) ?>/volumes" class="btn btn-secondary">Back</a>
            </div>
        </div>
    </div>

    <?php if (empty($wsUrl)): ?>
        <div class="card">
            <div class="card-body">
                <p class="text-danger">This application’s node does not have a Browser WebSocket URL configured.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-header">
                <div class="flex items-center justify-between gap-4 flex-wrap">
                    <div class="flex items-center gap-3 flex-wrap">
                        <span class="badge badge-default" id="vfm-status">Connecting…</span>
                        <span class="text-sm text-secondary" id="vfm-path-label"></span>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <button type="button" class="btn btn-secondary btn-sm" id="vfm-up">Up</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="vfm-refresh">Refresh</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="vfm-new-file">New file</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="vfm-new-folder">New folder</button>
                    </div>
                </div>
                <div class="flex items-center gap-2 mt-3">
                    <label class="text-sm text-secondary" for="vfm-path">Path</label>
                    <input class="input input-sm" id="vfm-path" value="/" style="min-width: 220px;" />
                    <button type="button" class="btn btn-secondary btn-sm" id="vfm-go">Go</button>
                </div>
            </div>
            <div class="card-body">
                <div style="overflow-x:auto;">
                    <table class="table" id="vfm-table">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th style="width: 140px;" class="text-right">Size</th>
                            <th style="width: 220px;">Modified</th>
                            <th style="width: 160px;">Actions</th>
                        </tr>
                        </thead>
                        <tbody id="vfm-rows"></tbody>
                    </table>
                </div>
                <div class="text-sm text-tertiary mt-2" id="vfm-empty" style="display:none;">No files found.</div>
            </div>
        </div>

        <?php $vfmVer = @filemtime(__DIR__ . '/../../../public/js/volumeFileManager.js') ?: time(); ?>
        <script src="/js/volumeFileManager.js?v=<?= e((string) $vfmVer) ?>"></script>
    <?php endif; ?>
</div>
