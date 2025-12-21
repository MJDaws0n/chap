<?php
/**
 * Head Partial
 * Common head elements for all pages
 * 
 * Variables:
 * - $title: Page title (optional)
 */
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#ffffff">
<title><?= htmlspecialchars($title ?? 'Chap') ?><?= isset($title) ? ' | Chap' : '' ?></title>
<meta name="csrf-token" content="<?= csrf_token() ?>">

<!-- Chap Design System CSS -->
<link rel="stylesheet" href="/css/variables.css">
<link rel="stylesheet" href="/css/base.css">
<link rel="stylesheet" href="/css/components.css">
<link rel="stylesheet" href="/css/layout.css">
<link rel="stylesheet" href="/css/overlays.css">
<link rel="stylesheet" href="/css/utilities.css">

<!-- Theme initialization (must be in head to prevent flash) -->
<script src="/js/theme.js"></script>

<!-- Preconnect for performance -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
