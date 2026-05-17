import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                // CIHRM Ghana institutional type stack — single sans for body + headings, mono for tabular data
                sans: ['"Open Sans"', ...defaultTheme.fontFamily.sans],
                serif: ['"Open Sans"', ...defaultTheme.fontFamily.serif],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
                display: ['"Open Sans"', ...defaultTheme.fontFamily.sans],
            },
            fontSize: {
                // Consistent type scale — pixel sizes paired with sensible line-heights and tracking
                'micro':    ['10px',  { lineHeight: '1.25',  letterSpacing: '0.04em' }],
                'tiny':     ['11px',  { lineHeight: '1.35',  letterSpacing: '0.02em' }],
                'caption':  ['12px',  { lineHeight: '1.45',  letterSpacing: '0.005em' }],
                'body':     ['14px',  { lineHeight: '1.55',  letterSpacing: '0' }],
                'body-lg':  ['15px',  { lineHeight: '1.6',   letterSpacing: '0' }],
                'lead':     ['16px',  { lineHeight: '1.55',  letterSpacing: '-0.005em' }],
                'h6':       ['13px',  { lineHeight: '1.3',   letterSpacing: '-0.005em' }],
                'h5':       ['15px',  { lineHeight: '1.3',   letterSpacing: '-0.01em' }],
                'h4':       ['18px',  { lineHeight: '1.3',   letterSpacing: '-0.01em' }],
                'h3':       ['22px',  { lineHeight: '1.25',  letterSpacing: '-0.015em' }],
                'h2':       ['28px',  { lineHeight: '1.2',   letterSpacing: '-0.02em' }],
                'h1':       ['34px',  { lineHeight: '1.15',  letterSpacing: '-0.022em' }],
                'display':  ['44px',  { lineHeight: '1.05',  letterSpacing: '-0.028em' }],
            },
            animation: {
                'reveal-up':     'revealUp 0.9s cubic-bezier(0.22, 1, 0.36, 1) forwards',
                'reveal-down':   'revealDown 0.9s cubic-bezier(0.22, 1, 0.36, 1) forwards',
                'reveal-left':   'revealLeft 0.9s cubic-bezier(0.22, 1, 0.36, 1) forwards',
                'fade-in':       'fadeIn 0.8s ease-out forwards',
                'slow-spin':     'spin 20s linear infinite',
                'float':         'float 6s ease-in-out infinite',
                'shimmer':       'shimmer 2.5s linear infinite',
                'scale-in':      'scaleIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) forwards',
                'glow-pulse':    'glowPulse 3s ease-in-out infinite',
                'gradient-x':    'gradientX 8s ease infinite',
                'slide-up-fade': 'slideUpFade 0.5s cubic-bezier(0.22, 1, 0.36, 1) forwards',
                'ping-slow':     'ping 2.5s cubic-bezier(0, 0, 0.2, 1) infinite',
                'bounce-subtle': 'bounceSubtle 2s ease-in-out infinite',
            },
            keyframes: {
                revealUp:     { '0%': { opacity: '0', transform: 'translateY(50px)' },  '100%': { opacity: '1', transform: 'translateY(0)' } },
                revealDown:   { '0%': { opacity: '0', transform: 'translateY(-50px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                revealLeft:   { '0%': { opacity: '0', transform: 'translateX(50px)' },  '100%': { opacity: '1', transform: 'translateX(0)' } },
                fadeIn:       { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                float:        { '0%, 100%': { transform: 'translateY(0px)' }, '50%': { transform: 'translateY(-24px)' } },
                shimmer:      { '0%': { backgroundPosition: '-200% 0' }, '100%': { backgroundPosition: '200% 0' } },
                scaleIn:      { '0%': { opacity: '0', transform: 'scale(0.92)' }, '100%': { opacity: '1', transform: 'scale(1)' } },
                glowPulse:    { '0%, 100%': { opacity: '0.4' }, '50%': { opacity: '0.8' } },
                gradientX:    { '0%, 100%': { backgroundPosition: '0% 50%' }, '50%': { backgroundPosition: '100% 50%' } },
                slideUpFade:  { '0%': { opacity: '0', transform: 'translateY(16px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                bounceSubtle: { '0%, 100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-8px)' } },
            },
            colors: {
                // ── CSS-variable-backed semantic tokens ──
                // Format: 'rgb(var(--ct-name) / <alpha-value>)' enables opacity modifiers
                // (e.g. bg-background/80, border-outline-variant/50)

                background:   'rgb(var(--ct-background)    / <alpha-value>)',
                'on-background': 'rgb(var(--ct-on-background) / <alpha-value>)',

                primary:      'rgb(var(--ct-primary)        / <alpha-value>)',
                'on-primary': 'rgb(var(--ct-on-primary)     / <alpha-value>)',

                // ── CIHRM brand: deep navy (#0d1452) is the primary identity.
                //    `secondary` is the action token used app-wide for buttons,
                //    active nav, links and focus rings — a mid-blue derived
                //    from the primary so it stays cohesive on white surfaces.
                secondary:              '#1a237e',  // primary action blue
                'on-secondary':         '#ffffff',
                'secondary-container':  '#3949ab',  // hover / brighter action
                'on-secondary-container': '#ffffff',

                // ── CIHRM brand palette (blue-dominant; gold ≤5% across app) ──
                'brand-navy':           '#0d1452',  // deep brand — sidebar bg, key headings
                'brand-navy-deep':      '#070b3a',  // ambient glow, gradients
                'brand-blue':           '#1a237e',  // mid-blue action
                'brand-blue-bright':    '#3949ab',  // brighter blue accent
                'brand-sky':            '#a7d3f0',  // soft blue tint — info bg

                // Reserved accents — use sparingly (≤5% combined across UI)
                'brand-gold':           '#ffd700',  // gold — primary 5% accent (CTAs/charts)
                'brand-gold-deep':      '#b88a08',  // darker gold for text/ink
                'brand-cyan':           '#12d9e3',  // electric cyan — chart/spark only
                'brand-magenta':        '#d912e3',  // electric magenta — chart/spark only

                // Legacy aliases (kept so existing pages don't break)
                'brand-empathy':        '#d912e3',  // re-routed to magenta accent
                'brand-curiosity':      '#12d9e3',  // re-routed to cyan accent
                'brand-collaboration':  '#ffd700',  // re-routed to gold accent
                'brand-vision':         '#ffd700',

                'surface-container-lowest': 'rgb(var(--ct-surface-lowest) / <alpha-value>)',
                'surface-container-low':    'rgb(var(--ct-surface-low)    / <alpha-value>)',
                'surface-container':        'rgb(var(--ct-surface)        / <alpha-value>)',
                'surface-container-high':   'rgb(var(--ct-surface-high)   / <alpha-value>)',
                'surface-container-highest':'rgb(var(--ct-surface-highest)/ <alpha-value>)',

                'on-surface':         'rgb(var(--ct-on-surface)         / <alpha-value>)',
                'on-surface-variant': 'rgb(var(--ct-on-surface-variant) / <alpha-value>)',
                'outline-variant':    'rgb(var(--ct-outline-variant)    / <alpha-value>)',

                'inverse-surface':    'rgb(var(--ct-inverse-surface)    / <alpha-value>)',
                'inverse-on-surface': 'rgb(var(--ct-inverse-on-surface) / <alpha-value>)',

                'tertiary-container':    '#25005a',
                'on-tertiary-container': '#9863ff',

                // Sidebar — keyed off brand navy (#0d1452)
                sidebar:         '#0d1452',
                'sidebar-surface': '#0f3057',
                'sidebar-border':  '#173a66',
                'sidebar-hover':   '#143a6e',
                'sidebar-active':  '#1a237e',
            },
            backgroundSize: {
                '200%': '200%',
                '300%': '300%',
            },
            boxShadow: {
                'glow-sm':   '0 0 12px rgba(26, 35, 126, 0.28)',
                'glow':      '0 0 24px rgba(26, 35, 126, 0.32)',
                'glow-lg':   '0 0 48px rgba(26, 35, 126, 0.36)',
                'glow-gold': '0 0 20px rgba(255, 215, 0, 0.32)',
                'lifted':    '0 8px 32px rgba(0,0,0,0.08), 0 2px 8px rgba(0,0,0,0.04)',
                'lifted-lg': '0 16px 48px rgba(0,0,0,0.12), 0 4px 16px rgba(0,0,0,0.06)',
                'card':      '0 1px 3px rgba(0,0,0,0.06), 0 4px 16px rgba(0,0,0,0.04)',
                'card-hover':'0 4px 24px rgba(0,0,0,0.1), 0 1px 4px rgba(0,0,0,0.06)',
                'header':    '0 1px 0 rgba(0,0,0,0.06)',
            },
            transitionTimingFunction: {
                'spring': 'cubic-bezier(0.22, 1, 0.36, 1)',
            },
            backdropBlur: {
                xs: '2px',
            },
        },
    },

    plugins: [forms],
};
