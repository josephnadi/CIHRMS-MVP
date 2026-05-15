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
                sans: ['Plus Jakarta Sans', ...defaultTheme.fontFamily.sans],
                serif: ['Instrument Serif', ...defaultTheme.fontFamily.serif],
                mono: ['JetBrains Mono', ...defaultTheme.fontFamily.mono],
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

                secondary:              '#0051d5',
                'on-secondary':         '#ffffff',
                'secondary-container':  '#316bf3',
                'on-secondary-container': '#fefcff',

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

                // Sidebar (always dark, no variable needed)
                sidebar:         '#0c0e14',
                'sidebar-surface': '#131620',
                'sidebar-border':  '#1c1f2e',
                'sidebar-hover':   '#1a1d2e',
                'sidebar-active':  '#162040',
            },
            backgroundSize: {
                '200%': '200%',
                '300%': '300%',
            },
            boxShadow: {
                'glow-sm':   '0 0 12px rgba(0, 81, 213, 0.25)',
                'glow':      '0 0 24px rgba(0, 81, 213, 0.3)',
                'glow-lg':   '0 0 48px rgba(0, 81, 213, 0.35)',
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
