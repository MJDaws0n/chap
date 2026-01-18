(function () {
  'use strict';

  function qs(sel, root) { return (root || document).querySelector(sel); }

  function getCsrfToken(form) {
    const el = form ? form.querySelector('input[name="_csrf_token"]') : null;
    return el ? String(el.value || '') : '';
  }

  function showToast(type, message) {
    if (window.Chap && window.Chap.toast && typeof window.Chap.toast[type] === 'function') {
      window.Chap.toast[type](message);
      return;
    }
    if (type === 'error') alert(message);
  }

  async function runChapPrompt(prompt) {
    if (!prompt || !window.Chap || !window.Chap.modal) {
      throw new Error('Prompt requested but modal system unavailable');
    }

    const variant = (prompt.confirm && prompt.confirm.variant) ? String(prompt.confirm.variant) : 'neutral';
    const modalType = (variant === 'danger') ? 'danger' : (variant === 'success') ? 'success' : 'confirm';

    function safePromptHtml(desc, links) {
      const d = escapeHtml(String(desc || ''));
      const ls = Array.isArray(links) ? links : [];
      const items = ls
        .map((l) => {
          const label = escapeHtml(l && l.label ? String(l.label) : 'Link');
          const url = l && l.url ? String(l.url) : '';
          // basic client-side safety; server already validates.
          if (!/^https?:\/\//i.test(url)) return '';
          const href = escapeHtml(url);
          return `<li><a href="${href}" target="_blank" rel="noopener noreferrer">${label}</a></li>`;
        })
        .filter(Boolean)
        .join('');
      if (!items) return `<p class="modal-message">${d}</p>`;
      return `<p class="modal-message">${d}</p><ul class="text-sm" style="margin: 0.5rem 0 0 1.25rem;">${items}</ul>`;
    }

    if (String(prompt.type) === 'confirm') {
      const res = await window.Chap.modal.show({
        type: modalType,
        title: String(prompt.title || 'Confirm'),
        html: safePromptHtml(prompt.description, prompt.links),
        showCancel: true,
        confirmText: (prompt.confirm && prompt.confirm.text) ? String(prompt.confirm.text) : 'Confirm',
        cancelText: (prompt.cancel && prompt.cancel.text) ? String(prompt.cancel.text) : 'Cancel',
      });
      return { confirmed: !!(res && res.confirmed) };
    }

    if (String(prompt.type) === 'value') {
      const input = prompt.input || {};
      const inputType = (input.type === 'number' || input.type === 'select') ? input.type : 'text';

      const res = await window.Chap.modal.show({
        type: 'info',
        title: String(prompt.title || 'Enter a value'),
        html: safePromptHtml(prompt.description, prompt.links),
        showCancel: true,
        confirmText: (prompt.confirm && prompt.confirm.text) ? String(prompt.confirm.text) : 'Submit',
        cancelText: (prompt.cancel && prompt.cancel.text) ? String(prompt.cancel.text) : 'Cancel',
        input: {
          type: inputType === 'number' ? 'number' : (inputType === 'select' ? 'select' : 'text'),
          placeholder: String(input.placeholder || ''),
          value: (input.default !== undefined && input.default !== null) ? String(input.default) : '',
          required: input.required !== false,
          options: Array.isArray(input.options) ? input.options : [],
        },
      });

      if (!res || !res.confirmed) {
        return { value: null };
      }
      return { value: res.value };
    }

    throw new Error('Unknown prompt type');
  }

  function escapeHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function getLogClass(message) {
    const lower = String(message || '').toLowerCase();
    if (lower.includes('error') || lower.includes('failed')) return 'log-error';
    if (lower.includes('warning') || lower.includes('warn')) return 'log-warning';
    if (lower.includes('success') || lower.includes('completed')) return 'log-success';
    return 'log-info';
  }

  function init() {
    const deployForm = qs('form[data-inline-deploy]');
    if (!deployForm) return;

    const logsWrap = qs('#container-logs-wrap');
    const deployWrap = qs('#deployment-logs-wrap');
    const deployLogs = qs('#deployment-logs-container');
    const deployStatus = qs('#deployment-logs-status');

    if (!logsWrap || !deployWrap || !deployLogs) return;

    let poll = null;
    let lastCount = 0;

    function setMode(mode) {
      const isDeploy = mode === 'deploy';
      logsWrap.classList.toggle('hidden', isDeploy);
      deployWrap.classList.toggle('hidden', !isDeploy);

      // Hide exec bar while deploying.
      const execBar = qs('#logs-exec-wrap');
      if (execBar) execBar.classList.toggle('hidden', isDeploy);
    }

    function renderLogs(logs) {
      deployLogs.innerHTML = '';
      const frag = document.createDocumentFragment();

      (logs || []).forEach((log) => {
        const entry = document.createElement('div');
        entry.className = 'log-entry ' + getLogClass(log && log.message);

        const ts = document.createElement('span');
        ts.className = 'log-timestamp';
        ts.textContent = (log && log.timestamp) ? String(log.timestamp) : '';

        const msg = document.createElement('span');
        msg.className = 'log-message';
        msg.textContent = (log && log.message) ? String(log.message) : '';

        entry.appendChild(ts);
        entry.appendChild(msg);
        frag.appendChild(entry);
      });

      deployLogs.appendChild(frag);
      deployLogs.scrollTop = deployLogs.scrollHeight;
    }

    async function pollDeployment(deploymentUuid) {
      const url = `/deployments/${encodeURIComponent(deploymentUuid)}/logs`;

      async function tick() {
        try {
          const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
          const data = await res.json().catch(() => ({}));
          if (!res.ok) throw new Error((data && (data.error || data.message)) ? (data.error || data.message) : 'Failed to fetch logs');

          if (Array.isArray(data.logs) && data.logs.length !== lastCount) {
            lastCount = data.logs.length;
            renderLogs(data.logs);
          }

          if (deployStatus) {
            const s = data && data.status ? String(data.status) : '';
            deployStatus.textContent = s ? `Status: ${s}` : '';
          }

          const status = data && data.status ? String(data.status) : '';
          if (status && !['queued', 'building', 'deploying'].includes(status)) {
            if (poll) clearInterval(poll);
            poll = null;

            // Re-enable deploy button
            const btn = deployForm.querySelector('button[type="submit"]');
            if (btn) {
              btn.disabled = false;
              btn.removeAttribute('aria-disabled');
              btn.textContent = 'Redeploy';
            }

            // Return to container logs after a short pause.
            setTimeout(() => {
              setMode('logs');
              showToast('success', 'Deployment finished');
            }, 700);
          }
        } catch (e) {
          // keep polling but show status
          if (deployStatus) deployStatus.textContent = 'Status: error fetching logs';
        }
      }

      await tick();
      poll = setInterval(tick, 2000);
    }

    deployForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();

      const btn = deployForm.querySelector('button[type="submit"]');
      if (btn) {
        btn.disabled = true;
        btn.setAttribute('aria-disabled', 'true');
        btn.textContent = 'Deploying…';
      }

      try {
        const csrf = getCsrfToken(deployForm);
        let startRes = await fetch(deployForm.action, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
          },
          body: JSON.stringify({ _csrf_token: csrf }),
        });

        let data = await startRes.json().catch(() => ({}));

        // Handle ChapScribe prompt flow (409 action_required).
        while (startRes.status === 409 && data && data.error === 'action_required') {
          const runUuid = data && data.script_run && data.script_run.uuid ? String(data.script_run.uuid) : '';
          if (!runUuid) throw new Error('Template script requires action but no script_run UUID was returned');

          const response = await runChapPrompt(data.prompt);

          startRes = await fetch(`/chap-scripts/${encodeURIComponent(runUuid)}/respond`, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
            },
            body: JSON.stringify({ _csrf_token: csrf, response }),
          });
          data = await startRes.json().catch(() => ({}));
        }

        if (!startRes.ok) {
          throw new Error((data && (data.error || data.message)) ? (data.error || data.message) : 'Failed to start deployment');
        }

        const uuid = data && data.deployment && data.deployment.uuid ? String(data.deployment.uuid) : '';
        if (!uuid) throw new Error('Deployment started but no deployment UUID returned');

        // Switch UI to deployment logs mode.
        lastCount = 0;
        if (deployStatus) deployStatus.textContent = 'Status: starting…';
        deployLogs.innerHTML = `<div class="text-sm text-secondary">Starting deployment…</div>`;
        setMode('deploy');

        await pollDeployment(uuid);
      } catch (e) {
        const msg = e && e.message ? e.message : String(e);
        showToast('error', msg);
        setMode('logs');
        if (btn) {
          btn.disabled = false;
          btn.removeAttribute('aria-disabled');
          // best-effort label
          btn.textContent = 'Deploy';
        }
      }
    });

    // Cancel button (if present)
    const cancelForm = qs('form[data-cancel-deploy-form]');
    if (cancelForm) {
      cancelForm.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        try {
          const csrf = getCsrfToken(cancelForm);
          const res = await fetch(cancelForm.action, {
            method: 'POST',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
            },
            body: JSON.stringify({ _csrf_token: csrf }),
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok) throw new Error((data && (data.error || data.message)) ? (data.error || data.message) : 'Failed to cancel deployment');

          if (poll) clearInterval(poll);
          poll = null;
          showToast('success', 'Deployment cancelled');
          setMode('logs');

          // Give the server a moment then reload to update buttons/status.
          setTimeout(() => { window.location.reload(); }, 600);
        } catch (e) {
          showToast('error', e && e.message ? e.message : String(e));
        }
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
