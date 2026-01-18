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
        const res = await fetch(deployForm.action, {
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
        if (!res.ok) {
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
