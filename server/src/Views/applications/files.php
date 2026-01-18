<?php
/**
 * Application Files View
 * Container file manager (WebSocket-only, browser connects to node).
 */
$wsUrl = $browserWebsocketUrl ?? '';
$applicationUuid = (isset($application) && is_object($application) && isset($application->uuid))
    ? (string) $application->uuid
    : (string) ($application_uuid ?? '');
?>

<div class="flex flex-col gap-6" id="file-manager"
     data-ws-url="<?= e($wsUrl) ?>"
     data-session-id="<?= e($sessionId ?? '') ?>"
    data-application-uuid="<?= e($applicationUuid) ?>">

    <?php $activeTab = 'container-filesystem'; ?>
    <?php include __DIR__ . '/_header_tabs.php'; ?>

    <div class="alert alert-warning">
        <svg class="alert-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <span>
            <strong>Advanced use cases only.</strong> This edits the <em>running container</em> filesystem and changes may be lost on redeploy.
            Suggested: use <a href="/applications/<?= e($applicationUuid) ?>/volumes" class="link">Volume Files</a> instead.
        </span>
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
                        <!-- Container Dropdown (like Live Logs) -->
                        <div class="dropdown" id="fm-container-dropdown">
                            <button type="button" class="btn btn-secondary" id="fm-container-select-btn" data-dropdown-trigger="fm-container-dropdown-menu" data-dropdown-placement="bottom-start">
                                <span id="fm-selected-container-name">Select container...</span>
                                <svg class="icon dropdown-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div class="dropdown-menu" id="fm-container-dropdown-menu" style="min-width: 320px;">
                                <div class="p-3 border-b border-primary">
                                    <input type="text" class="input input-sm" placeholder="Search containers..." id="fm-container-search" autocomplete="off">
                                </div>
                                <div class="dropdown-items" id="fm-container-list">
                                    <div class="dropdown-empty">No containers available</div>
                                </div>
                            </div>
                        </div>

                        <!-- Connection Status -->
                        <div class="flex items-center gap-2 text-sm text-secondary" id="fm-connection-status">
                            <span class="badge badge-default" id="fm-status">Connecting…</span>
                        </div>

                        <div class="flex items-center gap-2 text-sm text-secondary" id="fm-transfer-wrap">
                            <span id="fm-transfer" class="text-sm text-secondary"></span>
                            <button type="button" class="btn btn-ghost btn-sm hidden" id="fm-transfer-cancel">Cancel</button>
                        </div>

                        <span class="text-sm text-secondary" id="fm-root"></span>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <input type="file" id="fm-upload-input" class="hidden" multiple>
                        <button type="button" class="btn btn-secondary btn-sm fm-icon-btn" id="fm-upload-btn" title="Upload" aria-label="Upload">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 9l5-5 5 5"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 20H4"/>
                            </svg>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm fm-icon-btn" id="fm-new-folder" title="New folder" aria-label="New folder">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 012-2h5l2 2h7a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 11v6m-3-3h6"/>
                            </svg>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm fm-icon-btn" id="fm-new-file" title="New file" aria-label="New file">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 3h7l5 5v13a2 2 0 01-2 2H7a2 2 0 01-2-2V5a2 2 0 012-2z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 3v6h6"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 11v6m-3-3h6"/>
                            </svg>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm fm-icon-btn" id="fm-rename" title="Rename" aria-label="Rename">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 4h10"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 8h7"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 20h4l10-10-4-4L4 16v4z"/>
                            </svg>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm fm-icon-btn" id="fm-move" title="Move" aria-label="Move">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h13"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 7l5 5-5 5"/>
                            </svg>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm fm-icon-btn" id="fm-copy" title="Copy" aria-label="Copy">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 8h12v12H8z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16V4h12"/>
                            </svg>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm fm-icon-btn" id="fm-download" title="Download" aria-label="Download">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v10"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 10l5 5 5-5"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 20H4"/>
                            </svg>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm fm-icon-btn" id="fm-archive" title="Archive" aria-label="Archive">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 8v13a2 2 0 01-2 2H5a2 2 0 01-2-2V8"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l2-5h14l2 5H3z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10 12h4"/>
                            </svg>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm fm-icon-btn" id="fm-unarchive" title="Unarchive" aria-label="Unarchive">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 8v13a2 2 0 01-2 2H5a2 2 0 01-2-2V8"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l2-5h14l2 5H3z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 12v6"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 15l3-3 3 3"/>
                            </svg>
                        </button>
                        <button type="button" class="btn btn-danger btn-sm fm-icon-btn" id="fm-delete" title="Delete" aria-label="Delete">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 6V4h8v2"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 6l-1 16H6L5 6"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10 11v6"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 11v6"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div class="flex items-center gap-2 flex-wrap" id="fm-breadcrumb"></div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <button type="button" class="btn btn-secondary btn-sm" id="fm-manual-location">Manual Location</button>
                        <button type="button" class="btn btn-ghost btn-sm" id="fm-refresh">Refresh</button>
                    </div>
                </div>

                <div class="mt-4 fm-dropzone" id="fm-dropzone">
                    <div class="fm-drop-overlay" aria-hidden="true">
                        <div class="fm-drop-overlay-inner">
                            <div class="fm-drop-title">Drop files to upload</div>
                            <div class="fm-drop-sub">Folders aren’t supported yet</div>
                        </div>
                    </div>

                    <div class="fm-table-wrap">
                        <table class="table" id="fm-table">
                            <thead>
                            <tr>
                                <th style="width: 56px;">
                                    <input type="checkbox" class="fm-checkbox" id="fm-select-all" aria-label="Select all">
                                </th>
                                <th>Name</th>
                                <th style="width: 120px;">Type</th>
                                <th style="width: 140px;">Size</th>
                                <th style="width: 140px;">Modified</th>
                                <th style="width: 96px;">Actions</th>
                            </tr>
                            </thead>
                            <tbody id="fm-rows"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <?php $fmVer = @filemtime(__DIR__ . '/../../../public/js/fileManager.js') ?: time(); ?>
        <script src="/js/fileManager.js?v=<?= e((string) $fmVer) ?>"></script>

        <style>
            .fm-dropzone { position: relative; }
            .fm-dropzone.dragover { outline: 2px dashed var(--border-strong); outline-offset: 4px; }
            .fm-drop-hint {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                padding: 10px 12px;
                border: 1px dashed var(--border-primary);
                border-radius: var(--radius-md);
                background: var(--bg-secondary);
                color: var(--text-secondary);
                margin-bottom: 12px;
                user-select: none;
            }
            .fm-drop-hint .icon { width: 16px; height: 16px; color: var(--text-tertiary); }

            .fm-drop-overlay {
                position: absolute;
                inset: 0;
                display: none;
                align-items: center;
                justify-content: center;
                pointer-events: none;
                background: color-mix(in srgb, var(--bg-primary) 60%, transparent);
                border-radius: var(--radius-md);
            }
            .fm-dropzone.dragover .fm-drop-overlay { display: flex; }
            .fm-drop-overlay-inner {
                padding: 18px 20px;
                border-radius: var(--radius-lg);
                border: 1px dashed var(--border-strong);
                background: var(--bg-secondary);
                text-align: center;
            }
            .fm-drop-title { font-weight: 600; }
            .fm-drop-sub { font-size: 12px; color: var(--text-tertiary); margin-top: 4px; }
            .fm-name { display:flex; align-items:center; gap: 10px; min-width: 0; }
            .fm-icon { width: 18px; height: 18px; color: var(--text-tertiary); }
            .fm-row.selected { background: var(--bg-secondary); }
            .fm-row { cursor: pointer; }
            .fm-row:hover { background: var(--bg-secondary); }
            .fm-table-wrap { overflow-x: auto; }
            #fm-table th, #fm-table td { padding-top: 10px; padding-bottom: 10px; }
            #fm-table td { font-size: 13px; }
            .fm-icon-btn { padding-left: 10px; padding-right: 10px; }
            .fm-icon-btn .icon { width: 16px; height: 16px; }
            .fm-row-actions .btn { padding-left: 10px; padding-right: 10px; }
            .fm-select-cell { text-align: left; }
            .fm-mtime { max-width: 140px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .fm-row-actions .dropdown-menu { min-width: 200px; }

            .fm-checkbox {
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
            .fm-checkbox:focus-visible {
                outline: 2px solid var(--accent-blue);
                outline-offset: 2px;
            }
            .fm-checkbox:checked {
                background: var(--accent-blue);
                border-color: var(--accent-blue);
            }
            .fm-checkbox:checked::after {
                content: '';
                width: 9px;
                height: 5px;
                border: 2px solid rgba(255, 255, 255, 0.95);
                border-top: 0;
                border-right: 0;
                transform: rotate(-45deg);
                margin-top: -1px;
            }
            #fm-select-all { margin-left: 2px; }
        </style>
    <?php endif; ?>
</div>
