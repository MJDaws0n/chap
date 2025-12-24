(function () {
    'use strict';

    const FALLBACK_LANGUAGES = [
        { label: 'Bash', mode: 'shell', mime: 'text/x-sh' },
        { label: 'CSS', mode: 'css', mime: 'text/css' },
        { label: 'Dockerfile', mode: 'dockerfile', mime: 'text/x-dockerfile' },
        { label: 'HTML', mode: 'htmlmixed', mime: 'text/html' },
        { label: 'JavaScript', mode: 'javascript', mime: 'text/javascript' },
        { label: 'JSON', mode: 'javascript', mime: 'application/json' },
        { label: 'Markdown', mode: 'markdown', mime: 'text/x-markdown' },
        { label: 'Nginx', mode: 'nginx', mime: 'text/x-nginx-conf' },
        { label: 'PHP', mode: 'php', mime: 'application/x-httpd-php' },
        { label: 'Python', mode: 'python', mime: 'text/x-python' },
        { label: 'SQL', mode: 'sql', mime: 'text/x-sql' },
        { label: 'TOML', mode: 'toml', mime: 'text/x-toml' },
        { label: 'YAML', mode: 'yaml', mime: 'text/x-yaml' },
    ];

    const FALLBACK_MODE_MAP = new Map(FALLBACK_LANGUAGES.map((l) => [l.mode, l]));

    function qs(sel, root) { return (root || document).querySelector(sel); }

    function showToast(type, message) {
        if (window.Chap && window.Chap.toast && typeof window.Chap.toast[type] === 'function') {
            window.Chap.toast[type](message);
            return;
        }
        alert(message);
    }

    function generateId(prefix) {
        return `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
    }

    function init() {
        const root = qs('#file-editor');
        if (!root) return;

        const wsUrl = root.dataset.wsUrl;
        const sessionId = root.dataset.sessionId;
        const appUuid = root.dataset.applicationUuid;
        const containerId = root.dataset.containerId;
        const initialPath = root.dataset.path;

        const statusEl = qs('#fe-status');
        const metaEl = qs('#fe-meta');
        const pathEl = qs('#fe-path');
        const languageEl = qs('#fe-language');
        const reloadBtn = qs('#fe-reload');
        const saveBtn = qs('#fe-save');
        const textarea = qs('#fe-textarea');

        const pending = new Map();
        let ws = null;
        let authenticated = false;
        let cm = null;
        let currentPath = initialPath || '';
        let selectedMode = ''; // '' = auto

        function setStatus(kind, text) {
            statusEl.textContent = text;
            statusEl.className = 'badge ' + (kind || 'badge-default');
        }

        function send(type, payload) {
            if (!ws || ws.readyState !== WebSocket.OPEN) return false;
            ws.send(JSON.stringify({ type: type, ...payload }));
            return true;
        }

        function request(action, payload) {
            const requestId = generateId('req');
            return new Promise((resolve, reject) => {
                pending.set(requestId, { resolve, reject });
                const p = { ...(payload || {}) };
                if (containerId) p.container_id = containerId;
                if (!send('files:request', { request_id: requestId, action: action, payload: p })) {
                    pending.delete(requestId);
                    reject(new Error('WebSocket not connected'));
                }
                setTimeout(() => {
                    if (pending.has(requestId)) {
                        pending.delete(requestId);
                        reject(new Error('Request timeout'));
                    }
                }, 30000);
            });
        }

        function guessModeByFilename(filename) {
            if (!window.CodeMirror || !window.CodeMirror.findModeByFileName) return null;
            const info = window.CodeMirror.findModeByFileName(filename || '') || window.CodeMirror.findModeByExtension((filename || '').split('.').pop());
            return info || null;
        }

        function ensureCodeMirrorMode(modeInfo) {
            if (!window.CodeMirror || !window.CodeMirror.requireMode) return Promise.resolve();
            if (!modeInfo || !modeInfo.mode) return Promise.resolve();
            // loadmode defaults to a relative mode URL (../mode/%N/%N.js) which breaks under our routes
            // and gets blocked by nosniff when it returns HTML. Always use the CDN.
            window.CodeMirror.modeURL = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/%N/%N.min.js';
            return new Promise((resolve) => {
                window.CodeMirror.requireMode(modeInfo.mode, () => resolve());
            });
        }

        function getModeInfoForSelectValue(value) {
            const v = String(value || '');
            if (!v) return null;

            // If CodeMirror meta isn't available for some reason, use a small built-in mapping.
            const fallback = FALLBACK_MODE_MAP.get(v);
            if ((!window.CodeMirror || !Array.isArray(window.CodeMirror.modeInfo)) && fallback) return fallback;
            if (!window.CodeMirror || !Array.isArray(window.CodeMirror.modeInfo)) return null;

            // Prefer exact mode match.
            const byMode = window.CodeMirror.modeInfo.find((m) => m && m.mode === v);
            if (byMode) return byMode;

            // Fallback to mime.
            const byMime = window.CodeMirror.modeInfo.find((m) => m && m.mime === v);
            return byMime || null;
        }

        function populateLanguagePicker() {
            if (!languageEl) return;
            // Keep the first option (Auto)
            while (languageEl.options.length > 1) languageEl.remove(1);

            if (window.CodeMirror && Array.isArray(window.CodeMirror.modeInfo) && window.CodeMirror.modeInfo.length) {
                // A-Z by human name
                const modes = window.CodeMirror.modeInfo
                    .filter((m) => m && m.name && m.mode)
                    .slice()
                    .sort((a, b) => String(a.name).localeCompare(String(b.name)));

                for (const m of modes) {
                    const opt = document.createElement('option');
                    opt.value = m.mode;
                    opt.textContent = m.name;
                    languageEl.appendChild(opt);
                }
                return;
            }

            // Fallback (keeps the picker useful even if meta.min.js failed to load).
            for (const entry of FALLBACK_LANGUAGES) {
                const opt = document.createElement('option');
                opt.value = entry.mode;
                opt.textContent = entry.label;
                languageEl.appendChild(opt);
            }
        }

        function populateLanguagePickerWhenReady() {
            if (!languageEl) return;

            const maxAttempts = 30;
            let attempt = 0;

            const tick = () => {
                attempt += 1;
                const metaReady = window.CodeMirror && Array.isArray(window.CodeMirror.modeInfo) && window.CodeMirror.modeInfo.length;

                if (metaReady || attempt >= maxAttempts) {
                    populateLanguagePicker();
                    return;
                }
                setTimeout(tick, 100);
            };

            tick();
        }

        async function applyEditorMode() {
            if (!cm || !window.CodeMirror) return;

            let modeInfo = null;
            if (selectedMode) {
                modeInfo = getModeInfoForSelectValue(selectedMode);
            } else {
                // Auto by filename
                modeInfo = guessModeByFilename(currentPath);

                // Better default for .env files
                if (!modeInfo && /(^|\/|\.)\.env(\.|$)/.test(currentPath) && window.CodeMirror.findModeByName) {
                    modeInfo = window.CodeMirror.findModeByName('Properties') || window.CodeMirror.findModeByName('Shell');
                }
            }

            await ensureCodeMirrorMode(modeInfo);

            if (modeInfo) {
                cm.setOption('mode', modeInfo.mime || modeInfo.mode);
            } else {
                cm.setOption('mode', null);
            }
        }

        async function loadFile() {
            if (!currentPath) {
                showToast('error', 'Missing file path');
                return;
            }
            pathEl.textContent = currentPath;
            setStatus('badge-default', 'Loading…');

            const res = await request('read', { path: currentPath });
            const content = (res && typeof res.content === 'string') ? res.content : '';

            if (!cm && window.CodeMirror) {
                cm = window.CodeMirror.fromTextArea(textarea, {
                    lineNumbers: true,
                    indentUnit: 2,
                    tabSize: 2,
                    indentWithTabs: false,
                    lineWrapping: true,
                    viewportMargin: Infinity,
                });
            }

            if (cm) {
                await applyEditorMode();
                cm.setValue(content);
                cm.focus();
            } else {
                textarea.value = content;
            }

            setStatus('badge-success', 'Ready');
        }

        async function saveFile() {
            if (!currentPath) return;
            const content = cm ? cm.getValue() : (textarea.value || '');
            setStatus('badge-default', 'Saving…');
            await request('write', { path: currentPath, content });
            setStatus('badge-success', 'Saved');
            showToast('success', 'Saved');
        }

        function handleMessage(msg) {
            if (!msg || !msg.type) return;

            if (msg.type === 'auth:success') {
                authenticated = true;
                setStatus('badge-success', 'Connected');
                metaEl.textContent = containerId ? `Container: ${containerId.slice(0, 12)}` : '';

                populateLanguagePickerWhenReady();

                loadFile().catch((e) => {
                    setStatus('badge-danger', 'Error');
                    showToast('error', e && e.message ? e.message : 'Failed to load file');
                });
                return;
            }

            if (msg.type === 'auth:failed') {
                authenticated = false;
                setStatus('badge-danger', 'Auth failed');
                showToast('error', msg.error || 'Authentication failed');
                return;
            }

            if (msg.type === 'files:response') {
                const requestId = msg.request_id;
                const entry = pending.get(requestId);
                if (!entry) return;
                pending.delete(requestId);
                if (!msg.ok) {
                    entry.reject(new Error(msg.error || 'Request failed'));
                    return;
                }
                entry.resolve(msg.result || {});
            }
        }

        if (!wsUrl) {
            setStatus('badge-danger', 'No WS URL');
            return;
        }

        ws = new WebSocket(wsUrl);
        ws.addEventListener('open', () => {
            send('auth', { session_id: sessionId, application_uuid: appUuid });
        });
        ws.addEventListener('message', (ev) => {
            let msg;
            try { msg = JSON.parse(ev.data); } catch { return; }
            handleMessage(msg);
        });
        ws.addEventListener('close', () => {
            authenticated = false;
            setStatus('badge-danger', 'Disconnected');
        });
        ws.addEventListener('error', () => {
            authenticated = false;
            setStatus('badge-danger', 'Error');
        });

        reloadBtn.addEventListener('click', () => {
            if (!authenticated) return;
            loadFile().catch((e) => showToast('error', e && e.message ? e.message : 'Failed to reload'));
        });

        saveBtn.addEventListener('click', () => {
            if (!authenticated) return;
            saveFile().catch((e) => {
                setStatus('badge-danger', 'Error');
                showToast('error', e && e.message ? e.message : 'Failed to save');
            });
        });

        if (languageEl) {
            languageEl.addEventListener('change', () => {
                selectedMode = String(languageEl.value || '');
                applyEditorMode().catch(() => {});
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
