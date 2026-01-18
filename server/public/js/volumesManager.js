(function () {
    'use strict';

    function qs(sel, root) { return (root || document).querySelector(sel); }

    function showToast(type, message) {
        if (window.Chap && window.Chap.toast && typeof window.Chap.toast[type] === 'function') {
            window.Chap.toast[type](message);
            return;
        }
        alert(message);
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

    function generateId(prefix) {
        return `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
    }

    function base64ToUint8Array(b64) {
        const binary = atob(b64);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
        return bytes;
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

    function triggerDownload(blob, filename) {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    function init() {
        const root = qs('#volumes-manager');
        if (!root) return;

        const wsUrl = root.dataset.wsUrl;
        const sessionId = root.dataset.sessionId;
        const appUuid = root.dataset.applicationUuid;

        function friendlyResourceName(name) {
            const n = String(name || '');
            const app = String(appUuid || '');
            if (!n) return n;

            // Prefer stripping the app UUID segment if present.
            if (app) {
                const idx = n.indexOf(app);
                if (idx >= 0) {
                    const cut = idx + app.length;
                    const next = n[cut];
                    if (next === '-' || next === '_') return n.slice(cut + 1);
                    return n.slice(cut);
                }
            }

            // Generic chap prefix.
            if (n.startsWith('chap-') && n.length > 5) return n.slice(5);
            if (n.startsWith('chap_') && n.length > 5) return n.slice(5);
            return n;
        }

        const statusEl = qs('#vm-status');
        const summaryEl = qs('#vm-summary');
        const progressEl = qs('#vm-progress');
        const cancelBtn = qs('#vm-cancel');
        const refreshBtn = qs('#vm-refresh');
        const downloadBtn = qs('#vm-download');
        const deleteBtn = qs('#vm-delete');
        const rowsEl = qs('#vm-rows');
        const selectAllCb = qs('#vm-select-all');
        const replaceInput = qs('#vm-replace-input');

        let ws = null;
        let authenticated = false;
        const pending = new Map();

        const downloads = new Map(); // transferId -> { name, chunks: Uint8Array[], received }
        const downloadMeta = new Map(); // transferId -> { approxTotalBytes: number, sentBytes: number }
        const downloadWaiters = new Map(); // transferId -> { resolve, reject }
        const downloadSettled = new Map(); // transferId -> { ok: boolean, error?: string }
        let activeDownloadTransferId = null;
        let bulkDownloadCtx = null; // { index, total }
        const selectedNames = new Set();
        let volumes = [];

        let replaceTargetName = null;

        let activeCancel = null;

        function setCancelable(text, onCancel) {
            setProgress(text);
            activeCancel = typeof onCancel === 'function' ? onCancel : null;
            if (!cancelBtn) return;
            const show = !!activeCancel && !!text;
            cancelBtn.classList.toggle('hidden', !show);
            cancelBtn.disabled = !show;
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', async () => {
                if (!activeCancel) return;
                try { await activeCancel(); } catch {}
            });
        }

        function setProgress(text) {
            if (!progressEl) return;
            progressEl.textContent = text || '';
        }

        function fmtBytes(bytes) {
            if (!Number.isFinite(bytes)) return '';
            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            let v = bytes;
            let i = 0;
            while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
            return `${v.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
        }

        function setStatus(text, cls) {
            if (!statusEl) return;
            statusEl.textContent = text;
            statusEl.className = `badge ${cls || 'badge-default'}`;
        }

        function setSummary(text) {
            if (summaryEl) summaryEl.textContent = text || '';
        }

        function selectedSingle() {
            if (selectedNames.size !== 1) return null;
            const name = Array.from(selectedNames)[0];
            return volumes.find(v => v.name === name) || null;
        }

        function updateButtons() {
            const enabled = selectedNames.size > 0;
            downloadBtn.disabled = !enabled;
            deleteBtn.disabled = !enabled;
        }

        function filesystemUrlFor(name) {
            return `/applications/${encodeURIComponent(appUuid)}/volumes/${encodeURIComponent(name)}/files`;
        }

        function mkRowDropdown(itemsBuilder) {
            const dropdownId = generateId('vm_row_menu');
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

            const addItem = (label, onClick, danger) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'dropdown-item';
                b.textContent = label;
                if (danger) b.classList.add('text-danger');
                b.addEventListener('click', (ev) => {
                    ev.preventDefault();
                    ev.stopPropagation();
                    Promise.resolve(onClick()).catch((err) => {
                        showToast('error', err && err.message ? err.message : 'Action failed');
                    });
                });
                items.appendChild(b);
                return b;
            };

            itemsBuilder(addItem);
            menu.appendChild(items);
            dd.appendChild(trigger);
            dd.appendChild(menu);
            return dd;
        }

        function downloadProgressLabel(suffix) {
            if (bulkDownloadCtx && bulkDownloadCtx.total > 1) {
                return `Downloading ${bulkDownloadCtx.index}/${bulkDownloadCtx.total} — ${suffix}`;
            }
            return `Downloading… ${suffix}`;
        }

        function waitForDownload(transferId) {
            const settled = downloadSettled.get(transferId);
            if (settled) {
                downloadSettled.delete(transferId);
                return settled.ok ? Promise.resolve(true) : Promise.reject(new Error(settled.error || 'cancelled'));
            }
            return new Promise((resolve, reject) => {
                downloadWaiters.set(transferId, { resolve, reject });
            });
        }

        function render() {
            rowsEl.innerHTML = '';
            const frag = document.createDocumentFragment();

            for (const v of volumes) {
                const tr = document.createElement('tr');
                tr.className = 'vm-row';
                tr.dataset.name = v.name;

                const cbTd = document.createElement('td');
                cbTd.className = 'vm-select-cell';
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.className = 'vm-checkbox';
                cb.checked = selectedNames.has(v.name);
                cb.addEventListener('click', (e) => {
                    e.stopPropagation();
                });
                cb.addEventListener('change', () => {
                    if (cb.checked) selectedNames.add(v.name);
                    else selectedNames.delete(v.name);
                    tr.classList.toggle('selected', cb.checked);
                    updateButtons();
                    updateSelectAll();
                });
                cbTd.appendChild(cb);

                const nameTd = document.createElement('td');
                nameTd.textContent = friendlyResourceName(v.name);
                nameTd.title = v.name;

                const typeTd = document.createElement('td');
                typeTd.textContent = v.type;

                const mountsTd = document.createElement('td');
                mountsTd.textContent = (v.mounts || []).join(', ');

                const usedTd = document.createElement('td');
                usedTd.textContent = (v.used_by || []).map(friendlyResourceName).join(', ');
                usedTd.title = (v.used_by || []).join(', ');

                const actionsTd = document.createElement('td');
                actionsTd.className = 'vm-row-actions';

                const dd = mkRowDropdown((mkItem) => {
                    mkItem('View', async () => {
                        window.location.href = filesystemUrlFor(v.name);
                    });
                    mkItem('Download', async () => {
                        await downloadVolumes([v.name]);
                    });
                    mkItem('Replace', async () => {
                        replaceTargetName = v.name;
                        replaceInput.click();
                    });
                    mkItem('Delete', async () => {
                        await deleteVolumes([v.name]);
                    }, true);
                });
                actionsTd.appendChild(dd);

                tr.appendChild(cbTd);
                tr.appendChild(nameTd);
                tr.appendChild(typeTd);
                tr.appendChild(mountsTd);
                tr.appendChild(usedTd);
                tr.appendChild(actionsTd);

                tr.addEventListener('click', (ev) => {
                    if (ev && ev.target && ev.target.closest) {
                        if (ev.target.closest('.vm-row-actions')) return;
                        if (ev.target.closest('.vm-select-cell')) return;
                        if (ev.target.closest('[data-dropdown-trigger]')) return;
                    }
                    window.location.href = filesystemUrlFor(v.name);
                });

                tr.classList.toggle('selected', selectedNames.has(v.name));
                frag.appendChild(tr);
            }

            rowsEl.appendChild(frag);
            setSummary(`${volumes.length} volume${volumes.length === 1 ? '' : 's'}`);
            updateButtons();
            updateSelectAll();
        }

        function updateSelectAll() {
            if (!selectAllCb) return;
            if (!volumes.length) {
                selectAllCb.checked = false;
                selectAllCb.indeterminate = false;
                return;
            }
            const total = volumes.length;
            const sel = selectedNames.size;
            selectAllCb.checked = sel === total;
            selectAllCb.indeterminate = sel > 0 && sel < total;
        }

        function selectOnly(name) {
            selectedNames.clear();
            selectedNames.add(name);
            updateButtons();
            updateSelectAll();
        }

        async function deleteVolumes(names) {
            const list = (names || []).filter(Boolean);
            if (!list.length) return;

            const label = list.length === 1 ? `volume “${list[0]}”` : `${list.length} volumes`;
            const ok = await chapConfirmDelete({
                title: 'Delete volume?',
                text: `This will permanently delete ${label}. This cannot be undone.`,
            });
            if (!ok) return;

            try {
                for (const name of list) {
                    await request('delete', { name });
                }
                showToast('success', list.length === 1 ? 'Volume deleted' : 'Volumes deleted');
                for (const name of list) selectedNames.delete(name);
                await refresh();
            } catch (e) {
                showToast('error', e.message || 'Delete failed');
            }
        }

        async function downloadVolumes(names) {
            const list = (names || []).filter(Boolean);
            if (!list.length) return;

            bulkDownloadCtx = { index: 0, total: list.length };
            try {
                for (let i = 0; i < list.length; i++) {
                    bulkDownloadCtx.index = i + 1;
                    const name = list[i];

                    const res = await request('download', { name });
                    if (!res || !res.transfer_id) throw new Error('Download did not start');
                    const tid = res.transfer_id;
                    activeDownloadTransferId = tid;
                    try {
                        await waitForDownload(tid);
                    } catch (e) {
                        const msg = e && e.message ? e.message : 'Download failed';
                        if (msg === 'cancelled') return;
                        throw e;
                    }
                }
                showToast('success', list.length === 1 ? 'Download ready' : 'Downloads ready');
            } finally {
                bulkDownloadCtx = null;
            }
        }

        function request(action, payload) {
            return new Promise((resolve, reject) => {
                if (!ws || ws.readyState !== WebSocket.OPEN) return reject(new Error('Not connected'));
                const requestId = generateId('vm');
                pending.set(requestId, { resolve, reject });
                ws.send(JSON.stringify({
                    type: 'volumes:request',
                    request_id: requestId,
                    action,
                    payload: payload || {},
                }));
            });
        }

        async function refresh() {
            try {
                const res = await request('list', {});
                volumes = Array.isArray(res.volumes) ? res.volumes : [];
                // Keep selection if still present
                for (const s of Array.from(selectedNames)) {
                    if (!volumes.find(v => v.name === s)) selectedNames.delete(s);
                }
                render();
            } catch (e) {
                showToast('error', e.message || 'Failed to load volumes');
            }
        }

        async function deleteSelected() {
            try {
                await deleteVolumes(Array.from(selectedNames));
            } catch (e) {
                showToast('error', e && e.message ? e.message : 'Delete failed');
            }
        }

        async function downloadSelected() {
            try {
                await downloadVolumes(Array.from(selectedNames));
            } catch (e) {
                const msg = e && e.message ? e.message : 'Download failed';
                if (msg !== 'cancelled') showToast('error', msg);
            }
        }

        async function replaceSelectedWithFile(file) {
            const targetName = replaceTargetName || (selectedSingle() ? selectedSingle().name : null);
            if (!targetName) return;
            if (!file) return;

            const ok = await chapConfirm({
                title: 'Replace volume?',
                text: `This will wipe volume “${targetName}” then restore from the selected archive.`,
                confirmButtonText: 'Replace',
            });
            if (!ok) return;

            try {
                const initRes = await request('replace:init', { name: targetName, size: file.size, filename: file.name });
                const transferId = initRes.transfer_id;
                const chunkSize = initRes.chunk_size || (256 * 1024);
                if (!transferId) throw new Error('Replace did not start');

                let cancelled = false;
                setCancelable('Uploading… 0%', async () => {
                    cancelled = true;
                    try { await request('replace:cancel', { transfer_id: transferId }); } catch {}
                    showToast('info', 'Upload cancelled');
                    setCancelable('', null);
                });

                let offset = 0;
                while (offset < file.size) {
                    if (cancelled) break;
                    const slice = file.slice(offset, offset + chunkSize);
                    const buf = await slice.arrayBuffer();
                    const b64 = arrayBufferToBase64(buf);
                    await request('replace:chunk', { transfer_id: transferId, offset, data_b64: b64 });
                    offset += slice.size;

                    const pct = Math.floor((offset / file.size) * 100);
                    setCancelable(`Uploading… ${Math.min(100, Math.max(0, pct))}%`, activeCancel);
                }

                if (cancelled) return;

                await request('replace:commit', { transfer_id: transferId });
                showToast('success', 'Volume replaced');
                setCancelable('', null);
                await refresh();
            } catch (e) {
                showToast('error', e.message || 'Replace failed');
                setCancelable('', null);
            } finally {
                try { replaceInput.value = ''; } catch {}
                replaceTargetName = null;
            }
        }

        function connect() {
            if (!wsUrl) {
                setStatus('Missing WebSocket URL', 'badge-danger');
                return;
            }

            setStatus('Connecting…', 'badge-default');
            ws = new WebSocket(wsUrl);

            ws.addEventListener('open', () => {
                ws.send(JSON.stringify({ type: 'auth', session_id: sessionId, application_uuid: appUuid }));
            });

            ws.addEventListener('message', async (ev) => {
                let msg;
                try { msg = JSON.parse(ev.data); } catch { return; }

                if (msg.type === 'auth:success') {
                    authenticated = true;
                    setStatus('Connected', 'badge-success');
                    await refresh();
                    return;
                }

                if (msg.type === 'auth:failed') {
                    setStatus('Auth failed', 'badge-danger');
                    showToast('error', msg.error || 'Authentication failed');
                    return;
                }

                if (msg.type === 'volumes:response') {
                    const reqId = msg.request_id;
                    const p = pending.get(reqId);
                    if (!p) return;
                    pending.delete(reqId);
                    if (msg.ok) p.resolve(msg.result || {});
                    else p.reject(new Error(msg.error || 'Request failed'));
                    return;
                }

                if (msg.type === 'volumes:download:start') {
                    downloads.set(msg.transfer_id, { name: msg.name || 'volume.tar.gz', chunks: [], received: 0 });
                    downloadMeta.set(msg.transfer_id, {
                        approxTotalBytes: Number.isFinite(msg.approx_total_bytes) ? msg.approx_total_bytes : 0,
                        sentBytes: 0,
                    });
                    const tid = msg.transfer_id;
                    activeDownloadTransferId = tid;
                    setCancelable(downloadProgressLabel('0%'), async () => {
                        try { await request('download:cancel', { transfer_id: tid }); } catch {}
                        downloads.delete(tid);
                        downloadMeta.delete(tid);
                        const w = downloadWaiters.get(tid);
                        if (w) {
                            downloadWaiters.delete(tid);
                            w.reject(new Error('cancelled'));
                        } else {
                            downloadSettled.set(tid, { ok: false, error: 'cancelled' });
                        }
                        setCancelable('', null);
                        showToast('info', 'Download cancelled');
                    });
                    return;
                }

                if (msg.type === 'volumes:download:chunk') {
                    const t = downloads.get(msg.transfer_id);
                    if (!t) return;
                    const bytes = base64ToUint8Array(msg.data_b64 || '');
                    t.chunks.push(bytes);
                    t.received += bytes.length;

                    const meta = downloadMeta.get(msg.transfer_id);
                    if (meta) {
                        const sent = Number.isFinite(msg.sent_bytes) ? msg.sent_bytes : t.received;
                        meta.sentBytes = sent;
                        const total = meta.approxTotalBytes;
                        if (total && total > 0) {
                            const pct = Math.floor((sent / total) * 100);
                            setCancelable(downloadProgressLabel(`${Math.min(99, Math.max(0, pct))}%`), activeCancel);
                        } else {
                            setCancelable(downloadProgressLabel(fmtBytes(sent)), activeCancel);
                        }
                    }
                    return;
                }

                if (msg.type === 'volumes:download:done') {
                    const t2 = downloads.get(msg.transfer_id);
                    if (!t2) return;
                    downloads.delete(msg.transfer_id);
                    downloadMeta.delete(msg.transfer_id);
                    const blob = new Blob(t2.chunks, { type: 'application/gzip' });
                    triggerDownload(blob, msg.name || t2.name || 'volume.tar.gz');
                    const w = downloadWaiters.get(msg.transfer_id);
                    if (w) {
                        downloadWaiters.delete(msg.transfer_id);
                        w.resolve(true);
                    } else {
                        downloadSettled.set(msg.transfer_id, { ok: true });
                    }
                    setCancelable('', null);
                    if (activeDownloadTransferId === msg.transfer_id) activeDownloadTransferId = null;
                    return;
                }

                if (msg.type === 'volumes:download:cancelled') {
                    downloads.delete(msg.transfer_id);
                    downloadMeta.delete(msg.transfer_id);
                    const w = downloadWaiters.get(msg.transfer_id);
                    if (w) {
                        downloadWaiters.delete(msg.transfer_id);
                        w.reject(new Error('cancelled'));
                    } else {
                        downloadSettled.set(msg.transfer_id, { ok: false, error: 'cancelled' });
                    }
                    setCancelable('', null);
                    if (activeDownloadTransferId === msg.transfer_id) activeDownloadTransferId = null;
                    return;
                }

                if (msg.type === 'volumes:replace:cancelled') {
                    setCancelable('', null);
                    return;
                }

                if (msg.type === 'error' && msg.error) {
                    showToast('error', msg.error);
                    // If an error happens mid-transfer, clear progress.
                    setCancelable('', null);

                    if (activeDownloadTransferId) {
                        const w = downloadWaiters.get(activeDownloadTransferId);
                        if (w) {
                            downloadWaiters.delete(activeDownloadTransferId);
                            w.reject(new Error(msg.error));
                        } else {
                            downloadSettled.set(activeDownloadTransferId, { ok: false, error: msg.error });
                        }
                        activeDownloadTransferId = null;
                    }
                }
            });

            ws.addEventListener('close', () => {
                authenticated = false;
                setStatus('Disconnected', 'badge-danger');
            });

            ws.addEventListener('error', () => {
                authenticated = false;
                setStatus('Disconnected', 'badge-danger');
            });
        }

        refreshBtn.addEventListener('click', () => refresh());

        deleteBtn.addEventListener('click', () => deleteSelected());
        downloadBtn.addEventListener('click', () => downloadSelected());
        replaceInput.addEventListener('change', () => {
            const f = (replaceInput.files && replaceInput.files[0]) ? replaceInput.files[0] : null;
            if (!f) return;
            replaceSelectedWithFile(f);
        });

        selectAllCb.addEventListener('change', () => {
            if (selectAllCb.checked) {
                selectedNames.clear();
                for (const v of volumes) selectedNames.add(v.name);
            } else {
                selectedNames.clear();
            }
            render();
            updateButtons();
            updateSelectAll();
        });

        connect();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
