<?php
/**
 * Scripts Partial
 * Common scripts for all pages
 */
?>
<!-- Chap Application JavaScript -->
<script src="/js/modal.js"></script>
<script src="/js/dropdown.js"></script>
<script src="/js/toast.js"></script>
<script src="/js/app.js"></script>

<script>
    // CSRF token for AJAX requests
    window.csrfToken = '<?= csrf_token() ?>';
    
    // Initialize Chap namespace
    window.Chap = window.Chap || {};
    window.Chap.csrfToken = window.csrfToken;
    
    // API helper function
    window.Chap.api = async function(url, method = 'GET', data = null) {
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            }
        };
        if (data) options.body = JSON.stringify(data);
        
        try {
            const response = await fetch(url, options);
            const result = await response.json();
            
            if (!response.ok) {
                throw new Error(result.error || result.message || 'Request failed');
            }
            
            return result;
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    };

    // Confirm before submitting forms that change resource limits.
    (function attachResourceLimitConfirm() {
        const forms = document.querySelectorAll('form[data-confirm-resource-limits="1"]');
        if (!forms || !forms.length) return;

        const limitFieldMatcher = (name) => {
            if (!name) return false;
            return (
                name.endsWith('_limit') ||
                name.endsWith('_mb_limit') ||
                name.endsWith('_millicores_limit') ||
                name === 'cpu_limit_cores' ||
                name === 'cpu_limit' ||
                name === 'memory_limit' ||
                name.startsWith('max_')
            );
        };

        for (const form of forms) {
            const watched = Array.from(form.querySelectorAll('input, select, textarea'))
                .filter((el) => el && typeof el.name === 'string' && limitFieldMatcher(el.name));

            const initial = new Map();
            for (const el of watched) {
                initial.set(el.name, String(el.value ?? ''));
            }

            form.addEventListener('submit', (e) => {
                let changed = false;
                for (const el of watched) {
                    const before = initial.get(el.name);
                    const now = String(el.value ?? '');
                    if (before !== now) {
                        changed = true;
                        break;
                    }
                }

                if (!changed) return;

                const msg = form.getAttribute('data-confirm-message')
                    || 'Changing resource limits can restart/redeploy applications and may auto-adjust child limits to stay within the new caps. Continue?';

                if (!window.confirm(msg)) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });
        }
    })();
</script>
