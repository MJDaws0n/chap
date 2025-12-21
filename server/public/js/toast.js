/**
 * Chap Toast Notification System
 * Lightweight toast notifications
 */

(function() {
    'use strict';

    // Toast container
    let toastContainer = null;
    
    // Default options
    const DEFAULTS = {
        type: 'info', // info, success, warning, danger
        duration: 4000, // 0 for persistent
        position: 'top-right', // top-right, top-left, bottom-right, bottom-left, top-center, bottom-center
        dismissible: true,
        icon: true
    };

    // Icons for each type
    const ICONS = {
        info: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>`,
        success: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`,
        warning: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>`,
        danger: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>`
    };

    /**
     * Create or get toast container
     */
    function getContainer(position) {
        let container = document.querySelector(`.toast-container[data-position="${position}"]`);
        
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            container.dataset.position = position;
            document.body.appendChild(container);
        }
        
        return container;
    }

    /**
     * Create toast element
     */
    function createToastElement(message, options) {
        const toast = document.createElement('div');
        toast.className = `toast toast-${options.type}`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'polite');
        
        let html = '';
        
        // Icon
        if (options.icon && ICONS[options.type]) {
            html += `<div class="toast-icon">${ICONS[options.type]}</div>`;
        }
        
        // Message
        html += `<div class="toast-message">${escapeHtml(message)}</div>`;
        
        // Close button
        if (options.dismissible) {
            html += `<button type="button" class="toast-close" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>`;
        }
        
        // Progress bar for timed toasts
        if (options.duration > 0) {
            html += `<div class="toast-progress" style="animation-duration: ${options.duration}ms"></div>`;
        }
        
        toast.innerHTML = html;
        
        return toast;
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Show toast
     */
    function showToast(message, options = {}) {
        const config = { ...DEFAULTS, ...options };
        const container = getContainer(config.position);
        const toast = createToastElement(message, config);
        
        // Add to container
        if (config.position.includes('bottom')) {
            container.insertBefore(toast, container.firstChild);
        } else {
            container.appendChild(toast);
        }
        
        // Trigger animation
        requestAnimationFrame(() => {
            toast.classList.add('toast-visible');
        });
        
        // Close button handler
        const closeBtn = toast.querySelector('.toast-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => dismissToast(toast));
        }
        
        // Auto dismiss
        if (config.duration > 0) {
            setTimeout(() => dismissToast(toast), config.duration);
        }
        
        // Pause progress on hover
        toast.addEventListener('mouseenter', () => {
            const progress = toast.querySelector('.toast-progress');
            if (progress) {
                progress.style.animationPlayState = 'paused';
            }
        });
        
        toast.addEventListener('mouseleave', () => {
            const progress = toast.querySelector('.toast-progress');
            if (progress) {
                progress.style.animationPlayState = 'running';
            }
        });
        
        return toast;
    }

    /**
     * Dismiss toast
     */
    function dismissToast(toast) {
        if (!toast || toast.classList.contains('toast-dismissing')) return;
        
        toast.classList.add('toast-dismissing');
        toast.classList.remove('toast-visible');
        
        setTimeout(() => {
            toast.remove();
            
            // Clean up empty containers
            document.querySelectorAll('.toast-container').forEach(container => {
                if (container.children.length === 0) {
                    container.remove();
                }
            });
        }, 300);
    }

    /**
     * Dismiss all toasts
     */
    function dismissAllToasts() {
        document.querySelectorAll('.toast').forEach(dismissToast);
    }

    // Public API
    const Toast = {
        /**
         * Show a toast with full options
         */
        show: showToast,

        /**
         * Show info toast
         */
        info: (message, options = {}) => showToast(message, { ...options, type: 'info' }),

        /**
         * Show success toast
         */
        success: (message, options = {}) => showToast(message, { ...options, type: 'success' }),

        /**
         * Show warning toast
         */
        warning: (message, options = {}) => showToast(message, { ...options, type: 'warning' }),

        /**
         * Show danger/error toast
         */
        danger: (message, options = {}) => showToast(message, { ...options, type: 'danger' }),

        /**
         * Show error toast (alias for danger)
         */
        error: (message, options = {}) => showToast(message, { ...options, type: 'danger' }),

        /**
         * Dismiss a specific toast
         */
        dismiss: dismissToast,

        /**
         * Dismiss all toasts
         */
        dismissAll: dismissAllToasts
    };

    // Expose to global scope
    window.Chap = window.Chap || {};
    window.Chap.toast = Toast;

    // Also expose as window.Toast for convenience
    window.Toast = Toast;

})();
