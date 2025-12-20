// Chap custom modal (replaces SweetAlert usage).
// Keeps the old API shape: chapSwal(options).then(({isConfirmed, isDismissed, value}) => ...)
// Supported options used in the codebase: title, text, html, icon, showCancelButton,
// confirmButtonText, cancelButtonText, didOpen, preConfirm.

(function () {
  function el(tag, attrs, children) {
    const node = document.createElement(tag);
    if (attrs) {
      for (const [k, v] of Object.entries(attrs)) {
        if (k === 'class') node.className = v;
        else if (k === 'text') node.textContent = v;
        else if (k === 'html') node.innerHTML = v;
        else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
        else if (v === true) node.setAttribute(k, k);
        else if (v != null) node.setAttribute(k, String(v));
      }
    }
    if (children) {
      for (const child of children) {
        if (child == null) continue;
        node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
      }
    }
    return node;
  }

  function normalizeOptions(options) {
    const o = options || {};
    return {
      title: o.title || '',
      text: o.text || '',
      html: o.html || '',
      icon: o.icon || null,
      showCancelButton: !!o.showCancelButton,
      confirmButtonText: o.confirmButtonText || 'Confirm',
      cancelButtonText: o.cancelButtonText || 'Cancel',
      didOpen: typeof o.didOpen === 'function' ? o.didOpen : null,
      preConfirm: typeof o.preConfirm === 'function' ? o.preConfirm : null,
      // Allow callers to pass through any extra fields without breaking.
      __raw: o,
    };
  }

  function renderIcon(icon) {
    if (!icon) return null;
    const map = {
      success: 'badge--success',
      warning: 'badge--warning',
      error: 'badge--danger',
      info: '',
      question: '',
    };
    const cls = map[icon] || '';
    return el('div', { class: `badge ${cls}` }, [String(icon).toUpperCase()]);
  }

  function chapSwal(options) {
    const o = normalizeOptions(options);

    return new Promise((resolve) => {
      let settled = false;
      let overlay;
      let modal;
      let focusBefore;

      function settle(result) {
        if (settled) return;
        settled = true;
        cleanup();
        resolve(result);
      }

      function cleanup() {
        document.removeEventListener('keydown', onKeydown);
        if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
        if (focusBefore && typeof focusBefore.focus === 'function') {
          try { focusBefore.focus(); } catch (_) {}
        }
      }

      function onCancel() {
        settle({
          isConfirmed: false,
          isDenied: false,
          isDismissed: true,
          dismiss: 'cancel',
        });
      }

      async function onConfirm() {
        try {
          let value;
          if (o.preConfirm) {
            value = await o.preConfirm();
          }
          settle({
            isConfirmed: true,
            isDenied: false,
            isDismissed: false,
            value,
          });
        } catch (e) {
          // If preConfirm throws, keep the modal open.
          console.error('[chapSwal] preConfirm failed:', e);
        }
      }

      function onKeydown(e) {
        if (e.key === 'Escape') {
          e.preventDefault();
          onCancel();
        }
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
          // Optional: cmd/ctrl+enter confirms in textareas.
          onConfirm();
        }
      }

      focusBefore = document.activeElement;

      const iconNode = renderIcon(o.icon);
      const headerRow = el('div', { class: 'row row--between', style: 'position: relative' }, [
        el('div', { class: 'row', style: 'gap: 10px' }, [
          iconNode,
          el('h2', { class: 'modal__title', text: o.title }),
        ]),
        el('button', { class: 'btn btn--ghost btn--sm modal__close', type: 'button', 'aria-label': 'Close', onClick: onCancel }, ['Close']),
      ]);

      const bodyChildren = [];
      if (o.text) bodyChildren.push(el('div', { text: o.text }));
      if (o.html) bodyChildren.push(el('div', { class: 'modal__html', html: o.html }));

      const cancelBtn = o.showCancelButton
        ? el('button', { class: 'btn', type: 'button', onClick: onCancel }, [o.cancelButtonText])
        : null;

      const confirmBtn = el('button', { class: 'btn btn--primary', type: 'button', onClick: onConfirm }, [o.confirmButtonText]);

      modal = el('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true' }, [
        el('div', { class: 'modal__header' }, [headerRow]),
        el('div', { class: 'modal__body' }, bodyChildren),
        el('div', { class: 'modal__actions' }, [cancelBtn, confirmBtn]),
      ]);

      overlay = el('div', { class: 'modal-overlay' }, [modal]);
      overlay.addEventListener('mousedown', (e) => {
        if (e.target === overlay) onCancel();
      });

      document.addEventListener('keydown', onKeydown);
      document.body.appendChild(overlay);

      // Focus confirm by default; textareas keep focus if provided.
      setTimeout(() => {
        try {
          const firstFocusable = modal.querySelector('textarea, input, select, button');
          if (firstFocusable) firstFocusable.focus();
          else confirmBtn.focus();
        } catch (_) {}
        if (o.didOpen) {
          try { o.didOpen(); } catch (e) { console.error('[chapSwal] didOpen failed:', e); }
        }
      }, 0);
    });
  }

  window.chapSwal = chapSwal;
})();
