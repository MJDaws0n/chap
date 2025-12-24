/**
 * Chap Modal System
 * Custom modal/dialog replacement for SweetAlert
 */

(function() {
    'use strict';

    // Modal configuration defaults
    const DEFAULTS = {
        title: '',
        message: '',
        type: 'info', // info, success, warning, danger, confirm
        confirmText: 'OK',
        cancelText: 'Cancel',
        showCancel: false,
        closeOnBackdrop: true,
        closeOnEscape: true,
        onConfirm: null,
        onCancel: null,
        html: null, // Custom HTML content
        input: null, // Input configuration { type, placeholder, value, required }
    };

    // Icons for each type
    const ICONS = {
        info: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>`,
        success: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`,
        warning: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>`,
        danger: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>`,
        confirm: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>`
    };

    // CSS Classes
    const CLASSES = {
        backdrop: 'modal-backdrop',
        modal: 'modal',
        container: 'modal-container',
        header: 'modal-header',
        icon: 'modal-icon',
        title: 'modal-title',
        body: 'modal-body',
        message: 'modal-message',
        input: 'modal-input',
        footer: 'modal-footer',
        visible: 'modal-visible',
        closing: 'modal-closing',
    };

    // Active modal tracking
    let activeModal = null;
    let modalStack = [];

    /**
     * Create modal element
     */
    function createModalElement(options) {
        // Create backdrop
        const backdrop = document.createElement('div');
        backdrop.className = CLASSES.backdrop;
        
        // Create modal container
        const modal = document.createElement('div');
        modal.className = CLASSES.modal;
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        if (options.title) {
            modal.setAttribute('aria-labelledby', 'modal-title');
        }
        
        // Build modal content
        let html = `<div class="${CLASSES.container}">`;
        
        // Header with icon and title
        if (options.title || ICONS[options.type]) {
            html += `<div class="${CLASSES.header}">`;
            if (ICONS[options.type]) {
                html += `<div class="${CLASSES.icon} modal-icon-${options.type}">${ICONS[options.type]}</div>`;
            }
            if (options.title) {
                html += `<h3 id="modal-title" class="${CLASSES.title}">${escapeHtml(options.title)}</h3>`;
            }
            html += `</div>`;
        }
        
        // Body
        html += `<div class="${CLASSES.body}">`;
        if (options.html) {
            html += options.html;
        } else if (options.message) {
            html += `<p class="${CLASSES.message}">${escapeHtml(options.message)}</p>`;
        }
        
        // Input field
        if (options.input) {
            const inputType = options.input.type || 'text';
            const placeholder = options.input.placeholder || '';
            const value = options.input.value || '';
            const required = options.input.required ? 'required' : '';
            
            if (inputType === 'textarea') {
                html += `<textarea class="input ${CLASSES.input}" placeholder="${escapeHtml(placeholder)}" ${required}>${escapeHtml(value)}</textarea>`;
            } else {
                html += `<input type="${inputType}" class="input ${CLASSES.input}" placeholder="${escapeHtml(placeholder)}" value="${escapeHtml(value)}" ${required}>`;
            }
        }
        html += `</div>`;
        
        // Footer with buttons
        html += `<div class="${CLASSES.footer}">`;
        if (options.showCancel) {
            html += `<button type="button" class="btn btn-secondary modal-cancel">${escapeHtml(options.cancelText)}</button>`;
        }
        const confirmBtnClass = options.type === 'danger' ? 'btn-danger' : 'btn-primary';
        html += `<button type="button" class="btn ${confirmBtnClass} modal-confirm">${escapeHtml(options.confirmText)}</button>`;
        html += `</div>`;
        
        html += `</div>`;
        
        modal.innerHTML = html;
        backdrop.appendChild(modal);
        
        return backdrop;
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Show modal
     */
    function showModal(options) {
        const config = { ...DEFAULTS, ...options };
        
        return new Promise((resolve) => {
            const backdrop = createModalElement(config);
            const modal = backdrop.querySelector(`.${CLASSES.modal}`);
            
            // Store reference
            const modalData = {
                backdrop,
                modal,
                config,
                resolve
            };
            
            // Add to DOM
            document.body.appendChild(backdrop);
            
            // Prevent body scroll
            document.body.style.overflow = 'hidden';
            
            // Trigger animation
            requestAnimationFrame(() => {
                backdrop.classList.add(CLASSES.visible);
            });
            
            // Focus management
            const input = modal.querySelector(`.${CLASSES.input}`);
            const confirmBtn = modal.querySelector('.modal-confirm');
            
            if (input) {
                input.focus();
                input.select();
            } else if (confirmBtn) {
                confirmBtn.focus();
            }
            
            // Event handlers
            const confirmBtn2 = modal.querySelector('.modal-confirm');
            const cancelBtn = modal.querySelector('.modal-cancel');
            
            if (confirmBtn2) {
                confirmBtn2.addEventListener('click', () => {
                    let value = true;
                    if (config.input) {
                        const inputEl = modal.querySelector(`.${CLASSES.input}`);
                        value = inputEl ? inputEl.value : '';
                        
                        // Validate required
                        if (config.input.required && !value.trim()) {
                            inputEl.classList.add('input-error');
                            inputEl.focus();
                            return;
                        }
                    }
                    closeModal(modalData, { confirmed: true, value });
                });
            }
            
            if (cancelBtn) {
                cancelBtn.addEventListener('click', () => {
                    closeModal(modalData, { confirmed: false, value: null });
                });
            }
            
            // Backdrop click
            if (config.closeOnBackdrop) {
                backdrop.addEventListener('click', (e) => {
                    if (e.target === backdrop) {
                        closeModal(modalData, { confirmed: false, value: null });
                    }
                });
            }
            
            // Escape key
            if (config.closeOnEscape) {
                const escapeHandler = (e) => {
                    if (e.key === 'Escape') {
                        document.removeEventListener('keydown', escapeHandler);
                        closeModal(modalData, { confirmed: false, value: null });
                    }
                };
                document.addEventListener('keydown', escapeHandler);
                modalData.escapeHandler = escapeHandler;
            }
            
            // Enter key for input
            if (config.input) {
                const inputEl = modal.querySelector(`.${CLASSES.input}`);
                if (inputEl && inputEl.tagName !== 'TEXTAREA') {
                    inputEl.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            confirmBtn2.click();
                        }
                    });
                }
            }
            
            // Store active modal
            modalStack.push(modalData);
            activeModal = modalData;
        });
    }

    /**
     * Close modal
     */
    function closeModal(modalData, result) {
        const { backdrop, config, resolve, escapeHandler } = modalData;
        
        // Remove escape handler
        if (escapeHandler) {
            document.removeEventListener('keydown', escapeHandler);
        }
        
        // Add closing animation
        backdrop.classList.add(CLASSES.closing);
        backdrop.classList.remove(CLASSES.visible);
        
        // Remove after animation
        setTimeout(() => {
            backdrop.remove();
            
            // Remove from stack
            const index = modalStack.indexOf(modalData);
            if (index > -1) {
                modalStack.splice(index, 1);
            }
            
            // Update active modal
            activeModal = modalStack.length > 0 ? modalStack[modalStack.length - 1] : null;
            
            // Restore body scroll if no modals
            if (modalStack.length === 0) {
                document.body.style.overflow = '';
            }
            
            // Call callbacks
            if (result.confirmed && typeof config.onConfirm === 'function') {
                config.onConfirm(result.value);
            } else if (!result.confirmed && typeof config.onCancel === 'function') {
                config.onCancel();
            }
            
            // Resolve promise
            resolve(result);
        }, 200);
    }

    /**
     * Close all modals
     */
    function closeAllModals() {
        [...modalStack].forEach(modalData => {
            closeModal(modalData, { confirmed: false, value: null });
        });
    }

    // Public API
    const Modal = {
        /**
         * Show a modal with full options
         */
        show: showModal,

        /**
         * Show info modal
         */
        info: (title, message) => showModal({
            type: 'info',
            title,
            message
        }),

        /**
         * Show success modal
         */
        success: (title, message) => showModal({
            type: 'success',
            title,
            message
        }),

        /**
         * Show warning modal
         */
        warning: (title, message) => showModal({
            type: 'warning',
            title,
            message
        }),

        /**
         * Show danger/error modal
         */
        danger: (title, message) => showModal({
            type: 'danger',
            title,
            message
        }),

        /**
         * Show error modal (alias for danger)
         */
        error: (title, message) => showModal({
            type: 'danger',
            title,
            message
        }),

        /**
         * Show confirmation modal
         */
        confirm: (title, message, options = {}) => showModal({
            type: 'confirm',
            title,
            message,
            showCancel: true,
            confirmText: options.confirmText || 'Confirm',
            cancelText: options.cancelText || 'Cancel',
            ...options
        }),

        /**
         * Show delete confirmation modal
         */
        confirmDelete: (title, message) => showModal({
            type: 'danger',
            title: title || 'Confirm Delete',
            message: message || 'Are you sure you want to delete this? This action cannot be undone.',
            showCancel: true,
            confirmText: 'Delete',
            cancelText: 'Cancel'
        }),

        /**
         * Show prompt modal with input
         */
        prompt: (title, options = {}) => showModal({
            type: 'info',
            title,
            message: options.message || '',
            showCancel: true,
            confirmText: options.confirmText || 'Submit',
            cancelText: options.cancelText || 'Cancel',
            input: {
                type: options.inputType || 'text',
                placeholder: options.placeholder || '',
                value: options.value || '',
                required: options.required !== false
            }
        }),

        /**
         * Close currently active modal
         */
        close: () => {
            if (activeModal) {
                closeModal(activeModal, { confirmed: false, value: null });
            }
        },

        /**
         * Close all open modals
         */
        closeAll: closeAllModals
    };

    // Expose to global scope
    window.Chap = window.Chap || {};
    window.Chap.modal = Modal;

    // Also expose as window.Modal for convenience
    window.Modal = Modal;

})();
