<?php
/**
 * Shared <head> partial.
 * Expects: $title (string|null)
 */
$pageTitle = ($title ?? 'Chap');
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> | Chap</title>
<meta name="csrf-token" content="<?= csrf_token() ?>">

<!-- Theme (apply early to avoid flash) -->
<script>
  (function () {
    try {
      var mode = localStorage.getItem('chap.theme') || 'auto';
      if (mode !== 'light' && mode !== 'dark' && mode !== 'auto') mode = 'auto';
      document.documentElement.setAttribute('data-theme', mode);
    } catch (_) {
      document.documentElement.setAttribute('data-theme', 'auto');
    }
  })();
</script>

<link rel="stylesheet" href="/css/tokens.css">
<link rel="stylesheet" href="/css/base.css">
<link rel="stylesheet" href="/css/layout.css">
<link rel="stylesheet" href="/css/components.css">
<link rel="stylesheet" href="/css/utilities.css">
<link rel="stylesheet" href="/css/pages/logs.css">

<script defer src="/js/theme.js"></script>
<script defer src="/js/chapSwal.js"></script>
<script defer src="/js/app.js"></script>
