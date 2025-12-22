(function () {
    'use strict';

    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

    function formatBytes(bytes) {
        if (!Number.isFinite(bytes)) return '';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let v = bytes;
        let i = 0;
        while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
        return `${v.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
    }

    function showToast(type, message) {
        if (window.Chap && window.Chap.toast && typeof window.Chap.toast[type] === 'function') {
            window.Chap.toast[type](message);
            return;
        }
        // Fallback
        alert(message);
    }

    function openModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        if (window.Chap && window.Chap.modal && window.Chap.modal.open) {
            window.Chap.modal.open(modal);
            return;
        }
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        if (window.Chap && window.Chap.modal && window.Chap.modal.close) {
            window.Chap.modal.close(modal);
            return;
        }
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    }

    function generateId(prefix) {
        return `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
    }

    function arrayBufferToBase64(buf) {
        const bytes = new Uint8Array(buf);
        const chunk = 0x8000;
        let binary = '';
        for (let i = 0; i < bytes.length; i += chunk) {
            binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
        }
        return btoa(binary);
    }

    function init() {
        const root = qs('#file-manager');
        if (!root) return;

        const wsUrl = root.dataset.wsUrl;
        const sessionId = root.dataset.sessionId;
        const appUuid = root.dataset.applicationUuid;

        const statusEl = qs('#fm-status');
        const rootEl = qs('#fm-root');
        const pathInput = qs('#fm-path');
        const goBtn = qs('#fm-go');
        const refreshBtn = qs('#fm-refresh');
        const rowsEl = qs('#fm-rows');
        const breadcrumbEl = qs('#fm-breadcrumb');
        const dropzone = qs('#fm-dropzone');

        const uploadBtn = qs('#fm-upload-btn');
        const uploadInput = qs('#fm-upload-input');

        const newFolderBtn = qs('#fm-new-folder');
        const newFileBtn = qs('#fm-new-file');
        const renameBtn = qs('#fm-rename');
        const moveBtn = qs('#fm-move');
        const downloadBtn = qs('#fm-download');
        const deleteBtn = qs('#fm-delete');
        const archiveBtn = qs('#fm-archive');
        const unarchiveBtn = qs('#fm-unarchive');

        const editorWrap = qs('#fm-editor');
        const editorPathEl = qs('#fm-editor-path');
        const editorSaveBtn = qs('#fm-editor-save');
        const editorReloadBtn = qs('#fm-editor-reload');
        const editorTextarea = qs('#fm-editor-textarea');

        const containerSelectBtn = qs('#fm-container-select-btn');
        const selectedContainerNameEl = qs('#fm-selected-container-name');
        const containerDropdownMenu = qs('#fm-container-dropdown-menu');
        const containerSearch = qs('#fm-container-search');
        const containerListEl = qs('#fm-container-list');

        let ws = null;
        let authenticated = false;
        let currentPath = '/';
        let containerRoot = null;
        let persistentPrefixes = [];
        let selected = null; // { name, path, type }

        let containers = [];
        let selectedContainerId = null;

        const pending = new Map(); // requestId -> { resolve, reject }

        // Transfers
        const downloads = new Map(); // transferId -> { name, chunks: Uint8Array[], received }
        const uploads = new Map(); // transferId -> { file, offset, chunkSize, destDir, name }

        let cm = null;
        let editingPath = null;

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
                pending.set(requestId, { resolve, reject, action });
                const withContainer = { ...(payload || {}) };
                if (selectedContainerId) withContainer.container_id = selectedContainerId;
                if (!send('files:request', { request_id: requestId, action: action, payload: withContainer })) {
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

        function buildBreadcrumb(path) {
            breadcrumbEl.innerHTML = '';
            const parts = String(path || '/').split('/').filter(Boolean);
            const crumbs = [{ label: '/', path: '/' }];
            let acc = '';
            for (const p of parts) {
                acc += '/' + p;
                crumbs.push({ label: p, path: acc });
            }
            for (let i = 0; i < crumbs.length; i++) {
                const c = crumbs[i];
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-ghost btn-sm';
                btn.textContent = c.label;
                btn.addEventListener('click', () => {
                    navigate(c.path);
                });
                breadcrumbEl.appendChild(btn);
            }
        }

        function iconSvg(kind) {
            const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('viewBox', '0 0 24 24');
            svg.setAttribute('fill', 'none');
            svg.setAttribute('stroke', 'currentColor');
            svg.setAttribute('stroke-width', '2');
            svg.classList.add('fm-icon');
            const p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            p.setAttribute('stroke-linecap', 'round');
            p.setAttribute('stroke-linejoin', 'round');
            if (kind === 'folder') {
                p.setAttribute('d', 'M3 7a2 2 0 012-2h5l2 2h7a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z');
            } else {
                p.setAttribute('d', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z');
            }
            svg.appendChild(p);
            return svg;
        }

        function selectRow(path, name, type, tr) {
            selected = { path, name, type };
            qsa('tr.fm-row', rowsEl).forEach(r => r.classList.remove('selected'));
            if (tr) tr.classList.add('selected');
        }

        function render(entries) {
            rowsEl.innerHTML = '';

            // Parent dir row
            if (currentPath !== '/') {
                const tr = document.createElement('tr');
                tr.className = 'fm-row';
                const td0 = document.createElement('td');
                td0.textContent = '';
                const td1 = document.createElement('td');
                const wrap = document.createElement('div');
                wrap.className = 'fm-name';
                wrap.appendChild(iconSvg('folder'));
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = '..';
                btn.addEventListener('click', () => {
                    const parent = currentPath.split('/').slice(0, -1).join('/') || '/';
                    navigate(parent);
                });
                wrap.appendChild(btn);
                td1.appendChild(wrap);
                const td2 = document.createElement('td');
                td2.textContent = 'folder';
                const td3 = document.createElement('td');
                td3.textContent = '';
                const td4 = document.createElement('td');
                td4.textContent = '';
                const td5 = document.createElement('td');
                td5.textContent = '';
                tr.appendChild(td0);
                tr.appendChild(td1);
                tr.appendChild(td2);
                tr.appendChild(td3);
                tr.appendChild(td4);
                tr.appendChild(td5);
                rowsEl.appendChild(tr);
            }

            for (const e of (entries || [])) {
                const tr = document.createElement('tr');
                tr.className = 'fm-row';

                const td0 = document.createElement('td');
                td0.textContent = '';

                const td1 = document.createElement('td');
                const wrap = document.createElement('div');
                wrap.className = 'fm-name';
                wrap.appendChild(iconSvg(e.type === 'dir' ? 'folder' : 'file'));
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = e.name;
                btn.title = e.path;
                btn.addEventListener('click', () => {
                    selectRow(e.path, e.name, e.type, tr);
                });
                btn.addEventListener('dblclick', () => {
                    if (e.type === 'dir') {
                        navigate(e.path);
                    } else {
                        openEditor(e.path);
                    }
                });
                wrap.appendChild(btn);
                td1.appendChild(wrap);

                const td2 = document.createElement('td');
                td2.textContent = e.type === 'dir' ? 'folder' : 'file';

                const td3 = document.createElement('td');
                td3.textContent = e.type === 'dir' ? '' : formatBytes(e.size);

                const td4 = document.createElement('td');
                td4.textContent = e.mtime || '';

                const td5 = document.createElement('td');
                if (e.persistent) {
                    const badge = document.createElement('span');
                    badge.className = 'badge badge-info';
                    badge.textContent = 'Persistent';
                    td5.appendChild(badge);
                } else {
                    td5.textContent = '';
                }

                tr.appendChild(td0);
                tr.appendChild(td1);
                tr.appendChild(td2);
                tr.appendChild(td3);
                tr.appendChild(td4);
                tr.appendChild(td5);

                tr.addEventListener('click', () => {
                    selectRow(e.path, e.name, e.type, tr);
                });

                rowsEl.appendChild(tr);
            }
        }

        async function refresh() {
            if (!authenticated) return;
            const res = await request('list', { path: currentPath });
            if (res && res.root) {
                containerRoot = res.root;
                rootEl.textContent = `Root: ${containerRoot}`;
            }
            if (Array.isArray(res && res.persistent_prefixes)) {
                persistentPrefixes = res.persistent_prefixes;
            }
            pathInput.value = currentPath;
            buildBreadcrumb(currentPath);
            render(res.entries || []);
        }

        async function navigate(path) {
            currentPath = path || '/';
            selected = null;
            await refresh();
        }

        function guessModeByFilename(filename) {
            if (!window.CodeMirror || !window.CodeMirror.findModeByFileName) return null;
            const info = window.CodeMirror.findModeByFileName(filename || '') || window.CodeMirror.findModeByExtension((filename || '').split('.').pop());
            return info || null;
        }

        function ensureCodeMirrorMode(modeInfo) {
            if (!window.CodeMirror || !window.CodeMirror.requireMode) return Promise.resolve();
            if (!modeInfo || !modeInfo.mode) return Promise.resolve();

            if (!window.CodeMirror.modeURL) {
                window.CodeMirror.modeURL = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/%N/%N.min.js';
            }

            return new Promise((resolve) => {
                window.CodeMirror.requireMode(modeInfo.mode, () => resolve());
            });
        }

        async function openEditor(path) {
            const res = await request('read', { path: path });
            const content = (res && typeof res.content === 'string') ? res.content : '';
            editingPath = path;
            editorPathEl.textContent = path;

            if (editorWrap) editorWrap.classList.remove('hidden');

            if (!cm && window.CodeMirror) {
                // Initialize CodeMirror on first open
                cm = window.CodeMirror.fromTextArea(editorTextarea, {
                    lineNumbers: true,
                    indentUnit: 2,
                    tabSize: 2,
                    indentWithTabs: false,
                    lineWrapping: false,
                    viewportMargin: Infinity,
                });
            }

            if (cm) {
                cm.setValue(content);
                const modeInfo = guessModeByFilename(path.split('/').pop());
                await ensureCodeMirrorMode(modeInfo);
                if (modeInfo && modeInfo.mime) {
                    cm.setOption('mode', modeInfo.mime);
                }
                setTimeout(() => cm.refresh(), 0);
            } else {
                editorTextarea.value = content;
            }
        }

        async function saveEditor() {
            if (!editingPath) return;
            const content = cm ? cm.getValue() : (editorTextarea.value || '');
            await request('write', { path: editingPath, content: content });
            showToast('success', 'Saved');
        }

        async function reloadEditor() {
            if (!editingPath) return;
            const res = await request('read', { path: editingPath });
            const content = (res && typeof res.content === 'string') ? res.content : '';
            if (cm) cm.setValue(content);
            else editorTextarea.value = content;
            showToast('success', 'Reloaded');
        }

        async function doDelete() {
            if (!selected) return showToast('error', 'Select a file or folder');
            if (!confirm(`Delete ${selected.path}?`)) return;
            await request('delete', { path: selected.path, type: selected.type });
            selected = null;
            await refresh();
            showToast('success', 'Deleted');
        }

        async function doRename() {
            if (!selected) return showToast('error', 'Select a file or folder');
            const newName = prompt('New name', selected.name);
            if (!newName) return;
            await request('rename', { path: selected.path, new_name: newName });
            selected = null;
            await refresh();
            showToast('success', 'Renamed');
        }

        async function doMove() {
            if (!selected) return showToast('error', 'Select a file or folder');
            const dest = prompt('Move to (destination directory path)', currentPath);
            if (!dest) return;
            await request('move', { path: selected.path, dest_dir: dest });
            selected = null;
            await refresh();
            showToast('success', 'Moved');
        }

        async function doMkdir() {
            const name = prompt('Folder name');
            if (!name) return;
            await request('mkdir', { dir: currentPath, name: name });
            await refresh();
            showToast('success', 'Folder created');
        }

        async function doNewFile() {
            const name = prompt('File name');
            if (!name) return;
            await request('touch', { dir: currentPath, name: name });
            await refresh();
            showToast('success', 'File created');
        }

        async function doArchive() {
            if (!selected) return showToast('error', 'Select a file or folder');
            const outName = prompt('Archive name (e.g. archive.tar.gz)', selected.name + '.tar.gz');
            if (!outName) return;
            await request('archive', { path: selected.path, out_dir: currentPath, out_name: outName });
            await refresh();
            showToast('success', 'Archived');
        }

        async function doUnarchive() {
            if (!selected || selected.type !== 'file') return showToast('error', 'Select an archive file');
            const into = prompt('Extract into directory', currentPath);
            if (!into) return;
            await request('unarchive', { path: selected.path, dest_dir: into });
            await refresh();
            showToast('success', 'Unarchived');
        }

        async function doDownload() {
            if (!selected || selected.type !== 'file') return showToast('error', 'Select a file');
            await request('download', { path: selected.path });
            // actual file bytes come via files:download:* events
        }

        async function uploadFiles(fileList) {
            const files = Array.from(fileList || []).filter(Boolean);
            if (files.length === 0) return;

            for (const file of files) {
                const initRes = await request('upload:init', {
                    dir: currentPath,
                    name: file.name,
                    size: file.size,
                    type: file.type || 'application/octet-stream',
                });

                const transferId = initRes.transfer_id;
                const chunkSize = initRes.chunk_size || (256 * 1024);

                uploads.set(transferId, { file, offset: 0, chunkSize, destDir: currentPath, name: file.name });

                let offset = 0;
                while (offset < file.size) {
                    const slice = file.slice(offset, offset + chunkSize);
                    const buf = await slice.arrayBuffer();
                    const b64 = arrayBufferToBase64(buf);
                    await request('upload:chunk', { transfer_id: transferId, offset: offset, data_b64: b64 });
                    offset += slice.size;
                }

                await request('upload:commit', { transfer_id: transferId });
                uploads.delete(transferId);
                showToast('success', `Uploaded ${file.name}`);
            }

            await refresh();
        }

        function handleMessage(msg) {
            if (!msg || !msg.type) return;

            if (msg.type === 'auth:success') {
                authenticated = true;
                setStatus('badge-success', 'Connected');
                request('meta', {}).then((res) => {
                    containerRoot = res.root || '/';
                    rootEl.textContent = `Root: ${containerRoot}`;
                    if (Array.isArray(res.persistent_prefixes)) persistentPrefixes = res.persistent_prefixes;
                    currentPath = res.default_path || '/';
                    if (Array.isArray(res.containers)) {
                        containers = res.containers;
                        renderContainerList(containers);
                        if (res.selected_container_id) {
                            setSelectedContainer(res.selected_container_id);
                        }
                    }
                    navigate(currentPath);
                }).catch((e) => {
                    showToast('error', e.message || 'Failed to initialize');
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
                return;
            }

            if (msg.type === 'containers') {
                // Reuse the node's existing container discovery stream
                containers = Array.isArray(msg.containers) ? msg.containers : [];
                renderContainerList(containers);
                if (!selectedContainerId && containers.length) {
                    setSelectedContainer(containers[0].id);
                }
                return;
            }

            if (msg.type === 'files:download:start') {
                downloads.set(msg.transfer_id, { name: msg.name || 'download', chunks: [], received: 0, mime: msg.mime || 'application/octet-stream' });
                return;
            }

            if (msg.type === 'files:download:chunk') {
                const d = downloads.get(msg.transfer_id);
                if (!d) return;
                const bin = atob(msg.data_b64 || '');
                const arr = new Uint8Array(bin.length);
                for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
                d.chunks.push(arr);
                d.received += arr.length;
                return;
            }

            if (msg.type === 'files:download:done') {
                const d = downloads.get(msg.transfer_id);
                if (!d) return;
                const blob = new Blob(d.chunks, { type: d.mime });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = msg.name || d.name || 'download';
                document.body.appendChild(a);
                a.click();
                a.remove();
                URL.revokeObjectURL(url);
                downloads.delete(msg.transfer_id);
                showToast('success', 'Downloaded');
                return;
            }

            if (msg.type === 'error') {
                showToast('error', msg.error || 'Error');
            }
        }

        function setSelectedContainer(containerId) {
            selectedContainerId = containerId;
            const c = containers.find(x => x.id === containerId);
            if (selectedContainerNameEl) {
                selectedContainerNameEl.textContent = c ? c.name : 'Select container...';
            }
            // Refresh the current dir when container changes
            if (authenticated) {
                refresh().catch(() => {});
            }
        }

        function renderContainerList(list) {
            if (!containerListEl) return;
            containerListEl.innerHTML = '';
            const items = Array.isArray(list) ? list : [];

            if (items.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'dropdown-empty';
                empty.textContent = 'No containers available';
                containerListEl.appendChild(empty);
                return;
            }

            for (const c of items) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'dropdown-item';
                btn.textContent = `${c.name} (${c.status})`;
                btn.addEventListener('click', () => {
                    setSelectedContainer(c.id);
                });
                containerListEl.appendChild(btn);
            }
        }

        function connect() {
            if (!wsUrl) return;
            setStatus('badge-default', 'Connecting…');

            ws = new WebSocket(wsUrl);

            ws.addEventListener('open', () => {
                setStatus('badge-default', 'Authenticating…');
                send('auth', { session_id: sessionId, application_uuid: appUuid });
            });

            ws.addEventListener('message', (evt) => {
                try {
                    const msg = JSON.parse(evt.data);
                    handleMessage(msg);
                } catch {
                    // ignore
                }
            });

            ws.addEventListener('close', () => {
                authenticated = false;
                setStatus('badge-warning', 'Disconnected');
                // attempt reconnect
                setTimeout(connect, 1500);
            });

            ws.addEventListener('error', () => {
                setStatus('badge-danger', 'WebSocket error');
            });
        }

        // Bind UI
        goBtn.addEventListener('click', () => {
            navigate(pathInput.value || '/');
        });
        pathInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                navigate(pathInput.value || '/');
            }
        });
        refreshBtn.addEventListener('click', () => refresh());

        uploadBtn.addEventListener('click', () => uploadInput.click());
        uploadInput.addEventListener('change', () => {
            uploadFiles(uploadInput.files);
            uploadInput.value = '';
        });

        newFolderBtn.addEventListener('click', doMkdir);
        newFileBtn.addEventListener('click', doNewFile);
        renameBtn.addEventListener('click', doRename);
        moveBtn.addEventListener('click', doMove);
        deleteBtn.addEventListener('click', doDelete);
        downloadBtn.addEventListener('click', doDownload);
        archiveBtn.addEventListener('click', doArchive);
        unarchiveBtn.addEventListener('click', doUnarchive);

        editorSaveBtn.addEventListener('click', saveEditor);
        editorReloadBtn.addEventListener('click', reloadEditor);

        // Drag and drop
        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });
        dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            if (e.dataTransfer && e.dataTransfer.files) {
                uploadFiles(e.dataTransfer.files);
            }
        });

        // Container search filtering (best-effort)
        if (containerSearch) {
            containerSearch.addEventListener('input', (e) => {
                const q = String(e.target.value || '').toLowerCase().trim();
                if (!q) return renderContainerList(containers);
                renderContainerList(containers.filter(c => (String(c.name || '').toLowerCase().includes(q))));
            });
        }

        if (containerDropdownMenu) {
            containerDropdownMenu.addEventListener('dropdown:open', () => {
                if (containerSearch) {
                    containerSearch.value = '';
                    setTimeout(() => containerSearch.focus(), 0);
                }
                renderContainerList(containers);
            });
        }

        connect();
    }

    document.addEventListener('DOMContentLoaded', init);
})();
