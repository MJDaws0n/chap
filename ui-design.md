# Chap UI Design System

## Overview

This document defines the comprehensive UI design system for Chap - a self-hosted deployment platform. The design philosophy centers around a modern, clean aesthetic with a frosted glass effect (glassmorphism), smooth transitions, and excellent readability in both light and dark modes.

---

## Color Palette

### Light Mode
```css
--bg-primary: #f5f5f7;           /* Main background - soft warm gray */
--bg-secondary: #ffffff;          /* Cards and elevated surfaces */
--bg-tertiary: #e8e8ed;           /* Subtle backgrounds, hover states */
--bg-glass: rgba(255, 255, 255, 0.72);  /* Frosted glass effect */

--text-primary: #1d1d1f;          /* Main text - near black */
--text-secondary: #6e6e73;        /* Secondary/muted text */
--text-tertiary: #86868b;         /* Placeholder, hints */

--border-primary: rgba(0, 0, 0, 0.08);   /* Subtle borders */
--border-secondary: rgba(0, 0, 0, 0.12); /* More visible borders */

--accent-blue: #0071e3;           /* Primary action color */
--accent-blue-hover: #0077ed;     /* Hover state */
--accent-green: #34c759;          /* Success, online, running */
--accent-yellow: #ff9f0a;         /* Warning, building */
--accent-orange: #ff9500;         /* Alerts */
--accent-red: #ff3b30;            /* Error, danger, offline */
--accent-purple: #af52de;         /* Nodes, special elements */
--accent-pink: #ff2d55;           /* Highlights */
--accent-teal: #5ac8fa;           /* Info */
```

### Dark Mode
```css
--bg-primary: #000000;            /* Main background - true black */
--bg-secondary: #1c1c1e;          /* Cards and elevated surfaces */
--bg-tertiary: #2c2c2e;           /* Subtle backgrounds, hover states */
--bg-glass: rgba(28, 28, 30, 0.72);  /* Frosted glass effect */

--text-primary: #f5f5f7;          /* Main text - off white */
--text-secondary: #98989d;        /* Secondary/muted text */
--text-tertiary: #6e6e73;         /* Placeholder, hints */

--border-primary: rgba(255, 255, 255, 0.08);   /* Subtle borders */
--border-secondary: rgba(255, 255, 255, 0.12); /* More visible borders */

/* Accent colors remain the same but slightly adjusted for dark backgrounds */
--accent-blue: #0a84ff;
--accent-blue-hover: #409cff;
--accent-green: #30d158;
--accent-yellow: #ffd60a;
--accent-orange: #ff9f0a;
--accent-red: #ff453a;
--accent-purple: #bf5af2;
--accent-pink: #ff375f;
--accent-teal: #64d2ff;
```

---

## Typography

### Font Stack
```css
--font-system: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', 
               'Helvetica Neue', Helvetica, Arial, sans-serif;
--font-mono: 'SF Mono', SFMono-Regular, ui-monospace, 'DejaVu Sans Mono', 
             Menlo, Consolas, monospace;
```

### Font Sizes
```css
--text-xs: 0.6875rem;     /* 11px - Fine print, badges */
--text-sm: 0.8125rem;     /* 13px - Secondary text, labels */
--text-base: 0.9375rem;   /* 15px - Body text */
--text-lg: 1.0625rem;     /* 17px - Emphasized body */
--text-xl: 1.25rem;       /* 20px - Section headers */
--text-2xl: 1.5rem;       /* 24px - Page titles */
--text-3xl: 1.875rem;     /* 30px - Hero text */
--text-4xl: 2.25rem;      /* 36px - Landing page headers */
```

### Font Weights
```css
--font-normal: 400;
--font-medium: 500;
--font-semibold: 600;
--font-bold: 700;
```

---

## Spacing System

Based on 4px increments:

```css
--space-1: 0.25rem;   /* 4px */
--space-2: 0.5rem;    /* 8px */
--space-3: 0.75rem;   /* 12px */
--space-4: 1rem;      /* 16px */
--space-5: 1.25rem;   /* 20px */
--space-6: 1.5rem;    /* 24px */
--space-8: 2rem;      /* 32px */
--space-10: 2.5rem;   /* 40px */
--space-12: 3rem;     /* 48px */
--space-16: 4rem;     /* 64px */
```

---

## Border Radius

```css
--radius-sm: 6px;      /* Small elements like badges */
--radius-md: 10px;     /* Buttons, inputs */
--radius-lg: 14px;     /* Cards, panels */
--radius-xl: 20px;     /* Large cards, modals */
--radius-full: 9999px; /* Circular elements */
```

---

## Shadows & Effects

### Light Mode Shadows
```css
--shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.04);
--shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
--shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.12);
--shadow-xl: 0 16px 48px rgba(0, 0, 0, 0.16);
```

### Dark Mode Shadows
```css
--shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.2);
--shadow-md: 0 4px 12px rgba(0, 0, 0, 0.3);
--shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.4);
--shadow-xl: 0 16px 48px rgba(0, 0, 0, 0.5);
```

### Glass Effect (Frosted Glass / Glassmorphism)
```css
.glass {
    background: var(--bg-glass);
    backdrop-filter: blur(20px) saturate(180%);
    -webkit-backdrop-filter: blur(20px) saturate(180%);
    border: 1px solid var(--border-primary);
}
```

---

## Transitions

```css
--transition-fast: 150ms cubic-bezier(0.4, 0, 0.2, 1);
--transition-base: 200ms cubic-bezier(0.4, 0, 0.2, 1);
--transition-slow: 300ms cubic-bezier(0.4, 0, 0.2, 1);
```

---

## Component Specifications

### Buttons
- **Primary**: Blue background, white text, 10px 20px padding
- **Secondary**: Gray background, dark text
- **Ghost**: Transparent, colored text
- **Danger**: Red background or ghost with red text

### Form Inputs
- Background: `var(--bg-secondary)`
- Border: 1px solid `var(--border-secondary)`
- Border radius: `var(--radius-md)`
- Padding: 10px 14px
- Focus: Blue border with soft glow
- **IMPORTANT**: Always handle text overflow with `text-overflow: ellipsis`

### Cards
- Background: `var(--bg-secondary)` or glass effect
- Border: 1px solid `var(--border-primary)`
- Border radius: `var(--radius-lg)`
- Padding: `var(--space-6)`
- Hover: Subtle border darkening and shadow

### Status Badges
- Pill-shaped with `var(--radius-full)`
- Tinted background with matching text color
- Sizes: Small padding 4px 10px, tiny font size

### Custom Modal (Replacing SweetAlert)
- Overlay with backdrop blur
- Modal with `var(--radius-xl)`
- Fade + scale animation
- Max width 420px

### Custom Dropdown
- Trigger styled like input
- Menu with shadow and border radius
- Items with hover state
- Search filter when needed

### Toast Notifications
- Fixed position top-right
- Slide in from right
- Auto-dismiss with progress

---

## Layout Specifications

### App Layout (Authenticated)
- **Sidebar**: Fixed 260px, collapsible on mobile
- **Header**: 64px height, sticky, glass effect
- **Main Content**: Fluid, max-width 1400px centered
- **Content Padding**: 24px desktop, 16px mobile

### Auth Layout
- Centered container, max-width 420px
- Glass effect card

### Create/Edit Pages
- Form container: max-width 640px for single-column
- Full width for complex multi-column forms

### List/Index Pages
- Grid: 1 col mobile → 2 cols tablet → 3-4 cols desktop
- Tables: Full width with horizontal scroll on mobile

---

## Responsive Breakpoints

```css
--breakpoint-sm: 640px;   /* Small tablets */
--breakpoint-md: 768px;   /* Tablets */
--breakpoint-lg: 1024px;  /* Small laptops */
--breakpoint-xl: 1280px;  /* Desktops */
```

---

## Theme Toggle Behavior

1. **Default**: System preference via `prefers-color-scheme`
2. **User Override**: Stored in `localStorage` as 'chap-theme'
3. **Toggle Options**: Light, Dark, System
4. **No Flash**: Theme applied before page render

---

## Live Logs Specific

```css
.logs-container {
    font-family: var(--font-mono);
    font-size: var(--text-sm);
    line-height: 1.625;
    word-break: break-all;
    overflow-wrap: break-word;
    white-space: pre-wrap;
}
```

---

## File Structure

```
server/public/
├── css/
│   ├── variables.css     /* CSS custom properties */
│   ├── base.css          /* Reset, typography, global */
│   ├── components.css    /* Buttons, inputs, cards */
│   ├── layout.css        /* Grid, containers, app layout */
│   └── utilities.css     /* Helper classes */
└── js/
    ├── theme.js          /* Theme toggle */
    ├── modal.js          /* Custom modal system */
    ├── dropdown.js       /* Custom dropdowns */
    ├── toast.js          /* Toast notifications */
    └── app.js            /* Main application JS */

server/src/Views/
├── layouts/
│   ├── app.php           /* Main authenticated layout */
│   ├── auth.php          /* Auth pages layout */
│   └── guest.php         /* Public pages layout */
└── partials/
    ├── head.php          /* <head> contents */
    ├── header.php        /* Top header bar */
    ├── sidebar.php       /* Sidebar navigation */
    └── scripts.php       /* Bottom scripts */
```

---

## Key Design Principles

1. **Consistency**: Same components look the same everywhere
2. **Clarity**: Clear visual hierarchy and readable text
3. **Responsiveness**: Works on all screen sizes
4. **Performance**: No heavy frameworks, vanilla CSS/JS
5. **Accessibility**: Proper contrast, focus states, ARIA labels
