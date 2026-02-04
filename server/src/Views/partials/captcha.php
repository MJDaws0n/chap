<?php
/**
 * Captcha Partial
 * 
 * This partial renders reCAPTCHA or Autogate verification widgets and includes
 * JavaScript that disables the form submit button until verification completes.
 * 
 * Usage: <?php include __DIR__ . '/../partials/captcha.php'; ?>
 * 
 * The parent form should have a submit button. The script will:
 * - Find the closest form
 * - Disable submit buttons until captcha is verified
 * - Handle both reCAPTCHA and Autogate providers
 */

$captchaProvider = config('captcha.provider', 'none');
$captchaEnabled = in_array($captchaProvider, ['recaptcha', 'autogate'], true);
?>

<?php if ($captchaEnabled): ?>
    <?php if ($captchaProvider === 'recaptcha'): ?>
        <?php $siteKey = config('captcha.recaptcha.site_key', ''); ?>
        <?php if (!empty($siteKey)): ?>
            <div class="mb-6 captcha-container">
                <div class="g-recaptcha" 
                     data-sitekey="<?= e($siteKey) ?>"
                     data-callback="onCaptchaSuccess"
                     data-expired-callback="onCaptchaExpired"
                     data-error-callback="onCaptchaError"></div>
                <p class="form-hint text-secondary mt-2 captcha-hint">Please complete the verification above to continue.</p>
            </div>
            <script src="https://www.google.com/recaptcha/api.js" async defer></script>
            <script>
            (function() {
                'use strict';
                
                function findCaptchaForm() {
                    const containers = document.querySelectorAll('.captcha-container');
                    for (const container of containers) {
                        const form = container.closest('form');
                        if (form) return { form, container };
                    }
                    return null;
                }
                
                function updateSubmitState(enabled) {
                    const ctx = findCaptchaForm();
                    if (!ctx) return;
                    
                    const submitBtns = ctx.form.querySelectorAll('button[type="submit"], input[type="submit"]');
                    submitBtns.forEach(btn => {
                        btn.disabled = !enabled;
                        btn.setAttribute('aria-disabled', !enabled ? 'true' : 'false');
                    });
                    
                    const hint = ctx.container.querySelector('.captcha-hint');
                    if (hint) {
                        hint.style.display = enabled ? 'none' : 'block';
                    }
                }
                
                // Disable submit on page load
                document.addEventListener('DOMContentLoaded', function() {
                    updateSubmitState(false);
                });
                
                // Also try immediately in case DOM is already ready
                if (document.readyState !== 'loading') {
                    updateSubmitState(false);
                }
                
                window.onCaptchaSuccess = function(token) {
                    if (token) updateSubmitState(true);
                };
                
                window.onCaptchaExpired = function() {
                    updateSubmitState(false);
                };
                
                window.onCaptchaError = function() {
                    updateSubmitState(false);
                };
            })();
            </script>
        <?php endif; ?>
    <?php elseif ($captchaProvider === 'autogate'): ?>
        <?php $publicKey = config('captcha.autogate.public_key', ''); ?>
        <?php $theme = config('captcha.theme', 'dark'); ?>
        <?php if (!empty($publicKey)): ?>
            <div class="mb-6 captcha-container">
                <div id="captcha"></div>
                <input type="hidden" name="captcha_token" id="captcha_token">
                <p class="form-hint text-secondary mt-2 captcha-hint">Please complete the verification above to continue.</p>
            </div>
            <script src="https://autogate.mjdawson.net/lib/autogate.js"></script>
            <script>
            (function() {
                'use strict';
                
                function findCaptchaForm() {
                    const containers = document.querySelectorAll('.captcha-container');
                    for (const container of containers) {
                        const form = container.closest('form');
                        if (form) return { form, container };
                    }
                    return null;
                }
                
                function updateSubmitState(enabled) {
                    const ctx = findCaptchaForm();
                    if (!ctx) return;
                    
                    const submitBtns = ctx.form.querySelectorAll('button[type="submit"], input[type="submit"]');
                    submitBtns.forEach(btn => {
                        btn.disabled = !enabled;
                        btn.setAttribute('aria-disabled', !enabled ? 'true' : 'false');
                    });
                    
                    const hint = ctx.container.querySelector('.captcha-hint');
                    if (hint) {
                        hint.style.display = enabled ? 'none' : 'block';
                    }
                }
                
                function initAutogate() {
                    const el = document.getElementById('captcha');
                    const tokenEl = document.getElementById('captcha_token');
                    if (!el || !tokenEl) return;
                    
                    // Disable submit on init
                    updateSubmitState(false);
                    
                    const gate = new AutoGate('#captcha', <?= json_encode($publicKey) ?>, {
                        theme: <?= json_encode($theme) ?>,
                    });
                    
                    gate.onSuccess = function(token) {
                        tokenEl.value = token;
                        if (token) updateSubmitState(true);
                    };
                    
                    gate.onExpire = function() {
                        tokenEl.value = '';
                        updateSubmitState(false);
                    };
                    
                    gate.onError = function() {
                        tokenEl.value = '';
                        updateSubmitState(false);
                    };
                }
                
                document.addEventListener('DOMContentLoaded', initAutogate);
                if (document.readyState !== 'loading') {
                    initAutogate();
                }
            })();
            </script>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
