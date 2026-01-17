(() => {
  const root = document.getElementById('volume-file-editor');
  if (!root) return;

  const cfg = {
    wsUrl: root.dataset.wsUrl,
    sessionId: root.dataset.sessionId,
    (() => {
      const root = document.getElementById('volume-file-editor');
      if (!root) return;

      const wsUrl = root.dataset.wsUrl;
      const sessionId = root.dataset.sessionId;
      const applicationUuid = root.dataset.applicationUuid;
      const volumeName = root.dataset.volumeName;
      const initialPath = root.dataset.path || '/';

      const statusEl = document.getElementById('fe-status');
      const metaEl = document.getElementById('fe-meta');
      const pathEl = document.getElementById('fe-path');
      const textareaEl = document.getElementById('fe-textarea');
      const reloadBtn = document.getElementById('fe-reload');
      const saveBtn = document.getElementById('fe-save');
      const languageSelect = document.getElementById('fe-language');

      const makeId = () => (crypto?.randomUUID ? crypto.randomUUID() : (Math.random().toString(16).slice(2) + Date.now().toString(16)));
      const clamp = (n, min, max) => Math.max(min, Math.min(max, n));

      const setStatus = (text, kind = 'default') => {
        if (!statusEl) return;
        statusEl.textContent = text;
        statusEl.className = `badge badge-${kind}`;
      };

      const guessModeFromPath = (path) => {
        const fileName = (path || '').split('/').filter(Boolean).pop() || '';
        if (fileName === 'Dockerfile') return 'text/x-dockerfile';
        if (fileName.endsWith('.ts')) return 'application/typescript';
        if (fileName.endsWith('.js') || fileName.endsWith('.mjs') || fileName.endsWith('.cjs')) return 'text/javascript';
        if (fileName.endsWith('.json')) return 'application/json';
        if (fileName.endsWith('.php')) return 'application/x-httpd-php';
        if (fileName.endsWith('.sql')) return 'text/x-sql';
        if (fileName.endsWith('.py')) return 'text/x-python';
        if (fileName.endsWith('.yml') || fileName.endsWith('.yaml')) return 'text/x-yaml';
        if (fileName.endsWith('.toml')) return 'text/x-toml';
        if (fileName.endsWith('.md')) return 'text/x-markdown';
        if (fileName.endsWith('.css')) return 'text/css';
        if (fileName.endsWith('.html') || fileName.endsWith('.htm')) return 'text/html';
        if (fileName.endsWith('.sh') || fileName.endsWith('.bash')) return 'text/x-sh';
        if (fileName.endsWith('.conf')) return 'text/x-nginx-conf';
        return '';
      };

      const getTheme = () => {
        const rootEl = document.documentElement;
        const theme = rootEl.getAttribute('data-theme');
        return theme === 'dark' ? 'material-darker' : 'default';
      };

      let ws;
      let editor;
      let pending = {};
      let fileContent = '';
      let currentPath = initialPath;
      let authed = false;

      const send = (obj) => {
        if (!ws || ws.readyState !== WebSocket.OPEN) return;
        ws.send(JSON.stringify(obj));
      };

      const request = (action, payload, timeoutMs = 20000) => new Promise((resolve, reject) => {
        const requestId = makeId();
        pending[requestId] = { resolve, reject, ts: Date.now() };
        send({
          type: 'volumes:request',
          request_id: requestId,
          action,
          payload,
        });
        window.setTimeout(() => {
          if (!pending[requestId]) return;
          delete pending[requestId];
          reject({ ok: false, error: 'Timed out' });
        }, clamp(timeoutMs, 5000, 60000));
      });

      const updateMeta = (bytes, updatedAt) => {
        if (!metaEl) return;
        const parts = [];
        if (typeof bytes === 'number') parts.push(`${bytes.toLocaleString()} bytes`);
        if (updatedAt) parts.push(updatedAt);
        parts.push(`Volume: ${volumeName}`);
        metaEl.textContent = parts.filter(Boolean).join(' • ');
      };

      const applyContentToEditor = (content) => {
        fileContent = content || '';
        if (editor) editor.setValue(fileContent);
        else if (textareaEl) textareaEl.value = fileContent;
      };

      const getEditorValue = () => (editor ? editor.getValue() : (textareaEl?.value ?? ''));

      const isDirty = () => getEditorValue() !== fileContent;

      const updatePathLabel = () => {
        if (pathEl) pathEl.textContent = currentPath;
      };

      const setMode = (mode) => {
        if (!editor) return;
        if (!mode) {
          editor.setOption('mode', null);
          return;
        }
        // Load mode on-demand if possible
        if (window.CodeMirror?.autoLoadMode) {
          const info = window.CodeMirror.findModeByMIME(mode) || window.CodeMirror.findModeByExtension(mode);
          if (info?.mode) {
            window.CodeMirror.autoLoadMode(editor, info.mode);
            editor.setOption('mode', info.mime);
            return;
          }
        }
        editor.setOption('mode', mode);
      };

      const initCodeMirror = () => {
        if (!textareaEl || !window.CodeMirror) return;
        window.CodeMirror.modeURL = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/%N/%N.min.js';
        editor = window.CodeMirror.fromTextArea(textareaEl, {
          lineNumbers: true,
          lineWrapping: true,
          theme: getTheme(),
          viewportMargin: Infinity,
        });

        if (languageSelect) {
          const initialMode = guessModeFromPath(currentPath);
          languageSelect.value = initialMode;
          setMode(initialMode);
          languageSelect.addEventListener('change', () => setMode(languageSelect.value));
        }

        document.addEventListener('themechange', () => {
          if (!editor) return;
          editor.setOption('theme', getTheme());
        });
      };

      const loadFile = async () => {
        if (!authed) return;
        setStatus('Loading…', 'default');
        updatePathLabel();
        try {
          const res = await request('fs:read', { name: volumeName, path: currentPath });
          const content = res.content ?? '';
          applyContentToEditor(content);
          updateMeta(typeof res.bytes === 'number' ? res.bytes : (content.length), res.updated_at);
          setStatus('Loaded', 'success');

          if (languageSelect && !languageSelect.value) {
            const guess = guessModeFromPath(currentPath);
            languageSelect.value = guess;
            setMode(guess);
          }
        } catch (e) {
          setStatus('Load failed', 'danger');
        }
      };

      const saveFile = async () => {
        if (!authed) return;
        if (!isDirty()) {
          setStatus('No changes', 'default');
          return;
        }
        setStatus('Saving…', 'default');
        try {
          const content = getEditorValue();
          await request('fs:write', { name: volumeName, path: currentPath, content });
          fileContent = content;
          setStatus('Saved', 'success');
          updateMeta(content.length, new Date().toLocaleString());
        } catch (e) {
          setStatus('Save failed', 'danger');
        }
      };

      const onMessage = (event) => {
        let msg;
        try { msg = JSON.parse(event.data); } catch { return; }

        if (msg.type === 'auth:ok') {
          authed = true;
          setStatus('Connected', 'success');
          updatePathLabel();
          loadFile();
          return;
        }

        if (msg.type === 'auth:error') {
          setStatus('Unauthorized', 'danger');
          return;
        }

        if (msg.type === 'volumes:response' && msg.request_id && pending[msg.request_id]) {
          const { resolve, reject } = pending[msg.request_id];
          delete pending[msg.request_id];
          if (msg.ok) resolve(msg);
          else reject(msg);
        }
      };

      const connect = () => {
        setStatus('Connecting…', 'default');
        authed = false;
        pending = {};
        ws = new WebSocket(wsUrl);

        ws.addEventListener('open', () => {
          setStatus('Authorizing…', 'default');
          if (metaEl) metaEl.textContent = `Volume: ${volumeName}`;
          updatePathLabel();
          send({
            type: 'auth',
            session_id: sessionId,
            application_uuid: applicationUuid,
          });
        });
        ws.addEventListener('message', onMessage);
        ws.addEventListener('close', () => {
          setStatus('Disconnected', 'danger');
          window.setTimeout(connect, 1000);
        });
        ws.addEventListener('error', () => {
          setStatus('Error', 'danger');
        });
      };

      // UI wires
      reloadBtn?.addEventListener('click', () => {
        if (isDirty() && !confirm('Discard unsaved changes and reload?')) return;
        loadFile();
      });
      saveBtn?.addEventListener('click', () => saveFile());

      window.addEventListener('beforeunload', (e) => {
        if (!isDirty()) return;
        e.preventDefault();
        e.returnValue = '';
      });

      initCodeMirror();
      applyContentToEditor('');
      connect();
    })();
      state.authenticated = false;
      setStatus('Disconnected');
    });

    state.ws.addEventListener('error', () => {
      setStatus('WebSocket error');
    });
  }

  els.save?.addEventListener('click', () => {
    saveFile().catch((e) => {
      console.error(e);
      alert(e.message || 'Error');
    });
  });

  els.reload?.addEventListener('click', () => {
    loadFile().catch((e) => {
      console.error(e);
      alert(e.message || 'Error');
    });
  });

  attachWs();
})();
