/**
 * Chap Dropdown System
 * Handles dropdown menus, context menus, and popovers
 */

(function() {
    'use strict';

    // Active dropdowns tracking
    let activeDropdown = null;

    // Default options
    const DEFAULTS = {
        placement: 'bottom-start', // bottom-start, bottom-end, top-start, top-end
        offset: 4,
        closeOnSelect: true,
        closeOnClickOutside: true
    };

    /**
     * Position dropdown relative to trigger
     */
    function positionDropdown(trigger, dropdown, placement, offset) {
        const triggerRect = trigger.getBoundingClientRect();
        const dropdownRect = dropdown.getBoundingClientRect();
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;
        
        let top, left;
        
        // Calculate initial position
        switch (placement) {
            case 'bottom-start':
                top = triggerRect.bottom + offset;
                left = triggerRect.left;
                break;
            case 'bottom-end':
                top = triggerRect.bottom + offset;
                left = triggerRect.right - dropdownRect.width;
                break;
            case 'top-start':
                top = triggerRect.top - dropdownRect.height - offset;
                left = triggerRect.left;
                break;
            case 'top-end':
                top = triggerRect.top - dropdownRect.height - offset;
                left = triggerRect.right - dropdownRect.width;
                break;
            case 'right-start':
                top = triggerRect.top;
                left = triggerRect.right + offset;
                break;
            case 'left-start':
                top = triggerRect.top;
                left = triggerRect.left - dropdownRect.width - offset;
                break;
            default:
                top = triggerRect.bottom + offset;
                left = triggerRect.left;
        }
        
        // Adjust for viewport boundaries
        // Right edge
        if (left + dropdownRect.width > viewportWidth - 8) {
            left = viewportWidth - dropdownRect.width - 8;
        }
        // Left edge
        if (left < 8) {
            left = 8;
        }
        // Bottom edge - flip to top if needed
        if (top + dropdownRect.height > viewportHeight - 8 && placement.startsWith('bottom')) {
            top = triggerRect.top - dropdownRect.height - offset;
        }
        // Top edge - flip to bottom if needed
        if (top < 8 && placement.startsWith('top')) {
            top = triggerRect.bottom + offset;
        }
        
        dropdown.style.position = 'fixed';
        dropdown.style.top = `${top}px`;
        dropdown.style.left = `${left}px`;
    }

    /**
     * Open dropdown
     */
    function openDropdown(trigger, dropdown, options = {}) {
        const config = { ...DEFAULTS, ...options };

        const container = trigger.closest('.dropdown') || trigger.parentElement;
        
        // Close any active dropdown first
        if (activeDropdown && activeDropdown.dropdown !== dropdown) {
            closeDropdown(activeDropdown);
        }
        
        // Portal dropdown to body while open.
        // This avoids browser quirks where `position: fixed` becomes relative to a transformed/filtered ancestor,
        // which can produce huge/incorrect left/top values on desktop.
        const originalParent = dropdown.parentElement;
        const originalNextSibling = dropdown.nextSibling;
        const originalInlineWidth = dropdown.style.width;

        if (originalParent && originalParent !== document.body) {
            document.body.appendChild(dropdown);
        }

        // Show dropdown
        dropdown.classList.add('dropdown-open');
        dropdown.style.display = 'block';
        trigger.setAttribute('aria-expanded', 'true');
        if (container) container.classList.add('open');

        // If menu is marked full-width, match the trigger width ("100%" would become viewport-width after portalling)
        try {
            if (dropdown.classList.contains('w-full')) {
                const triggerRect = trigger.getBoundingClientRect();
                dropdown.style.width = `${triggerRect.width}px`;
            }
        } catch (_) {
            // ignore
        }
        
        // Position it
        positionDropdown(trigger, dropdown, config.placement, config.offset);
        
        // Store reference
        activeDropdown = {
            trigger,
            dropdown,
            config,
            container,
            originalParent,
            originalNextSibling,
            originalInlineWidth
        };
        
        // Focus first focusable item
        const firstFocusable = dropdown.querySelector('a, button, [tabindex]:not([tabindex="-1"])');
        if (firstFocusable) {
            firstFocusable.focus();
        }
        
        // Dispatch event
        dropdown.dispatchEvent(new CustomEvent('dropdown:open', { bubbles: true }));
    }

    /**
     * Close dropdown
     */
    function closeDropdown(dropdownData) {
        if (!dropdownData) return;
        
        const { trigger, dropdown, container, originalParent, originalNextSibling, originalInlineWidth } = dropdownData;
        
        dropdown.classList.remove('dropdown-open');
        dropdown.classList.add('dropdown-closing');
        trigger.setAttribute('aria-expanded', 'false');
        if (container) container.classList.remove('open');
        
        setTimeout(() => {
            dropdown.style.display = '';
            dropdown.classList.remove('dropdown-closing');

            // Restore DOM position (and width) after close animation
            if (originalParent && originalParent !== document.body) {
                if (originalNextSibling && originalNextSibling.parentNode === originalParent) {
                    originalParent.insertBefore(dropdown, originalNextSibling);
                } else {
                    originalParent.appendChild(dropdown);
                }
            }
            dropdown.style.width = originalInlineWidth || '';
        }, 150);
        
        if (activeDropdown === dropdownData) {
            activeDropdown = null;
        }
        
        // Dispatch event
        dropdown.dispatchEvent(new CustomEvent('dropdown:close', { bubbles: true }));
    }

    /**
     * Toggle dropdown
     */
    function toggleDropdown(trigger, dropdown, options = {}) {
        const isOpen = dropdown.classList.contains('dropdown-open');
        
        if (isOpen) {
            closeDropdown(activeDropdown);
        } else {
            openDropdown(trigger, dropdown, options);
        }
    }

    /**
     * Initialize dropdowns with data attributes
     */
    function initDropdowns() {
        // Click handlers for dropdown triggers
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-dropdown-trigger]');
            
            if (trigger) {
                e.preventDefault();
                e.stopPropagation();
                
                const dropdownId = trigger.dataset.dropdownTrigger;
                const dropdown = document.getElementById(dropdownId) || 
                               trigger.parentElement.querySelector('.dropdown-menu');
                
                if (dropdown) {
                    const placement = trigger.dataset.dropdownPlacement || DEFAULTS.placement;
                    toggleDropdown(trigger, dropdown, { placement });
                }
                return;
            }
            
            // Close on click outside
            if (activeDropdown && activeDropdown.config.closeOnClickOutside) {
                const isInsideDropdown = e.target.closest('.dropdown-menu');
                if (!isInsideDropdown) {
                    closeDropdown(activeDropdown);
                }
            }
        });

        // Close on item click if configured
        document.addEventListener('click', (e) => {
            const item = e.target.closest('.dropdown-item');
            if (item && activeDropdown && activeDropdown.config.closeOnSelect) {
                // Small delay for visual feedback
                setTimeout(() => {
                    closeDropdown(activeDropdown);
                }, 50);
            }
        });

        // Keyboard navigation
        document.addEventListener('keydown', (e) => {
            if (!activeDropdown) return;
            
            const { dropdown, trigger } = activeDropdown;
            
            switch (e.key) {
                case 'Escape':
                    closeDropdown(activeDropdown);
                    trigger.focus();
                    break;
                    
                case 'ArrowDown':
                    e.preventDefault();
                    focusNextItem(dropdown, 1);
                    break;
                    
                case 'ArrowUp':
                    e.preventDefault();
                    focusNextItem(dropdown, -1);
                    break;
                    
                case 'Tab':
                    // Close dropdown on tab out
                    closeDropdown(activeDropdown);
                    break;
            }
        });

        // Update position on scroll/resize
        let rafId = null;
        const updatePosition = () => {
            if (activeDropdown) {
                const { trigger, dropdown, config } = activeDropdown;
                positionDropdown(trigger, dropdown, config.placement, config.offset);
            }
        };
        
        window.addEventListener('scroll', () => {
            if (rafId) cancelAnimationFrame(rafId);
            rafId = requestAnimationFrame(updatePosition);
        }, { passive: true });
        
        window.addEventListener('resize', () => {
            if (rafId) cancelAnimationFrame(rafId);
            rafId = requestAnimationFrame(updatePosition);
        }, { passive: true });
    }

    /**
     * Focus next/previous item in dropdown
     */
    function focusNextItem(dropdown, direction) {
        const items = Array.from(dropdown.querySelectorAll('.dropdown-item:not(.disabled)'));
        if (items.length === 0) return;
        
        const currentIndex = items.findIndex(item => item === document.activeElement);
        let nextIndex;
        
        if (currentIndex === -1) {
            nextIndex = direction > 0 ? 0 : items.length - 1;
        } else {
            nextIndex = currentIndex + direction;
            if (nextIndex < 0) nextIndex = items.length - 1;
            if (nextIndex >= items.length) nextIndex = 0;
        }
        
        items[nextIndex].focus();
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDropdowns);
    } else {
        initDropdowns();
    }

    // Public API
    const Dropdown = {
        open: openDropdown,
        close: (dropdown) => {
            if (activeDropdown && activeDropdown.dropdown === dropdown) {
                closeDropdown(activeDropdown);
            }
        },
        toggle: toggleDropdown,
        closeAll: () => {
            if (activeDropdown) {
                closeDropdown(activeDropdown);
            }
        },
        getActive: () => activeDropdown
    };

    // Expose to global scope
    window.Chap = window.Chap || {};
    window.Chap.dropdown = Dropdown;

})();
