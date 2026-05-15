---
name: CIHRM Ghana Design System
colors:
  surface: '#f7f9fb'
  surface-dim: '#d8dadc'
  surface-bright: '#f7f9fb'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f2f4f6'
  surface-container: '#eceef0'
  surface-container-high: '#e6e8ea'
  surface-container-highest: '#e0e3e5'
  on-surface: '#191c1e'
  on-surface-variant: '#45464d'
  inverse-surface: '#2d3133'
  inverse-on-surface: '#eff1f3'
  outline: '#76777d'
  outline-variant: '#c6c6cd'
  surface-tint: '#565e74'
  primary: '#000000'
  on-primary: '#ffffff'
  primary-container: '#131b2e'
  on-primary-container: '#7c839b'
  inverse-primary: '#bec6e0'
  secondary: '#0051d5'
  on-secondary: '#ffffff'
  secondary-container: '#316bf3'
  on-secondary-container: '#fefcff'
  tertiary: '#000000'
  on-tertiary: '#ffffff'
  tertiary-container: '#25005a'
  on-tertiary-container: '#9863ff'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#dae2fd'
  primary-fixed-dim: '#bec6e0'
  on-primary-fixed: '#131b2e'
  on-primary-fixed-variant: '#3f465c'
  secondary-fixed: '#dbe1ff'
  secondary-fixed-dim: '#b4c5ff'
  on-secondary-fixed: '#00174b'
  on-secondary-fixed-variant: '#003ea8'
  tertiary-fixed: '#eaddff'
  tertiary-fixed-dim: '#d2bbff'
  on-tertiary-fixed: '#25005a'
  on-tertiary-fixed-variant: '#5a00c6'
  background: '#f7f9fb'
  on-background: '#191c1e'
  surface-variant: '#e0e3e5'
typography:
  headline-xl:
    fontFamily: Inter
    fontSize: 36px
    fontWeight: '700'
    lineHeight: 44px
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Inter
    fontSize: 28px
    fontWeight: '600'
    lineHeight: 36px
    letterSpacing: -0.01em
  headline-md:
    fontFamily: Inter
    fontSize: 20px
    fontWeight: '600'
    lineHeight: 28px
  body-lg:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  label-sm:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '500'
    lineHeight: 16px
    letterSpacing: 0.01em
  headline-lg-mobile:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  base: 4px
  xs: 8px
  sm: 16px
  md: 24px
  lg: 32px
  xl: 48px
  container-max: 1440px
  gutter: 24px
---

## Brand & Style
The design system for CIHRM Ghana represents the intersection of institutional authority and modern technological efficiency. The brand personality is **Prestigious**, **Structured**, and **Empowering**. It targets HR professionals and executives who require a robust, enterprise-grade environment that feels reliable yet forward-thinking.

The design style is **Corporate / Modern**, heavily influenced by the precision of Linear and the collaborative versatility of Monday.com. It prioritizes high-fidelity execution through subtle depth, refined borders, and a balanced use of white space. The UI aims to evoke a sense of organized calm, transforming complex HR data into actionable insights through clarity and intentional hierarchy.

## Colors
The palette is anchored by **Navy Blue (#0F172A)**, providing a foundational sense of institutional stability. **Royal Blue (#2563EB)** serves as the primary action color, ensuring high visibility for interactive elements. **Purple (#7C3AED)** is utilized as a tertiary accent for high-level data visualization and premium feature signifiers, echoing the vibrancy found in modern SaaS platforms.

The background ecosystem relies on **Light Gray (#F8FAFC)** and **White (#FFFFFF)** to create a layered "canvas" effect. Status colors follow a standardized semantic pattern to ensure immediate cognitive recognition of system states:
- **Green**: Success, completion, and active status.
- **Amber**: Pending actions, warnings, and transitions.
- **Red**: Critical errors, overdue tasks, and high-priority alerts.

## Typography
This design system utilizes **Inter** across all levels to maintain a systematic, utilitarian aesthetic. The typeface is chosen for its exceptional legibility in data-dense tables and complex forms. 

Hierarchy is established through tight control of weight and scale. Headlines utilize a slightly tighter letter-spacing and heavier weights to command attention, while body copy remains neutral and spacious. For mobile views, large display sizes scale down to prevent text wrapping issues while maintaining a clear information architecture.

## Layout & Spacing
The system employs a **Fixed Grid** philosophy for core desktop dashboards to ensure data alignment, while allowing for fluid behavior within content containers. A 12-column grid is standard for desktop, transitioning to an 8-column grid for tablets and a 4-column grid for mobile devices.

The spacing rhythm is based on a 4px baseline, but defaults to a 16px (sm) or 24px (md) increment for most structural padding. This "spacious layout" approach prevents the interface from feeling cluttered despite the inherent complexity of HR data. Margins are generous to ensure the eye can rest between different functional modules.

## Elevation & Depth
Depth in the design system is achieved through **Tonal Layers** combined with **Ambient Shadows**. Instead of heavy shadows, we use very soft, high-diffusion blurs with a slight navy tint (`rgba(15, 23, 42, 0.08)`) to lift cards off the background.

- **Level 0 (Base):** Light Gray (#F8FAFC) background.
- **Level 1 (Card):** White surfaces with a subtle 1px border (#E2E8F0) and minimal shadow.
- **Level 2 (Popovers/Dropdowns):** White surfaces with more pronounced shadows to signify temporary overlays.
- **Level 3 (Modals):** High elevation with a dimmed backdrop (40% opacity Navy) to focus user attention.

## Shapes
The shape language is consistently **Rounded**, using a 0.5rem (8px) corner radius for standard buttons and cards. This softens the "corporate" feel, making the software more approachable and modern without losing professional rigor. 

Larger containers like main content areas or primary dashboard cards use `rounded-lg` (16px), while utility elements like tags or small avatars may utilize `rounded-xl` (24px) or full circles for visual distinction.

## Components
- **Buttons:** Primary buttons use Royal Blue with white text. Secondary buttons use a subtle gray stroke with Navy text. State transitions (hover/active) should involve a 10% brightness shift rather than color changes.
- **Input Fields:** Use a 1px border in a medium-light gray. Upon focus, the border transitions to Royal Blue with a soft blue glow (focus ring).
- **Cards:** The primary container for information. Every card must have a consistent 24px internal padding and a 16px corner radius.
- **Status Chips:** Small, pill-shaped indicators with low-opacity background tints of the status color and high-contrast text (e.g., light green background with dark green text).
- **Data Tables:** High-fidelity tables with "sticky" headers. Row separators are faint (#F1F5F9). Interaction is highlighted by a subtle background change on hover.
- **Charts:** Use a palette derived from the primary Navy and Purple, ensuring high contrast for accessibility. Chart lines should be smooth (curved) to match the rounded shape language.