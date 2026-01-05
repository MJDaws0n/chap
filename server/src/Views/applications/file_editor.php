<?php
/**
 * Application File Editor View
 */
$wsUrl = $browserWebsocketUrl ?? '';
$path = $path ?? '';
$dir = $dir ?? '';
$containerId = $containerId ?? '';

$backDir = is_string($dir) && $dir !== '' ? $dir : (is_string($path) && $path !== '' ? dirname($path) : '/');
if (!is_string($backDir) || $backDir === '' || $backDir[0] !== '/') {
    $backDir = '/';
}

$backParams = ['path' => $backDir];
if (!empty($containerId)) {
    $backParams['container'] = $containerId;
}

$backUrl = '/applications/' . $application->uuid . '/files';
if (!empty($backParams)) {
    $backUrl .= '?' . http_build_query($backParams);
}
?>

<div class="flex flex-col gap-6" id="file-editor"
     data-ws-url="<?= e($wsUrl) ?>"
     data-session-id="<?= e($sessionId ?? '') ?>"
     data-application-uuid="<?= e($application->uuid) ?>"
     data-container-id="<?= e($containerId) ?>"
     data-path="<?= e($path) ?>">

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
                    <span class="breadcrumb-item"><a href="<?= e($backUrl) ?>">Files</a></span>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current">Edit</span>
                </nav>

                <div class="flex items-center gap-4 mt-4">
                    <div class="icon-box icon-box-lg icon-box-blue">
                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 2h8v4H8V2z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="page-header-title">Edit file</h1>
                        <p class="page-header-description truncate" id="fe-path">Loading…</p>
                    </div>
                </div>
            </div>

            <div class="page-header-actions flex-wrap">
                <a href="<?= e($backUrl) ?>" class="btn btn-secondary">Back</a>
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
                        <span class="badge badge-default" id="fe-status">Connecting…</span>
                        <span class="text-sm text-secondary" id="fe-meta"></span>

                        <div class="flex items-center gap-2">
                            <label class="text-sm text-secondary" for="fe-language">Language</label>
                            <select class="select select-sm" id="fe-language" aria-label="Language">
                                <option value="">Auto</option>
                                <option value="text/x-sh">Bash / Shell</option>
                                <option value="text/css">CSS</option>
                                <option value="text/x-dockerfile">Dockerfile</option>
                                <option value="text/html">HTML</option>
                                <option value="text/javascript">JavaScript</option>
                                <option value="application/typescript">TypeScript</option>
                                <option value="application/json">JSON</option>
                                <option value="text/x-markdown">Markdown</option>
                                <option value="text/x-nginx-conf">Nginx</option>
                                <option value="application/x-httpd-php">PHP</option>
                                <option value="text/x-python">Python</option>
                                <option value="text/x-sql">SQL</option>
                                <option value="text/x-toml">TOML</option>
                                <option value="text/x-yaml">YAML</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <a class="btn btn-secondary btn-sm" href="<?= e($backUrl) ?>" title="Back to files" aria-label="Back to files">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6" />
                            </svg>
                        </a>
                        <button type="button" class="btn btn-secondary btn-sm" id="fe-reload" title="Reload" aria-label="Reload">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v6h6" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 20v-6h-6" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 19a9 9 0 0114-7l1 1" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 5a9 9 0 01-14 7l-1-1" />
                            </svg>
                        </button>
                        <button type="button" class="btn btn-primary btn-sm" id="fe-save" title="Save" aria-label="Save">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 21v-8H7v8" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 3v5h8" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <div class="card-body">
                <textarea id="fe-textarea" class="textarea" rows="18"></textarea>
            </div>
        </div>

        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/material-darker.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
        <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/mode/loadmode.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/meta.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

        <?php $feVer = @filemtime(__DIR__ . '/../../../public/js/fileEditor.js') ?: time(); ?>
        <script src="/js/fileEditor.js?v=<?= e((string) $feVer) ?>"></script>

        <style>
            .CodeMirror {
                border: 1px solid var(--border-secondary);
                border-radius: var(--radius-md);
                height: min(70vh, 720px);
                max-width: 100%;
            }
            .CodeMirror-scroll {
                overflow-x: hidden;
            }
            .CodeMirror pre {
                word-break: break-word;
                overflow-wrap: anywhere;
            }
            #file-editor .card-body { overflow: hidden; }

            /* Light mode: keep token colors aligned to design tokens.
               Dark mode: use CodeMirror's built-in theme (material-darker). */
            :root:not([data-theme]),
            :root[data-theme="light"] {
                #file-editor .CodeMirror {
                    background: var(--bg-secondary);
                    color: var(--text-primary);
                }
                #file-editor .CodeMirror-gutters {
                    background: var(--bg-secondary);
                    border-right: 1px solid var(--border-primary);
                }
                #file-editor .CodeMirror-linenumber { color: var(--text-tertiary); }
                #file-editor .CodeMirror-cursor { border-left: 1px solid var(--text-primary); }
                #file-editor .CodeMirror-selected { background: var(--accent-blue-subtle) !important; }
                #file-editor .CodeMirror-activeline-background { background: var(--bg-tertiary); }

                /* Token colors using existing theme accents */
                #file-editor .cm-keyword { color: var(--accent-purple); }
                #file-editor .cm-atom, #file-editor .cm-number { color: var(--accent-orange); }
                #file-editor .cm-def, #file-editor .cm-property { color: var(--accent-blue); }
                #file-editor .cm-string { color: var(--accent-green); }
                #file-editor .cm-comment { color: var(--text-tertiary); }
                #file-editor .cm-variable, #file-editor .cm-variable-2, #file-editor .cm-variable-3 { color: var(--text-primary); }
                #file-editor .cm-operator { color: var(--text-secondary); }
                #file-editor .cm-meta, #file-editor .cm-qualifier { color: var(--accent-gray); }
                #file-editor .cm-tag { color: var(--accent-red); }
                #file-editor .cm-attribute { color: var(--accent-orange); }
                #file-editor .cm-builtin { color: var(--accent-teal); }
                #file-editor .cm-error { color: var(--accent-red); }
            }

            #file-editor .icon { width: 18px; height: 18px; }
        </style>
    <?php endif; ?>
</div>
