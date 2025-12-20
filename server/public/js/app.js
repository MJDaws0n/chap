(function () {
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

  function initSidebar() {
    const toggle = qs('[data-action="sidebar-toggle"]');
    const overlay = qs('[data-action="sidebar-overlay"]');

    function setOpen(open) {
      document.body.setAttribute('data-sidebar-open', open ? 'true' : 'false');
    }

    if (toggle) {
      toggle.addEventListener('click', () => {
        const open = document.body.getAttribute('data-sidebar-open') === 'true';
        setOpen(!open);
      });
    }

    if (overlay) {
      overlay.addEventListener('click', () => setOpen(false));
    }

    // Close sidebar on Escape (mobile)
    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      setOpen(false);
    });

    // Default closed on mobile, open on desktop
    const mq = window.matchMedia('(max-width: 980px)');
    function sync() {
      setOpen(!mq.matches);
    }
    // Don’t aggressively override user choice after load.
    if (!document.body.hasAttribute('data-sidebar-open')) sync();
  }

  function initUserMenu() {
    const btn = qs('[data-action="user-menu-toggle"]');
    const menu = qs('[data-user-menu]');
    if (!btn || !menu) return;

    function close() {
      menu.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
    }

    function open() {
      menu.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
    }

    close();

    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const isOpen = !menu.hidden;
      if (isOpen) close();
      else open();
    });

    document.addEventListener('click', (e) => {
      if (menu.hidden) return;
      if (menu.contains(e.target) || btn.contains(e.target)) return;
      close();
    });

    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      close();
    });
  }

  function initThemeSwitch() {
    const buttons = qsa('[data-theme-mode]');
    if (!buttons.length || !window.ChapTheme) return;

    function render() {
      const mode = window.ChapTheme.get();
      buttons.forEach((b) => b.setAttribute('aria-pressed', String(b.getAttribute('data-theme-mode') === mode)));
    }

    buttons.forEach((b) => {
      b.addEventListener('click', () => {
        window.ChapTheme.set(b.getAttribute('data-theme-mode'));
        render();
      });
    });

    render();
    document.addEventListener('chap:theme-changed', render);
  }

  document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    initUserMenu();
    initThemeSwitch();
  });
})();
