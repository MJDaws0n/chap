(function () {
  const STORAGE_KEY = 'chap.theme';

  function normalize(mode) {
    if (mode === 'light' || mode === 'dark' || mode === 'auto') return mode;
    return 'auto';
  }

  function getStored() {
    try {
      return normalize(localStorage.getItem(STORAGE_KEY));
    } catch (_) {
      return 'auto';
    }
  }

  function setTheme(mode) {
    const value = normalize(mode);
    document.documentElement.setAttribute('data-theme', value);
    try {
      localStorage.setItem(STORAGE_KEY, value);
    } catch (_) {}
    document.dispatchEvent(new CustomEvent('chap:theme-changed', { detail: { mode: value } }));
  }

  window.ChapTheme = {
    get: getStored,
    set: setTheme,
  };
})();
