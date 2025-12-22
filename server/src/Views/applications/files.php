<?php
/**
 * Application Files View
 * Container file manager (WebSocket-only, browser connects to node).
 */
$wsUrl = $browserWebsocketUrl ?? '';
?>

<div class="flex flex-col gap-6" id="file-manager"
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
                    <span class="breadcrumb-current">Files</span>
                </nav>

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-lg icon-box-blue">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7a2 2 0 012-2h5l2 2h7a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="page-header-title">Files</h1>
                        <p class="page-header-description truncate">Browse and manage files inside the running container.</p>
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

                        <span class="text-sm text-secondary" id="fm-root"></span>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <input type="file" id="fm-upload-input" class="hidden" multiple>
                        <button type="button" class="btn btn-secondary btn-sm" id="fm-upload-btn">Upload</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="fm-new-folder">New Folder</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="fm-new-file">New File</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="fm-rename">Rename</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="fm-move">Move</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="fm-download">Download</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="fm-archive">Archive</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="fm-unarchive">Unarchive</button>
                        <button type="button" class="btn btn-danger btn-sm" id="fm-delete">Delete</button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div class="flex items-center gap-2 flex-wrap" id="fm-breadcrumb"></div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <input type="text" class="input input-sm" id="fm-path" placeholder="/" style="min-width: 260px;">
                        <button type="button" class="btn btn-secondary btn-sm" id="fm-go">Go</button>
                        <button type="button" class="btn btn-ghost btn-sm" id="fm-refresh">Refresh</button>
                    </div>
                </div>

                <div class="mt-4 fm-dropzone" id="fm-dropzone">
                    <div class="fm-table-wrap">
                        <table class="table" id="fm-table">
                            <thead>
                            <tr>
                                <th style="width: 44px;"></th>
                                <th>Name</th>
                                <th style="width: 120px;">Type</th>
                                <th style="width: 140px;">Size</th>
                                <th style="width: 200px;">Modified</th>
                                <th style="width: 140px;">Persistence</th>
                            </tr>
                            </thead>
                            <tbody id="fm-rows"></tbody>
                        </table>
                    </div>
                    <div class="text-sm text-tertiary mt-2">Tip: drag & drop files here to upload.</div>
                </div>

                <div class="mt-5 hidden" id="fm-editor">
                    <div class="flex items-center justify-between gap-3 flex-wrap mb-3">
                        <div class="min-w-0">
                            <h3 class="card-title">Edit file</h3>
                            <div class="text-sm text-secondary truncate" id="fm-editor-path"></div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" class="btn btn-secondary btn-sm" id="fm-editor-reload">Reload</button>
                            <button type="button" class="btn btn-primary btn-sm" id="fm-editor-save">Save</button>
                        </div>
                    </div>
                    <textarea id="fm-editor-textarea" class="textarea" rows="14"></textarea>
                </div>
            </div>
        </div>

        <!-- CodeMirror 5 (MIT) via CDN + dynamic mode loader -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
        <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/mode/loadmode.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/meta.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

        <script src="/js/fileManager.js"></script>

        <style>
            .fm-dropzone.dragover { outline: 2px dashed var(--border-strong); outline-offset: 4px; }
            .fm-name { display:flex; align-items:center; gap: 10px; min-width: 0; }
            .fm-name button { all: unset; cursor: pointer; color: inherit; min-width: 0; }
            .fm-name button:hover { text-decoration: underline; }
            .fm-icon { width: 18px; height: 18px; color: var(--text-tertiary); }
            .fm-row.selected { background: var(--bg-secondary); }
            .fm-table-wrap { overflow-x: auto; }
            .CodeMirror { border: 1px solid var(--border); border-radius: var(--radius-md); height: min(520px, 60vh); }
        </style>
    <?php endif; ?>
</div>
