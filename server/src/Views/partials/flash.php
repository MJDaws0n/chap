<?php
/**
 * Flash messages partial.
 * Expects: $flash array with optional 'success'/'error'.
 */
?>
<?php if (!empty($flash['success'])): ?>
  <div class="alert alert--success" role="status">
    <?= htmlspecialchars($flash['success']) ?>
  </div>
<?php endif; ?>

<?php if (!empty($flash['error'])): ?>
  <div class="alert alert--error" role="alert">
    <?= htmlspecialchars($flash['error']) ?>
  </div>
<?php endif; ?>
