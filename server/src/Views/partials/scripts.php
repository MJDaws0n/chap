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
</script>
