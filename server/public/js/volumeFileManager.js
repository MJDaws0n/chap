(() => {
  const root = document.getElementById('volume-file-manager');
  if (!root) return;

  const cfg = {
    wsUrl: root.dataset.wsUrl,
    sessionId: root.dataset.sessionId,
    appUuid: root.dataset.applicationUuid,
    volumeName: root.dataset.volumeName
  };

  const els = {
    status: document.getElementById('vfm-status'),
    pathLabel: document.getElementById('vfm-path-label'),
    path: document.getElementById('vfm-path'),
    go: document.getElementById('vfm-go'),
    up: document.getElementById('vfm-up'),
    refresh: document.getElementById('vfm-refresh'),
    newFile: document.getElementById('vfm-new-file'),
    newFolder: document.getElementById('vfm-new-folder'),
    rows: document.getElementById('vfm-rows'),
    empty: document.getElementById('vfm-empty')
  };

  const state = {
    ws: null,
    authenticated: false,
    pending: new Map(),
    currentPath: '/'
  };

  function joinPath(dir, name) {
    const base = (dir || '/').replace(/\/+$/, '') || '/';
    const child = (name || '').replace(/^\/+/, '');
    if (base === '/') return '/' + child;
    return base + '/' + child;
  }

  function dirname(p) {
    if (!p || p === '/') return '/';
    const cleaned = p.replace(/\/+$/, '');
    const idx = cleaned.lastIndexOf('/');
    if (idx <= 0) return '/';
    return cleaned.slice(0, idx);
  }

  function fmtSize(n) {
    if (n == null) return '';
    const num = Number(n);
    if (!Number.isFinite(num)) return '';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let u = 0;
    let v = num;
    while (v >= 1024 && u < units.length - 1) {
      v /= 1024;
      u++;
    }
    return (u === 0 ? v.toFixed(0) : v.toFixed(1)) + ' ' + units[u];
  }

  function setStatus(text) {
    if (els.status) {
      els.status.textContent = text;
      els.status.className = `badge ${text === 'Connected' || text === 'Ready' ? 'badge-success' : (text === 'Disconnected' ? 'badge-danger' : 'badge-default')}`;
    }
  }

  function request(action, payload) {
    return new Promise((resolve, reject) => {
      if (!state.ws || state.ws.readyState !== WebSocket.OPEN) return reject(new Error('Not connected'));
      if (!state.authenticated) return reject(new Error('Not authenticated'));
      const requestId = `vfm_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
      state.pending.set(requestId, { resolve, reject });
      state.ws.send(JSON.stringify({
        type: 'volumes:request',
        request_id: requestId,
        action,
        payload: payload || {},
      }));
    });
  }

  function renderRows(entries) {
    els.rows.innerHTML = '';

    if (!entries || entries.length === 0) {
      if (els.empty) els.empty.style.display = '';
      return;
    }
    if (els.empty) els.empty.style.display = 'none';

    for (const e of entries) {
      const tr = document.createElement('tr');

      const nameTd = document.createElement('td');
      const nameBtn = document.createElement('button');
      nameBtn.type = 'button';
      nameBtn.className = 'btn btn-ghost btn-sm';
      nameBtn.style.paddingLeft = '0';
      nameBtn.textContent = e.name;
      nameBtn.addEventListener('click', () => {
        if (e.type === 'dir') {
          navigate(joinPath(state.currentPath, e.name));
        } else {
          const path = joinPath(state.currentPath, e.name);
          const url = new URL(window.location.href);
          url.pathname = `/applications/${encodeURIComponent(cfg.appUuid)}/volumes/${encodeURIComponent(cfg.volumeName)}/files/edit`;
          url.searchParams.set('path', path);
          url.searchParams.set('dir', state.currentPath);
          window.location.href = url.toString();
        }
      });
      nameTd.appendChild(nameBtn);

      const sizeTd = document.createElement('td');
      sizeTd.className = 'text-end text-muted';
      sizeTd.textContent = e.type === 'file' ? fmtSize(e.size) : '';

      const mtimeTd = document.createElement('td');
      mtimeTd.className = 'text-muted';
      mtimeTd.textContent = e.mtime || '';

      const actionsTd = document.createElement('td');
      actionsTd.className = '';

      if (e.type === 'file') {
        const editA = document.createElement('a');
        editA.className = 'btn btn-ghost btn-sm';
        editA.textContent = 'Edit';
        const path = joinPath(state.currentPath, e.name);
        const url = new URL(window.location.href);
        url.pathname = `/applications/${encodeURIComponent(cfg.appUuid)}/volumes/${encodeURIComponent(cfg.volumeName)}/files/edit`;
        url.searchParams.set('path', path);
        url.searchParams.set('dir', state.currentPath);
        editA.href = url.toString();
        actionsTd.appendChild(editA);
      }

      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'btn btn-ghost btn-sm';
      delBtn.textContent = 'Delete';
      delBtn.addEventListener('click', (evt) => {
        evt.stopPropagation();
        const targetPath = joinPath(state.currentPath, e.name);
        if (!confirm(`Delete ${e.type === 'dir' ? 'folder' : 'file'} ${targetPath}?`)) return;
        setStatus('Working…');
        request('fs:delete', { name: cfg.volumeName, path: targetPath, type: e.type }).then(() => navigate(state.currentPath)).catch((err) => {
          console.error(err);
          alert(err.message || 'Delete failed');
          setStatus('Ready');
        });
      });
      actionsTd.appendChild(delBtn);

      tr.appendChild(nameTd);
      tr.appendChild(sizeTd);
      tr.appendChild(mtimeTd);
      tr.appendChild(actionsTd);
      els.rows.appendChild(tr);
    }
  }

  async function list(path) {
    setStatus('Loading…');
    if (els.pathLabel) els.pathLabel.textContent = path;
    const res = await request('fs:list', { name: cfg.volumeName, path });
    renderRows(res.entries || []);
    setStatus('Ready');
  }

  async function createFile(path) {
    const name = prompt('New file name');
    if (!name) return;
    setStatus('Creating…');
    await request('fs:touch', { name: cfg.volumeName, dir: path, entry: name });
    await list(state.currentPath);
  }

  async function createFolder(path) {
    const name = prompt('New folder name');
    if (!name) return;
    setStatus('Creating…');
    await request('fs:mkdir', { name: cfg.volumeName, dir: path, entry: name });
    await list(state.currentPath);
  }

  async function navigate(path) {
    state.currentPath = path || '/';
    if (els.path) els.path.value = state.currentPath;
    await list(state.currentPath);
  }

  function attachWs() {
    if (!cfg.wsUrl) {
      setStatus('Missing WebSocket URL');
      return;
    }
    state.ws = new WebSocket(cfg.wsUrl);

    state.ws.addEventListener('open', async () => {
      setStatus('Connecting…');
      state.ws.send(JSON.stringify({ type: 'auth', session_id: cfg.sessionId, application_uuid: cfg.appUuid, tail: 0 }));
    });

    state.ws.addEventListener('message', (evt) => {
      let msg;
      try {
        msg = JSON.parse(evt.data);
      } catch {
        return;
      }
      if (!msg || typeof msg !== 'object') return;

      if (msg.type === 'auth:success') {
        state.authenticated = true;
        setStatus('Connected');
        const urlParams = new URLSearchParams(window.location.search);
        const initial = urlParams.get('path') || '/';
        navigate(initial).catch((e) => {
          console.error(e);
          setStatus(e.message || 'Error');
        });
        return;
      }

      if (msg.type === 'auth:failed') {
        setStatus('Auth failed');
        alert(msg.error || 'Authentication failed');
        return;
      }

      if (msg.type === 'volumes:response') {
        const reqId = msg.request_id;
        const p = state.pending.get(reqId);
        if (!p) return;
        state.pending.delete(reqId);
        if (msg.ok) p.resolve(msg.result || {});
        else p.reject(new Error(msg.error || 'Request failed'));
      }
    });

    state.ws.addEventListener('close', () => {
      state.authenticated = false;
      setStatus('Disconnected');
    });

    state.ws.addEventListener('error', () => {
      setStatus('WebSocket error');
    });
  }

  // UI events
  els.go?.addEventListener('click', () => {
    navigate(els.path.value || '/').catch((e) => {
      console.error(e);
      alert(e.message || 'Error');
    });
  });

  els.path?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      els.go?.click();
    }
  });

  els.up?.addEventListener('click', () => {
    navigate(dirname(state.currentPath)).catch((e) => {
      console.error(e);
      alert(e.message || 'Error');
    });
  });

  els.refresh?.addEventListener('click', () => {
    navigate(state.currentPath).catch((e) => {
      console.error(e);
      alert(e.message || 'Error');
    });
  });

  els.newFile?.addEventListener('click', () => {
    createFile(state.currentPath).catch((e) => {
      console.error(e);
      alert(e.message || 'Error');
    });
  });

  els.newFolder?.addEventListener('click', () => {
    createFolder(state.currentPath).catch((e) => {
      console.error(e);
      alert(e.message || 'Error');
    });
  });

  attachWs();
})();
