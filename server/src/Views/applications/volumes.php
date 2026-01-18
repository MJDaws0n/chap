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

    <?php $activeTab = 'volume-files'; ?>
    <?php include __DIR__ . '/_header_tabs.php'; ?>

    <div class="card">
        <div class="card-body">
            <p class="text-secondary text-sm">Manage persistent volumes attached to this application. Tip: open a volume to browse and edit files.</p>
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
                        <button type="button" class="btn btn-ghost btn-sm hidden" id="vm-cancel">Cancel</button>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <input type="file" id="vm-replace-input" class="hidden" accept=".tar.gz,.tgz,application/gzip,application/x-gzip">
                        <button type="button" class="btn btn-secondary btn-sm" id="vm-refresh">Refresh</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="vm-download" disabled>Download</button>
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
                            <th style="width: 96px;">Actions</th>
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
            .vm-row { cursor: pointer; }
            .vm-row:hover { background: var(--bg-secondary); }
            .vm-select-cell { text-align: left; }
            .vm-row-actions .dropdown-menu { min-width: 200px; }
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
