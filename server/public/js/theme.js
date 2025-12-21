/**
 * Chap Theme Manager
 * Handles dark/light mode toggle with system preference detection
 * and localStorage persistence
 */

(function() {
    'use strict';

    const STORAGE_KEY = 'chap-theme';
    const THEME_ATTR = 'data-theme';
    const THEMES = {
        LIGHT: 'light',
        DARK: 'dark',
        SYSTEM: 'system'
    };

    // Theme Manager Class
    class ThemeManager {
        constructor() {
            this.currentTheme = this.getStoredTheme() || THEMES.SYSTEM;
            this.mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
            
            this.init();
        }

        /**
         * Initialize theme manager
         */
        init() {
            // Apply theme immediately to prevent flash
            this.applyTheme(this.currentTheme);
            
            // Listen for system theme changes
            this.mediaQuery.addEventListener('change', (e) => {
                if (this.currentTheme === THEMES.SYSTEM) {
                    this.applyTheme(THEMES.SYSTEM);
                }
            });

            // Set up toggle buttons when DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => this.setupToggles());
            } else {
                this.setupToggles();
            }
        }

        /**
         * Get stored theme from localStorage
         */
        getStoredTheme() {
            try {
                return localStorage.getItem(STORAGE_KEY);
            } catch (e) {
                console.warn('Unable to access localStorage:', e);
                return null;
            }
        }

        /**
         * Store theme in localStorage
         */
        storeTheme(theme) {
            try {
                localStorage.setItem(STORAGE_KEY, theme);
            } catch (e) {
                console.warn('Unable to store theme preference:', e);
            }
        }

        /**
         * Get the system's preferred color scheme
         */
        getSystemTheme() {
            return this.mediaQuery.matches ? THEMES.DARK : THEMES.LIGHT;
        }

        /**
         * Get the effective theme (resolves 'system' to actual theme)
         */
        getEffectiveTheme() {
            if (this.currentTheme === THEMES.SYSTEM) {
                return this.getSystemTheme();
            }
            return this.currentTheme;
        }

        /**
         * Apply theme to document
         */
        applyTheme(theme) {
            this.currentTheme = theme;
            const effectiveTheme = this.getEffectiveTheme();
            
            document.documentElement.setAttribute(THEME_ATTR, effectiveTheme);
            
            // Update meta theme-color for mobile browsers
            this.updateMetaThemeColor(effectiveTheme);
            
            // Dispatch custom event for any listeners
            document.dispatchEvent(new CustomEvent('themechange', {
                detail: { theme: effectiveTheme, preference: theme }
            }));
        }

        /**
         * Update meta theme-color tag
         */
        updateMetaThemeColor(theme) {
            let metaTheme = document.querySelector('meta[name="theme-color"]');
            
            if (!metaTheme) {
                metaTheme = document.createElement('meta');
                metaTheme.name = 'theme-color';
                document.head.appendChild(metaTheme);
            }
            
            // Use appropriate color based on theme
            metaTheme.content = theme === THEMES.DARK ? '#000000' : '#ffffff';
        }

        /**
         * Set theme and persist preference
         */
        setTheme(theme) {
            if (!Object.values(THEMES).includes(theme)) {
                console.warn(`Invalid theme: ${theme}`);
                return;
            }
            
            this.applyTheme(theme);
            this.storeTheme(theme);
            this.updateToggleButtons();
        }

        /**
         * Toggle between light and dark (skip system)
         */
        toggle() {
            const effectiveTheme = this.getEffectiveTheme();
            const newTheme = effectiveTheme === THEMES.DARK ? THEMES.LIGHT : THEMES.DARK;
            this.setTheme(newTheme);
        }

        /**
         * Cycle through: light -> dark -> system
         */
        cycle() {
            const order = [THEMES.LIGHT, THEMES.DARK, THEMES.SYSTEM];
            const currentIndex = order.indexOf(this.currentTheme);
            const nextIndex = (currentIndex + 1) % order.length;
            this.setTheme(order[nextIndex]);
        }

        /**
         * Set up theme toggle buttons
         */
        setupToggles() {
            // Three-button toggle (light, dark, system)
            const toggleContainer = document.querySelector('.theme-toggle');
            if (toggleContainer) {
                const buttons = toggleContainer.querySelectorAll('.theme-toggle-btn');
                buttons.forEach(btn => {
                    const theme = btn.dataset.theme;
                    if (theme) {
                        btn.addEventListener('click', () => this.setTheme(theme));
                    }
                });
            }

            // Simple toggle button (just switches between light/dark)
            const simpleToggle = document.querySelector('[data-theme-toggle]');
            if (simpleToggle) {
                simpleToggle.addEventListener('click', () => this.toggle());
            }

            this.updateToggleButtons();
        }

        /**
         * Update toggle button states
         */
        updateToggleButtons() {
            // Update three-button toggle
            const toggleContainer = document.querySelector('.theme-toggle');
            if (toggleContainer) {
                const buttons = toggleContainer.querySelectorAll('.theme-toggle-btn');
                buttons.forEach(btn => {
                    const theme = btn.dataset.theme;
                    btn.classList.toggle('active', theme === this.currentTheme);
                });
            }

            // Update simple toggle icon
            const simpleToggle = document.querySelector('[data-theme-toggle]');
            if (simpleToggle) {
                const effectiveTheme = this.getEffectiveTheme();
                const sunIcon = simpleToggle.querySelector('.icon-sun');
                const moonIcon = simpleToggle.querySelector('.icon-moon');
                
                if (sunIcon && moonIcon) {
                    sunIcon.style.display = effectiveTheme === THEMES.DARK ? 'block' : 'none';
                    moonIcon.style.display = effectiveTheme === THEMES.LIGHT ? 'block' : 'none';
                }
            }
        }
    }

    // Initialize theme manager immediately
    const themeManager = new ThemeManager();

    // Expose to global scope
    window.Chap = window.Chap || {};
    window.Chap.theme = {
        setTheme: (theme) => themeManager.setTheme(theme),
        toggle: () => themeManager.toggle(),
        cycle: () => themeManager.cycle(),
        getTheme: () => themeManager.currentTheme,
        getEffectiveTheme: () => themeManager.getEffectiveTheme()
    };

})();
