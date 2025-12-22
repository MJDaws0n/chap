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

    async function chapConfirm(options) {
        const title = (options && options.title) ? String(options.title) : 'Confirm';
        const text = (options && options.text) ? String(options.text) : '';
        const confirmButtonText = (options && options.confirmButtonText) ? String(options.confirmButtonText) : 'Confirm';

        if (window.Chap && window.Chap.modal && typeof window.Chap.modal.confirm === 'function') {
            const res = await window.Chap.modal.confirm(title, text, { confirmText: confirmButtonText });
            return !!(res && res.confirmed);
        }

        return confirm(text ? `${title}\n\n${text}` : title);
    }

    async function chapConfirmDelete(options) {
        const title = (options && options.title) ? String(options.title) : 'Confirm Delete';
        const text = (options && options.text) ? String(options.text) : 'Are you sure?';

        if (window.Chap && window.Chap.modal && typeof window.Chap.modal.confirmDelete === 'function') {
            const res = await window.Chap.modal.confirmDelete(title, text);
            return !!(res && res.confirmed);
        }

        return confirm(`${title}\n\n${text}`);
    }

    async function chapPrompt(options) {
        const title = (options && options.title) ? String(options.title) : 'Input';
        const label = (options && options.label) ? String(options.label) : '';
        const placeholder = (options && options.placeholder) ? String(options.placeholder) : '';
        const confirmButtonText = (options && options.confirmButtonText) ? String(options.confirmButtonText) : 'OK';
        const defaultValue = (options && options.defaultValue != null) ? String(options.defaultValue) : '';
        const validate = (options && typeof options.validate === 'function') ? options.validate : null;

        // Keep asking until valid (or cancelled).
        for (;;) {
            if (window.Chap && window.Chap.modal && typeof window.Chap.modal.prompt === 'function') {
                const res = await window.Chap.modal.prompt(title, {
                    message: label,
                    placeholder,
                    value: defaultValue,
                    confirmText: confirmButtonText,
                    required: true,
                });
                if (!res || !res.confirmed) return null;
                const out = String(res.value || '').trim();
                if (validate) {
                    const err = validate(out);
                    if (err) {
                        showToast('error', err);
                        continue;
                    }
                }
                return out;
            }

            const v = prompt(title, defaultValue);
            if (v == null) return null;
            const out2 = String(v).trim();
            if (!out2) return null;
            if (validate) {
                const err2 = validate(out2);
                if (err2) return null;
            }
            return out2;
        }
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
        const manualLocationBtn = qs('#fm-manual-location');
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
        const copyBtn = qs('#fm-copy');
        const downloadBtn = qs('#fm-download');
        const deleteBtn = qs('#fm-delete');
        const archiveBtn = qs('#fm-archive');
        const unarchiveBtn = qs('#fm-unarchive');

        const selectAllCb = qs('#fm-select-all');

        const containerSelectBtn = qs('#fm-container-select-btn');
        const selectedContainerNameEl = qs('#fm-selected-container-name');
        const containerDropdownMenu = qs('#fm-container-dropdown-menu');
        const containerSearch = qs('#fm-container-search');
        const containerListEl = qs('#fm-container-list');

        let ws = null;
        let authenticated = false;
        let currentPath = '/';
        let initialPathFromUrl = false;
        let containerRoot = null;
        let persistentPrefixes = [];
        const selectedPaths = new Set();
        const selectedItems = new Map(); // path -> { name, path, type }

        let containers = [];
        let selectedContainerId = null;

        let lastRenderedEntries = [];
        const visibleRowRefs = new Map(); // path -> { tr, cb, item }

        // Restore navigation state from query params (used by editor Back button)
        try {
            const u = new URL(window.location.href);
            const qp = u.searchParams.get('path');
            if (qp && typeof qp === 'string' && qp.startsWith('/')) {
                currentPath = qp;
                initialPathFromUrl = true;
            }
            const qc = u.searchParams.get('container');
            if (qc && typeof qc === 'string' && qc.trim()) selectedContainerId = qc.trim();
        } catch {
            // ignore
        }

        const pending = new Map(); // requestId -> { resolve, reject }

        // Transfers
        const downloads = new Map(); // transferId -> { name, chunks: Uint8Array[], received }
        const uploads = new Map(); // transferId -> { file, offset, chunkSize, destDir, name }

        function dirnameOf(p) {
            const s = String(p || '');
            if (!s || s === '/') return '/';
            const parts = s.split('/');
            parts.pop();
            const d = parts.join('/');
            return d === '' ? '/' : d;
        }

        function joinPath(dir, name) {
            const d = String(dir || '/');
            const n = String(name || '');
            if (!n) return d || '/';
            if (d === '/' || d === '') return '/' + n.replace(/^\/+/, '');
            return d.replace(/\/+$/, '') + '/' + n.replace(/^\/+/, '');
        }

        function splitBaseExt(filename) {
            const s = String(filename || '');
            const i = s.lastIndexOf('.');
            if (i > 0 && i < s.length - 1) {
                return { base: s.slice(0, i), ext: s.slice(i) };
            }
            return { base: s, ext: '' };
        }

        function isDirectChildPath(childPath, dirPath) {
            return dirnameOf(childPath) === (dirPath || '/');
        }

        function clearSelection() {
            selectedPaths.clear();
            selectedItems.clear();
        }

        function updateToolbarVisibility() {
            const list = getSelectedList();
            const count = list.length;
            const one = count === 1 ? list[0] : null;

            const toggle = (btn, visible) => {
                if (!btn) return;
                btn.classList.toggle('hidden', !visible);
            };

            // Show multi-select-related icons only when selection exists.
            toggle(renameBtn, count === 1);
            toggle(moveBtn, count >= 1);
            toggle(copyBtn, count >= 1);
            toggle(archiveBtn, count >= 1);
            toggle(deleteBtn, count >= 1);

            // Only show download/unarchive when selection makes sense.
            toggle(downloadBtn, count === 1 && one && one.type === 'file');
            toggle(unarchiveBtn, count === 1 && one && one.type === 'file');
        }

        function updateSelectAllState() {
            if (!selectAllCb) return;
            const total = Array.isArray(lastRenderedEntries) ? lastRenderedEntries.length : 0;
            if (total === 0) {
                selectAllCb.checked = false;
                selectAllCb.indeterminate = false;
                return;
            }
            let selectedVisible = 0;
            for (const e of lastRenderedEntries) {
                if (e && e.path && selectedPaths.has(e.path)) selectedVisible += 1;
            }
            selectAllCb.checked = selectedVisible === total;
            selectAllCb.indeterminate = selectedVisible > 0 && selectedVisible < total;
        }

        function toggleSelection(item, checked, tr) {
            if (!item || !item.path) return;
            if (checked) {
                selectedPaths.add(item.path);
                selectedItems.set(item.path, item);
                if (tr) tr.classList.add('selected');
            } else {
                selectedPaths.delete(item.path);
                selectedItems.delete(item.path);
                if (tr) tr.classList.remove('selected');
            }

            updateToolbarVisibility();
            updateSelectAllState();
        }

        function getSelectedList() {
            return Array.from(selectedItems.values());
        }

        function setStatus(kind, text) {
            statusEl.textContent = text;
            statusEl.className = 'badge ' + (kind || 'badge-default');
        }

        function syncUrlState() {
            try {
                const u = new URL(window.location.href);
                // Keep in sync for editor Back button + shareable links.
                u.searchParams.set('path', currentPath || '/');
                if (selectedContainerId) u.searchParams.set('container', selectedContainerId);
                else u.searchParams.delete('container');
                window.history.replaceState({}, '', u.toString());
            } catch {
                // ignore
            }
        }

        function formatMtimeShort(iso) {
            const s = String(iso || '').trim();
            if (!s) return '';
            // Prefer ISO compact "YYYY-MM-DD HH:MM" when possible.
            if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(s)) {
                return s.slice(0, 16).replace('T', ' ');
            }
            try {
                const d = new Date(s);
                if (!Number.isFinite(d.getTime())) return s;
                return d.toISOString().slice(0, 16).replace('T', ' ');
            } catch {
                return s;
            }
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

        function render(entries) {
            rowsEl.innerHTML = '';
            visibleRowRefs.clear();
            lastRenderedEntries = Array.isArray(entries) ? entries : [];

            // Parent dir row
            if (currentPath !== '/') {
                const tr = document.createElement('tr');
                tr.className = 'fm-row';
                const td0 = document.createElement('td');
                td0.className = 'fm-select-cell';
                td0.textContent = '';

                const td1 = document.createElement('td');
                const wrap = document.createElement('div');
                wrap.className = 'fm-name';
                wrap.appendChild(iconSvg('folder'));
                const label = document.createElement('span');
                label.textContent = '..';
                wrap.appendChild(label);
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

                tr.addEventListener('click', () => {
                    const parent = currentPath.split('/').slice(0, -1).join('/') || '/';
                    navigate(parent);
                });
            }

            for (const e of (entries || [])) {
                const tr = document.createElement('tr');
                tr.className = 'fm-row';

                const td0 = document.createElement('td');
                td0.className = 'fm-select-cell';
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.className = 'fm-checkbox';
                cb.title = 'Select';
                cb.setAttribute('aria-label', 'Select');
                cb.checked = selectedPaths.has(e.path);
                cb.addEventListener('click', (ev) => { ev.stopPropagation(); });
                cb.addEventListener('change', () => {
                    toggleSelection({ name: e.name, path: e.path, type: e.type }, cb.checked, tr);
                });
                td0.appendChild(cb);

                visibleRowRefs.set(e.path, { tr, cb, item: { name: e.name, path: e.path, type: e.type } });

                function selectOnlyThisRow() {
                    // If it is already the only selected item, keep it.
                    if (selectedPaths.size === 1 && selectedPaths.has(e.path)) return;

                    clearSelection();
                    // Clear UI checkboxes/rows quickly without a full refresh.
                    qsa('#fm-rows tr.fm-row.selected').forEach((row) => row.classList.remove('selected'));
                    qsa('#fm-rows input.fm-checkbox').forEach((x) => { x.checked = false; });
                    cb.checked = true;
                    toggleSelection({ name: e.name, path: e.path, type: e.type }, true, tr);
                }

                const td1 = document.createElement('td');
                const wrap = document.createElement('div');
                wrap.className = 'fm-name';
                wrap.appendChild(iconSvg(e.type === 'dir' ? 'folder' : 'file'));
                const nameSpan = document.createElement('span');
                nameSpan.textContent = e.name;
                nameSpan.title = e.path;
                nameSpan.className = 'truncate';
                wrap.appendChild(nameSpan);

                if (e.persistent) {
                    const badge = document.createElement('span');
                    badge.className = 'badge badge-info';
                    badge.textContent = 'Persistent';
                    wrap.appendChild(badge);
                }
                td1.appendChild(wrap);

                const td2 = document.createElement('td');
                td2.textContent = e.type === 'dir' ? 'folder' : 'file';

                const td3 = document.createElement('td');
                td3.textContent = e.type === 'dir' ? '' : formatBytes(e.size);

                const td4 = document.createElement('td');
                td4.className = 'fm-mtime';
                td4.textContent = formatMtimeShort(e.mtime);
                td4.title = e.mtime || '';

                const td5 = document.createElement('td');
                td5.className = 'fm-row-actions';

                // Per-row actions dropdown (match toolbar actions)
                const dropdownId = generateId('fm_row_menu');
                const dd = document.createElement('div');
                dd.className = 'dropdown';
                const trigger = document.createElement('button');
                trigger.type = 'button';
                trigger.className = 'btn btn-ghost btn-sm';
                trigger.title = 'Actions';
                trigger.setAttribute('aria-label', 'Actions');
                trigger.setAttribute('data-dropdown-trigger', dropdownId);
                trigger.setAttribute('data-dropdown-placement', 'bottom-end');
                trigger.innerHTML = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6h.01M12 12h.01M12 18h.01"/></svg>';

                const menu = document.createElement('div');
                menu.className = 'dropdown-menu';
                menu.id = dropdownId;
                const items = document.createElement('div');
                items.className = 'dropdown-items';

                const mkItem = (label, onClick, danger) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'dropdown-item';
                    b.textContent = label;
                    if (danger) b.classList.add('text-danger');
                    b.addEventListener('click', (ev) => {
                        ev.preventDefault();
                        Promise.resolve(onClick()).catch((err) => {
                            showToast('error', err && err.message ? err.message : 'Action failed');
                        });
                    });
                    return b;
                };

                items.appendChild(mkItem('Upload', async () => {
                    uploadInput.click();
                }));
                items.appendChild(mkItem('New folder', async () => {
                    await doMkdir();
                }));
                items.appendChild(mkItem('New file', async () => {
                    await doNewFile();
                }));
                items.appendChild(mkItem('Rename', async () => {
                    selectOnlyThisRow();
                    await doRename();
                }));
                items.appendChild(mkItem('Move', async () => {
                    selectOnlyThisRow();
                    await doMove();
                }));
                items.appendChild(mkItem('Copy', async () => {
                    selectOnlyThisRow();
                    await doCopy();
                }));
                items.appendChild(mkItem('Download', async () => {
                    selectOnlyThisRow();
                    await doDownload();
                }));
                items.appendChild(mkItem('Archive', async () => {
                    selectOnlyThisRow();
                    await doArchive();
                }));
                items.appendChild(mkItem('Unarchive', async () => {
                    selectOnlyThisRow();
                    await doUnarchive();
                }));
                items.appendChild(mkItem('Delete', async () => {
                    selectOnlyThisRow();
                    await doDelete();
                }, true));

                menu.appendChild(items);
                dd.appendChild(trigger);
                dd.appendChild(menu);
                td5.appendChild(dd);

                tr.appendChild(td0);
                tr.appendChild(td1);
                tr.appendChild(td2);
                tr.appendChild(td3);
                tr.appendChild(td4);
                tr.appendChild(td5);

                tr.addEventListener('click', (ev) => {
                    // Prevent row navigation when interacting with actions/selection.
                    if (ev && ev.target && ev.target.closest) {
                        if (ev.target.closest('.fm-row-actions')) return;
                        if (ev.target.closest('.fm-select-cell')) return;
                        if (ev.target.closest('[data-dropdown-trigger]')) return;
                    }

                    if (e.type === 'dir') {
                        navigate(e.path);
                        return;
                    }

                    const u = new URL(`/applications/${encodeURIComponent(appUuid)}/files/edit`, window.location.origin);
                    if (selectedContainerId) u.searchParams.set('container', selectedContainerId);
                    u.searchParams.set('path', e.path);
                    u.searchParams.set('dir', currentPath);
                    window.location.href = u.toString();
                });

                // Right-click opens the same actions menu.
                tr.addEventListener('contextmenu', (ev) => {
                    ev.preventDefault();
                    // Ensure selection matches the row.
                    selectOnlyThisRow();
                    // Open the dropdown at cursor (viewport clamped by Chap dropdown system).
                    if (window.Chap && window.Chap.dropdown && typeof window.Chap.dropdown.open === 'function') {
                        const cursorTrigger = document.createElement('button');
                        cursorTrigger.type = 'button';
                        cursorTrigger.setAttribute('aria-hidden', 'true');
                        cursorTrigger.tabIndex = -1;
                        cursorTrigger.style.position = 'fixed';
                        cursorTrigger.style.left = `${ev.clientX}px`;
                        cursorTrigger.style.top = `${ev.clientY}px`;
                        cursorTrigger.style.width = '1px';
                        cursorTrigger.style.height = '1px';
                        cursorTrigger.style.opacity = '0';
                        cursorTrigger.style.pointerEvents = 'none';
                        cursorTrigger.style.zIndex = '0';
                        document.body.appendChild(cursorTrigger);

                        const cleanup = () => {
                            try { cursorTrigger.remove(); } catch (_) { /* ignore */ }
                            menu.removeEventListener('dropdown:close', cleanup);
                        };
                        menu.addEventListener('dropdown:close', cleanup);

                        window.Chap.dropdown.open(cursorTrigger, menu, { placement: 'bottom-start', offset: 6 });
                    } else {
                        // Fallback
                        trigger.click();
                    }
                });

                rowsEl.appendChild(tr);

                if (selectedPaths.has(e.path)) tr.classList.add('selected');
            }

            updateToolbarVisibility();
            updateSelectAllState();
        }

        async function refresh() {
            if (!authenticated) return;
            try {
                const res = await request('list', { path: currentPath });
                if (res && res.root) {
                    containerRoot = res.root;
                    rootEl.textContent = `Root: ${containerRoot}`;
                }
                if (Array.isArray(res && res.persistent_prefixes)) {
                    persistentPrefixes = res.persistent_prefixes;
                }
                buildBreadcrumb(currentPath);
                render(res.entries || []);
                syncUrlState();
            } catch (e) {
                // If the directory doesn't exist in this container (common when switching containers),
                // auto-fallback to root instead of spamming an error toast.
                if (currentPath !== '/') {
                    currentPath = '/';
                    clearSelection();
                    try {
                        const res2 = await request('list', { path: currentPath });
                        if (res2 && res2.root) {
                            containerRoot = res2.root;
                            rootEl.textContent = `Root: ${containerRoot}`;
                        }
                        if (Array.isArray(res2 && res2.persistent_prefixes)) {
                            persistentPrefixes = res2.persistent_prefixes;
                        }
                        buildBreadcrumb(currentPath);
                        render(res2.entries || []);
                        syncUrlState();
                        return;
                    } catch {
                        // fall through
                    }
                }

                setStatus('badge-danger', 'Error');
                showToast('error', e && e.message ? e.message : 'Failed to load directory');
            }
        }

        async function navigate(path) {
            currentPath = path || '/';
            clearSelection();
            syncUrlState();
            await refresh();
        }

        async function doDelete() {
            const list = getSelectedList();
            if (!list.length) return showToast('error', 'Select one or more items');
            const label = list.length === 1 ? list[0].path : `${list.length} items`;

            const ok = await chapConfirmDelete({
                title: 'Delete',
                text: `Delete ${label}?`,
            });
            if (!ok) return;

            if (list.length === 1) {
                await request('delete', { path: list[0].path, type: list[0].type });
            } else {
                await request('delete', { paths: list.map((x) => x.path) });
            }
            clearSelection();
            await refresh();
            showToast('success', 'Deleted');
        }

        async function doRename() {
            const list = getSelectedList();
            if (list.length !== 1) return showToast('error', 'Select exactly one item');
            const item = list[0];

            const newName = await chapPrompt({
                title: 'Rename',
                label: 'New name',
                defaultValue: item.name,
                confirmButtonText: 'Rename',
            });
            if (!newName) return;

            await request('rename', { path: item.path, new_name: newName });
            clearSelection();
            await refresh();
            showToast('success', 'Renamed');
        }

        async function doMove() {
            const list = getSelectedList();
            if (!list.length) return showToast('error', 'Select one or more items');

            const dest = await chapPrompt({
                title: 'Move',
                label: 'Destination directory path',
                defaultValue: currentPath,
                placeholder: '/',
                confirmButtonText: 'Move',
                validate: (v) => {
                    const s = String(v || '').trim();
                    if (!s.startsWith('/')) return 'Path must start with /';
                    return null;
                },
            });
            if (!dest) return;

            if (list.length === 1) {
                await request('move', { path: list[0].path, dest_dir: dest });
            } else {
                await request('move', { paths: list.map((x) => x.path), dest_dir: dest });
            }
            clearSelection();
            await refresh();
            showToast('success', 'Moved');
        }

        async function doCopy() {
            const list = getSelectedList();
            if (!list.length) return showToast('error', 'Select one or more items');

            async function duplicateOne(it) {
                const dir = dirnameOf(it.path);
                const parts = splitBaseExt(it.name);
                const base2 = parts.base + '2';
                const candidates = [base2 + parts.ext];
                for (let i = 0; i < 12; i++) {
                    const r = Math.floor(1000 + Math.random() * 9000);
                    candidates.push(base2 + String(r) + parts.ext);
                }

                for (const name of candidates) {
                    const destPath = joinPath(dir, name);
                    try {
                        await request('copy', { path: it.path, dest_path: destPath });
                        return name;
                    } catch (e) {
                        const msg = (e && e.message) ? String(e.message) : '';
                        if (/exists|already exists/i.test(msg)) continue;
                        throw e;
                    }
                }
                throw new Error('Could not find an available name');
            }

            let okCount = 0;
            for (const it of list) {
                await duplicateOne(it);
                okCount += 1;
            }
            clearSelection();
            await refresh();
            showToast('success', okCount === 1 ? 'Duplicated' : `Duplicated ${okCount} items`);
        }

        async function doMkdir() {
            const name = await chapPrompt({
                title: 'New folder',
                label: 'Folder name',
                confirmButtonText: 'Create',
            });
            if (!name) return;
            await request('mkdir', { dir: currentPath, name: name });
            await refresh();
            showToast('success', 'Folder created');
        }

        async function doNewFile() {
            const name = await chapPrompt({
                title: 'New file',
                label: 'File name',
                confirmButtonText: 'Create',
            });
            if (!name) return;
            await request('touch', { dir: currentPath, name: name });
            await refresh();
            showToast('success', 'File created');
        }

        async function doArchive() {
            const list = getSelectedList();
            if (!list.length) return showToast('error', 'Select one or more items');

            for (const it of list) {
                if (!isDirectChildPath(it.path, currentPath)) {
                    showToast('error', 'To archive multiple items, select items from the same directory');
                    return;
                }
            }

            const defaultName = list.length === 1 ? `${list[0].name}.tar.gz` : 'archive.tar.gz';
            const outName = await chapPrompt({
                title: 'Archive',
                label: 'Archive name (e.g. archive.tar.gz)',
                defaultValue: defaultName,
                confirmButtonText: 'Archive',
            });
            if (!outName) return;

            await request('archive', {
                dir: currentPath,
                names: list.map((x) => x.name),
                out_dir: currentPath,
                out_name: outName,
            });

            clearSelection();
            await refresh();
            showToast('success', 'Archived');
        }

        async function doUnarchive() {
            const list = getSelectedList();
            if (list.length !== 1 || list[0].type !== 'file') return showToast('error', 'Select exactly one archive file');
            const item = list[0];

            const into = await chapPrompt({
                title: 'Unarchive',
                label: 'Extract into directory',
                defaultValue: currentPath,
                placeholder: '/',
                confirmButtonText: 'Extract',
                validate: (v) => {
                    const s = String(v || '').trim();
                    if (!s.startsWith('/')) return 'Path must start with /';
                    return null;
                },
            });
            if (!into) return;

            await request('unarchive', { path: item.path, dest_dir: into });
            clearSelection();
            await refresh();
            showToast('success', 'Unarchived');
        }

        async function doDownload() {
            const list = getSelectedList();
            if (list.length !== 1 || list[0].type !== 'file') return showToast('error', 'Select exactly one file');
            await request('download', { path: list[0].path });
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
                    if (!initialPathFromUrl) {
                        currentPath = res.default_path || '/';
                    }
                    if (Array.isArray(res.containers)) {
                        containers = res.containers;
                        renderContainerList(containers);
                        if (res.selected_container_id) {
                            setSelectedContainer(res.selected_container_id);
                        }
                    }
                    navigate(currentPath).catch((e) => {
                        showToast('error', e && e.message ? e.message : 'Failed to load directory');
                    });
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
                clearSelection();
                syncUrlState();
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
        if (manualLocationBtn) {
            manualLocationBtn.addEventListener('click', async () => {
                const path = await chapPrompt({
                    title: 'Manual location',
                    label: 'Directory path',
                    defaultValue: currentPath,
                    placeholder: '/',
                    confirmButtonText: 'Go',
                    validate: (v) => {
                        const s = String(v || '').trim();
                        if (!s.startsWith('/')) return 'Path must start with /';
                        return null;
                    },
                });
                if (!path) return;
                navigate(path);
            });
        }
        refreshBtn.addEventListener('click', () => refresh());

        if (selectAllCb) {
            selectAllCb.addEventListener('click', (ev) => {
                // Avoid row click interactions (header is not a row, but keep consistent)
                ev.stopPropagation();
            });
            selectAllCb.addEventListener('change', () => {
                const want = !!selectAllCb.checked;
                for (const [path, ref] of visibleRowRefs.entries()) {
                    if (!ref || !ref.item) continue;
                    ref.cb.checked = want;
                    toggleSelection(ref.item, want, ref.tr);
                }
                updateSelectAllState();
            });
        }

        uploadBtn.addEventListener('click', () => uploadInput.click());
        uploadInput.addEventListener('change', () => {
            uploadFiles(uploadInput.files);
            uploadInput.value = '';
        });

        newFolderBtn.addEventListener('click', doMkdir);
        newFileBtn.addEventListener('click', doNewFile);
        renameBtn.addEventListener('click', doRename);
        moveBtn.addEventListener('click', doMove);
        if (copyBtn) copyBtn.addEventListener('click', doCopy);
        deleteBtn.addEventListener('click', doDelete);
        downloadBtn.addEventListener('click', doDownload);
        archiveBtn.addEventListener('click', doArchive);
        unarchiveBtn.addEventListener('click', doUnarchive);

        // File editing is now on /applications/{uuid}/files/edit

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
