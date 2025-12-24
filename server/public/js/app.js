/**
 * Chap Application JavaScript
 * Main application functionality, utilities, and initializations
 */

(function() {
    'use strict';

    // ===== Custom Select Dropdowns =====
    // Enhances native <select class="select"> into Chap dropdown-menu based pickers.
    // Keeps the original <select> for form submission, but hides it visually.
    function initSelectDropdowns() {
        const SELECTOR = 'select.select';
        const SEARCH_THRESHOLD = 8;

        const makeChevronSvg = () => {
            const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('class', 'icon dropdown-chevron select-chevron');
            svg.setAttribute('viewBox', '0 0 24 24');
            svg.setAttribute('fill', 'none');
            svg.setAttribute('stroke', 'currentColor');
            svg.setAttribute('stroke-width', '2');
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('stroke-linecap', 'round');
            path.setAttribute('stroke-linejoin', 'round');
            path.setAttribute('d', 'M19 9l-7 7-7-7');
            svg.appendChild(path);
            return svg;
        };

        const enhanceSelect = (select) => {
            if (!(select instanceof HTMLSelectElement)) return;
            if (select.dataset.chapSelectEnhanced === 'true') return;
            if (select.multiple) return; // keep native for multi-select
            if (select.closest('[data-native-select]')) return;

            const options = Array.from(select.options);
            const enabledOptions = options.filter(o => !o.disabled);
            const enableSearch = (select.dataset.search === 'true') || (enabledOptions.length >= SEARCH_THRESHOLD);

            const menuId = (window.Chap && window.Chap.utils && window.Chap.utils.generateId)
                ? window.Chap.utils.generateId('select-menu')
                : `select-menu-${Math.random().toString(36).slice(2)}`;

            const dropdown = document.createElement('div');
            dropdown.className = 'dropdown select-dropdown';

            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'btn btn-secondary w-full dropdown-trigger select-trigger';
            trigger.dataset.dropdownTrigger = menuId;
            trigger.dataset.dropdownPlacement = select.dataset.dropdownPlacement || 'bottom-start';
            trigger.setAttribute('aria-expanded', 'false');
            trigger.setAttribute('aria-haspopup', 'listbox');

            const triggerLabel = document.createElement('span');
            triggerLabel.className = 'select-trigger-label';
            trigger.appendChild(triggerLabel);
            trigger.appendChild(makeChevronSvg());

            const menu = document.createElement('div');
            menu.className = 'dropdown-menu w-full select-dropdown-menu';
            menu.id = menuId;
            menu.setAttribute('role', 'listbox');

            let searchInput = null;
            if (enableSearch) {
                const searchWrap = document.createElement('div');
                searchWrap.className = 'dropdown-search';

                searchInput = document.createElement('input');
                searchInput.type = 'text';
                searchInput.className = 'input input-sm';
                searchInput.placeholder = select.dataset.searchPlaceholder || 'Search...';
                searchInput.autocomplete = 'off';

                searchWrap.appendChild(searchInput);
                menu.appendChild(searchWrap);
            }

            const itemsWrap = document.createElement('div');
            itemsWrap.className = 'dropdown-items';
            menu.appendChild(itemsWrap);

            const empty = document.createElement('div');
            empty.className = 'dropdown-empty hidden';
            empty.textContent = 'No results';
            itemsWrap.appendChild(empty);

            const optionButtons = [];

            const updateTriggerFromSelect = () => {
                const selectedOption = select.selectedOptions && select.selectedOptions[0]
                    ? select.selectedOptions[0]
                    : select.options[select.selectedIndex];
                const label = selectedOption ? (selectedOption.textContent || '').trim() : '';

                // If first option is a placeholder (empty value), show its label only when actually selected.
                triggerLabel.textContent = label || 'Select...';

                optionButtons.forEach(({ btn, option }) => {
                    const isSelected = option.selected;
                    btn.classList.toggle('active', isSelected);
                    btn.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                });

                const disabled = select.disabled;
                trigger.disabled = disabled;
                trigger.setAttribute('aria-disabled', disabled ? 'true' : 'false');

                // Clear any previous error highlight when valid
                if (!select.required || select.checkValidity()) {
                    trigger.classList.remove('select-trigger-error');
                }
            };

            const renderOptions = (query = '') => {
                const q = query.trim().toLowerCase();
                let visibleCount = 0;

                optionButtons.forEach(({ btn }) => {
                    const text = (btn.dataset.label || '').toLowerCase();
                    const match = !q || text.includes(q);
                    btn.classList.toggle('hidden', !match);
                    if (match) visibleCount++;
                });

                empty.classList.toggle('hidden', visibleCount !== 0);
            };

            // Build option buttons
            options.forEach((option, index) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'dropdown-item';
                btn.dataset.value = option.value;
                btn.dataset.index = String(index);
                btn.dataset.label = (option.textContent || '').trim();
                btn.textContent = (option.textContent || '').trim() || option.value;
                btn.setAttribute('role', 'option');
                btn.setAttribute('aria-selected', option.selected ? 'true' : 'false');

                if (option.disabled) {
                    btn.classList.add('disabled');
                    btn.disabled = true;
                }

                btn.addEventListener('click', () => {
                    if (option.disabled) return;
                    select.selectedIndex = index;
                    select.value = option.value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    updateTriggerFromSelect();
                });

                optionButtons.push({ btn, option });
                itemsWrap.appendChild(btn);
            });

            // Hide and mark the native select
            select.dataset.chapSelectEnhanced = 'true';
            select.classList.add('select-native-hidden');
            select.tabIndex = -1;

            // Insert dropdown UI right after label if possible, otherwise before select
            // Preserve layout by keeping the select in the same form-group.
            select.parentNode.insertBefore(dropdown, select);
            dropdown.appendChild(trigger);
            dropdown.appendChild(menu);
            dropdown.appendChild(select);

            // Make clicking the associated <label for="..."> open the custom dropdown
            if (select.id) {
                let labelEl = null;
                try {
                    labelEl = document.querySelector(`label[for="${CSS.escape(select.id)}"]`);
                } catch (_) {
                    labelEl = document.querySelector(`label[for="${select.id}"]`);
                }
                if (labelEl) {
                    labelEl.addEventListener('click', (e) => {
                        if (trigger.disabled) return;
                        e.preventDefault();
                        trigger.click();
                    });
                }
            }

            // Keep UI in sync
            select.addEventListener('change', updateTriggerFromSelect);
            updateTriggerFromSelect();

            if (searchInput) {
                searchInput.addEventListener('input', () => {
                    renderOptions(searchInput.value);
                });

                // Reset search whenever menu opens
                menu.addEventListener('dropdown:open', () => {
                    searchInput.value = '';
                    renderOptions('');
                    // focus is handled by dropdown.js; keep it predictable
                    setTimeout(() => searchInput && searchInput.focus(), 0);
                });
            }

            // Initial filter state
            renderOptions('');
        };

        // Enhance all current selects
        Array.from(document.querySelectorAll(SELECTOR)).forEach(enhanceSelect);

        // Enhance selects added later (e.g. dynamically rendered forms)
        if (document.documentElement.dataset.chapSelectObserver !== 'true') {
            document.documentElement.dataset.chapSelectObserver = 'true';
            const observer = new MutationObserver((mutations) => {
                for (const m of mutations) {
                    for (const node of m.addedNodes) {
                        if (!(node instanceof HTMLElement)) continue;
                        if (node.matches && node.matches(SELECTOR)) {
                            enhanceSelect(node);
                        }
                        if (node.querySelectorAll) {
                            node.querySelectorAll(SELECTOR).forEach(enhanceSelect);
                        }
                    }
                }
            });
            if (document.body) {
                observer.observe(document.body, { childList: true, subtree: true });
            }
        }

        // Best-effort validation UX for required selects.
        // If a required enhanced select is invalid, prevent submit and highlight the trigger.
        document.addEventListener('submit', (e) => {
            const form = e.target;
            if (!(form instanceof HTMLFormElement)) return;

            const enhanced = Array.from(form.querySelectorAll('select.select[data-chap-select-enhanced="true"]'));
            for (const select of enhanced) {
                if (!(select instanceof HTMLSelectElement)) continue;
                if (!select.required) continue;
                if (select.disabled) continue;
                if (select.checkValidity()) continue;

                e.preventDefault();

                const dropdown = select.closest('.select-dropdown');
                const trigger = dropdown ? dropdown.querySelector('[data-dropdown-trigger]') : null;
                if (trigger) {
                    trigger.classList.add('select-trigger-error');
                    // Open to help the user fix it
                    const menuId = trigger.getAttribute('data-dropdown-trigger');
                    const menu = menuId ? document.getElementById(menuId) : null;
                    if (menu && window.Chap && window.Chap.dropdown) {
                        window.Chap.dropdown.open(trigger, menu, { placement: trigger.dataset.dropdownPlacement || 'bottom-start' });
                    }
                    trigger.focus();
                }

                if (window.Toast && typeof window.Toast.error === 'function') {
                    window.Toast.error('Please select an option');
                }
                break;
            }
        }, true);
    }

    // ===== Sidebar Mobile Toggle =====
    function initSidebar() {
        const menuBtn = document.querySelector('.header-menu-btn');
        const sidebar = document.querySelector('.sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        
        if (!menuBtn || !sidebar) return;
        
        // Create overlay if it doesn't exist
        let sidebarOverlay = overlay;
        if (!sidebarOverlay) {
            sidebarOverlay = document.createElement('div');
            sidebarOverlay.className = 'sidebar-overlay';
            document.body.appendChild(sidebarOverlay);
        }
        
        function openSidebar() {
            sidebar.classList.add('open');
            sidebarOverlay.classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        
        function closeSidebar() {
            sidebar.classList.remove('open');
            sidebarOverlay.classList.remove('open');
            document.body.style.overflow = '';
        }
        
        menuBtn.addEventListener('click', () => {
            if (sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
        
        sidebarOverlay.addEventListener('click', closeSidebar);
        
        // Close on escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                closeSidebar();
            }
        });
        
        // Close on resize to desktop
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 1024 && sidebar.classList.contains('open')) {
                closeSidebar();
            }
        });
    }

    // ===== Copy to Clipboard =====
    function initCopyButtons() {
        document.addEventListener('click', async (e) => {
            const copyBtn = e.target.closest('[data-copy]');
            if (!copyBtn) return;
            
            const text = copyBtn.dataset.copy;
            const originalContent = copyBtn.innerHTML;
            
            try {
                await navigator.clipboard.writeText(text);
                
                // Show success state
                copyBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>`;
                copyBtn.classList.add('copied');
                
                setTimeout(() => {
                    copyBtn.innerHTML = originalContent;
                    copyBtn.classList.remove('copied');
                }, 2000);
                
                // Show toast if available
                if (window.Toast) {
                    Toast.success('Copied to clipboard');
                }
            } catch (err) {
                console.error('Failed to copy:', err);
                if (window.Toast) {
                    Toast.error('Failed to copy to clipboard');
                }
            }
        });
    }

    // ===== Form Helpers =====
    function initForms() {
        // Auto-resize textareas
        document.querySelectorAll('textarea[data-auto-resize]').forEach(textarea => {
            const resize = () => {
                textarea.style.height = 'auto';
                textarea.style.height = textarea.scrollHeight + 'px';
            };
            
            textarea.addEventListener('input', resize);
            resize(); // Initial resize
        });
        
        // Form confirmation before submit
        document.addEventListener('submit', (e) => {
            const form = e.target;
            const confirmMessage = form.dataset.confirm;
            
            if (confirmMessage && !form.dataset.confirmed) {
                e.preventDefault();
                
                if (window.Modal) {
                    Modal.confirm('Confirm Action', confirmMessage).then(result => {
                        if (result.confirmed) {
                            form.dataset.confirmed = 'true';
                            form.submit();
                        }
                    });
                } else if (confirm(confirmMessage)) {
                    form.dataset.confirmed = 'true';
                    form.submit();
                }
            }
        });
        
        // Delete form handling
        document.addEventListener('click', (e) => {
            const deleteBtn = e.target.closest('[data-delete]');
            if (!deleteBtn) return;
            
            e.preventDefault();
            
            const form = deleteBtn.closest('form') || document.querySelector(deleteBtn.dataset.delete);
            const itemName = deleteBtn.dataset.deleteName || 'this item';
            
            if (!form) return;
            
            if (window.Modal) {
                Modal.confirmDelete(
                    `Delete ${itemName}?`,
                    `Are you sure you want to delete ${itemName}? This action cannot be undone.`
                ).then(result => {
                    if (result.confirmed) {
                        form.submit();
                    }
                });
            } else if (confirm(`Are you sure you want to delete ${itemName}?`)) {
                form.submit();
            }
        });
    }

    // ===== Tabs =====
    function initTabs() {
        document.addEventListener('click', (e) => {
            const tab = e.target.closest('[data-tab]');
            if (!tab) return;
            
            e.preventDefault();
            
            const tabGroup = tab.closest('.tabs');
            const tabId = tab.dataset.tab;
            const tabContent = document.getElementById(tabId);
            
            if (!tabGroup || !tabContent) return;
            
            // Deactivate all tabs
            tabGroup.querySelectorAll('[data-tab]').forEach(t => {
                t.classList.remove('active');
                t.setAttribute('aria-selected', 'false');
            });
            
            // Hide all tab panels
            const panelContainer = tabContent.parentElement;
            panelContainer.querySelectorAll('.tab-panel').forEach(panel => {
                panel.classList.remove('active');
                panel.setAttribute('hidden', '');
            });
            
            // Activate clicked tab
            tab.classList.add('active');
            tab.setAttribute('aria-selected', 'true');
            
            // Show tab panel
            tabContent.classList.add('active');
            tabContent.removeAttribute('hidden');
        });
    }

    // ===== Collapsible Sections =====
    function initCollapsibles() {
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-collapse]');
            if (!trigger) return;
            
            const targetId = trigger.dataset.collapse;
            const target = document.getElementById(targetId);
            
            if (!target) return;
            
            const isExpanded = trigger.getAttribute('aria-expanded') === 'true';
            
            trigger.setAttribute('aria-expanded', !isExpanded);
            target.classList.toggle('collapsed', isExpanded);
            
            // Rotate chevron icon if present
            const chevron = trigger.querySelector('.collapse-chevron');
            if (chevron) {
                chevron.style.transform = isExpanded ? '' : 'rotate(180deg)';
            }
        });
    }

    // ===== Tooltips =====
    function initTooltips() {
        let tooltip = null;
        
        function showTooltip(trigger) {
            const text = trigger.dataset.tooltip;
            if (!text) return;
            
            tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            tooltip.textContent = text;
            document.body.appendChild(tooltip);
            
            // Position tooltip
            const triggerRect = trigger.getBoundingClientRect();
            const tooltipRect = tooltip.getBoundingClientRect();
            const placement = trigger.dataset.tooltipPlacement || 'top';
            
            let top, left;
            
            switch (placement) {
                case 'top':
                    top = triggerRect.top - tooltipRect.height - 8;
                    left = triggerRect.left + (triggerRect.width - tooltipRect.width) / 2;
                    break;
                case 'bottom':
                    top = triggerRect.bottom + 8;
                    left = triggerRect.left + (triggerRect.width - tooltipRect.width) / 2;
                    break;
                case 'left':
                    top = triggerRect.top + (triggerRect.height - tooltipRect.height) / 2;
                    left = triggerRect.left - tooltipRect.width - 8;
                    break;
                case 'right':
                    top = triggerRect.top + (triggerRect.height - tooltipRect.height) / 2;
                    left = triggerRect.right + 8;
                    break;
            }
            
            tooltip.style.top = `${top}px`;
            tooltip.style.left = `${left}px`;
            
            requestAnimationFrame(() => {
                tooltip.classList.add('tooltip-visible');
            });
        }
        
        function hideTooltip() {
            if (tooltip) {
                tooltip.remove();
                tooltip = null;
            }
        }
        
        document.addEventListener('mouseenter', (e) => {
            const trigger = e.target.closest('[data-tooltip]');
            if (trigger) showTooltip(trigger);
        }, true);
        
        document.addEventListener('mouseleave', (e) => {
            const trigger = e.target.closest('[data-tooltip]');
            if (trigger) hideTooltip();
        }, true);
        
        // Hide on scroll
        document.addEventListener('scroll', hideTooltip, { passive: true });
    }

    // ===== Loading States =====
    function initLoadingStates() {
        // Add loading state to buttons on form submit
        document.addEventListener('submit', (e) => {
            const form = e.target;
            const submitBtn = form.querySelector('[type="submit"]');
            
            if (submitBtn && !form.dataset.noLoading) {
                submitBtn.disabled = true;
                submitBtn.classList.add('btn-loading');
                
                const originalText = submitBtn.innerHTML;
                submitBtn.dataset.originalText = originalText;
                submitBtn.innerHTML = `<span class="spinner"></span> Loading...`;
            }
        });
    }

    // ===== Auto-hide flash messages =====
    function initFlashMessages() {
        const flashAlerts = Array.from(document.querySelectorAll('.alert[data-auto-hide]'));

        // If our toast system is available, convert flash banners into nice popups.
        if (window.Toast && typeof window.Toast.show === 'function') {
            flashAlerts.forEach((alertEl) => {
                if (!(alertEl instanceof HTMLElement)) return;
                if (alertEl.dataset.chapToasted === 'true') return;
                alertEl.dataset.chapToasted = 'true';

                const duration = parseInt(alertEl.dataset.autoHide) || 5000;

                const msgSpan = alertEl.querySelector('span');
                const message = (msgSpan ? msgSpan.textContent : alertEl.textContent) || '';
                const text = message.trim();
                if (!text) {
                    alertEl.remove();
                    return;
                }

                let type = 'info';
                if (alertEl.classList.contains('alert-success')) type = 'success';
                else if (alertEl.classList.contains('alert-danger')) type = 'error';
                else if (alertEl.classList.contains('alert-warning')) type = 'warning';
                else if (alertEl.classList.contains('alert-info')) type = 'info';

                // Keep timing consistent with the banner auto-hide.
                if (typeof window.Toast[type] === 'function') {
                    window.Toast[type](text, { duration });
                } else {
                    window.Toast.show(text, { type: type === 'error' ? 'danger' : type, duration });
                }

                alertEl.remove();
            });

            return;
        }

        // Fallback: keep old in-page banners with auto-hide + dismiss.
        flashAlerts.forEach(alert => {
            const duration = parseInt(alert.dataset.autoHide) || 5000;

            setTimeout(() => {
                alert.classList.add('alert-hiding');
                setTimeout(() => alert.remove(), 300);
            }, duration);
        });

        // Dismiss button
        document.addEventListener('click', (e) => {
            const dismissBtn = e.target.closest('[data-dismiss="alert"]');
            if (!dismissBtn) return;

            const alert = dismissBtn.closest('.alert');
            if (alert) {
                alert.classList.add('alert-hiding');
                setTimeout(() => alert.remove(), 300);
            }
        });
    }

    // ===== Smooth scroll for anchor links =====
    function initSmoothScroll() {
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a[href^="#"]');
            if (!link) return;
            
            const targetId = link.getAttribute('href').slice(1);
            const target = document.getElementById(targetId);
            
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    // ===== Accessibility: Skip link =====
    function initSkipLink() {
        const skipLink = document.querySelector('.skip-link');
        if (skipLink) {
            skipLink.addEventListener('click', (e) => {
                e.preventDefault();
                const target = document.querySelector(skipLink.getAttribute('href'));
                if (target) {
                    target.setAttribute('tabindex', '-1');
                    target.focus();
                }
            });
        }
    }

    // ===== AJAX Form Handling =====
    window.Chap = window.Chap || {};
    
    window.Chap.ajax = {
        /**
         * Submit form via AJAX
         */
        submitForm: async function(form, options = {}) {
            const formData = new FormData(form);
            const url = form.action;
            const method = form.method || 'POST';
            
            try {
                const response = await fetch(url, {
                    method: method,
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                const data = await response.json();
                
                if (response.ok) {
                    if (options.onSuccess) options.onSuccess(data);
                    if (data.message && window.Toast) {
                        Toast.success(data.message);
                    }
                    if (data.redirect) {
                        window.location.href = data.redirect;
                    }
                } else {
                    if (options.onError) options.onError(data);
                    if (data.message && window.Toast) {
                        Toast.error(data.message);
                    }
                }
                
                return data;
            } catch (error) {
                console.error('Form submission error:', error);
                if (window.Toast) {
                    Toast.error('An error occurred. Please try again.');
                }
                throw error;
            }
        },
        
        /**
         * Make AJAX request
         */
        request: async function(url, options = {}) {
            const defaultOptions = {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/json'
                }
            };
            
            const config = { ...defaultOptions, ...options };
            
            if (config.body && typeof config.body === 'object') {
                config.body = JSON.stringify(config.body);
            }
            
            try {
                const response = await fetch(url, config);
                const data = await response.json();
                
                if (!response.ok) {
                    throw new Error(data.message || 'Request failed');
                }
                
                return data;
            } catch (error) {
                console.error('AJAX request error:', error);
                throw error;
            }
        }
    };

    // ===== Utility Functions =====
    window.Chap.utils = {
        /**
         * Debounce function
         */
        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        },
        
        /**
         * Throttle function
         */
        throttle: function(func, limit) {
            let inThrottle;
            return function(...args) {
                if (!inThrottle) {
                    func.apply(this, args);
                    inThrottle = true;
                    setTimeout(() => inThrottle = false, limit);
                }
            };
        },
        
        /**
         * Format bytes to human readable
         */
        formatBytes: function(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        },
        
        /**
         * Format date relative to now
         */
        formatRelativeTime: function(date) {
            const now = new Date();
            const then = new Date(date);
            const diff = now - then;
            
            const seconds = Math.floor(diff / 1000);
            const minutes = Math.floor(seconds / 60);
            const hours = Math.floor(minutes / 60);
            const days = Math.floor(hours / 24);
            
            if (seconds < 60) return 'just now';
            if (minutes < 60) return `${minutes}m ago`;
            if (hours < 24) return `${hours}h ago`;
            if (days < 7) return `${days}d ago`;
            
            return then.toLocaleDateString();
        },
        
        /**
         * Generate random ID
         */
        generateId: function(prefix = 'chap') {
            return `${prefix}-${Math.random().toString(36).substr(2, 9)}`;
        }
    };

    // ===== Initialize All =====
    function init() {
        initSidebar();
        initCopyButtons();
        initForms();
        initSelectDropdowns();
        initTabs();
        initCollapsibles();
        initTooltips();
        initLoadingStates();
        initFlashMessages();
        initSmoothScroll();
        initSkipLink();
        
        // Dispatch ready event
        document.dispatchEvent(new CustomEvent('chap:ready'));
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
