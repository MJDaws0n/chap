<?php
/**
 * Application Volumes View
 * Persistent volume manager (WebSocket-only, browser connects to node).
 */
$wsUrl = $browserWebsocketUrl ?? '';
?>

<div class="flex flex-col gap-6" id="volumes-manager"
     data-ws-url="<?= e($wsUrl) ?>"
     data-session-id="<?= e($sessionId ?? '') ?>"
     data-application-uuid="<?= e($application->uuid) ?>">

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
                    <span class="breadcrumb-current">Volumes</span>
                </nav>

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-lg icon-box-blue">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="page-header-title">Volumes</h1>
                        <p class="page-header-description truncate">Download, replace, or delete persistent volumes attached to this application.</p>
                    </div>
                </div>
            </div>

            <div class="page-header-actions flex-wrap">
                <a href="/applications/<?= e($application->uuid) ?>" class="btn btn-secondary">Back</a>
            </div>
        </div>
    </div>

    <?php if (empty($wsUrl)): ?>
        <div class="card">
            <div class="card-body">
                <p class="text-danger">This application’s node does not have a Browser WebSocket URL configured.</p>
                <p class="text-secondary">Set <code>logs_websocket_url</code> for the node to enable WebSocket features.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-header">
                <div class="flex items-center justify-between gap-4 flex-wrap">
                    <div class="flex items-center gap-3 flex-wrap">
                        <span class="badge badge-default" id="vm-status">Connecting…</span>
                        <span class="text-sm text-secondary" id="vm-summary"></span>
                        <span class="text-sm text-secondary" id="vm-progress"></span>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <input type="file" id="vm-replace-input" class="hidden" accept=".tar.gz,.tgz,application/gzip,application/x-gzip">
                        <button type="button" class="btn btn-secondary btn-sm" id="vm-refresh">Refresh</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="vm-download" disabled>Download</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="vm-replace" disabled>Replace</button>
                        <button type="button" class="btn btn-danger btn-sm" id="vm-delete" disabled>Delete</button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="vm-table-wrap">
                    <table class="table" id="vm-table">
                        <thead>
                        <tr>
                            <th style="width: 56px;"><input type="checkbox" class="vm-checkbox" id="vm-select-all" aria-label="Select all"></th>
                            <th>Name</th>
                            <th style="width: 120px;">Type</th>
                            <th>Mounted At</th>
                            <th style="width: 220px;">Used By</th>
                            <th style="width: 120px;">Actions</th>
                        </tr>
                        </thead>
                        <tbody id="vm-rows"></tbody>
                    </table>
                </div>
                <div class="text-sm text-tertiary mt-2">
                    Backups are <code>.tar.gz</code>. Replace will wipe the volume then restore the archive.
                </div>
            </div>
        </div>

        <?php $vmVer = @filemtime(__DIR__ . '/../../../public/js/volumesManager.js') ?: time(); ?>
        <script src="/js/volumesManager.js?v=<?= e((string) $vmVer) ?>"></script>

        <style>
            .vm-table-wrap { overflow-x: auto; }
            .vm-row.selected { background: var(--bg-secondary); }
            .vm-row:hover { background: var(--bg-secondary); }
            .vm-checkbox {
                appearance: none;
                -webkit-appearance: none;
                width: 16px;
                height: 16px;
                border-radius: var(--radius-sm);
                border: 1px solid var(--border-primary);
                background: var(--bg-secondary);
                display: inline-grid;
                place-content: center;
                cursor: pointer;
            }
            .vm-checkbox:focus-visible { outline: 2px solid var(--accent-blue); outline-offset: 2px; }
            .vm-checkbox:checked { background: var(--accent-blue); border-color: var(--accent-blue); }
            .vm-checkbox:checked::after {
                content: '';
                width: 9px;
                height: 5px;
                border: 2px solid rgba(255, 255, 255, 0.95);
                border-top: 0;
                border-right: 0;
                transform: rotate(-45deg);
                margin-top: -1px;
            }
        </style>
    <?php endif; ?>
</div>
