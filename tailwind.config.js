import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Every value here points at a CSS variable defined in resources/css/tokens.css.
 * Colours use the `<alpha-value>` placeholder so opacity modifiers still work
 * (e.g. `bg-brand/10`).
 */
const withAlpha = (variable) => `rgb(var(${variable}) / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './app/Livewire/**/*.php',
        './app/View/**/*.php',
        // Enums hand back class names — Availability::dotClass() is where the
        // presence colours live. Without this the JIT never sees them and the
        // status dot renders with no background at all.
        './app/Enums/**/*.php',
        // Same problem again: huddle-machine.js picks a column class from the
        // participant count. Without this the JIT never sees grid-cols-3 and a
        // nine-person huddle renders as a single tall column.
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            colors: {
                canvas: withAlpha('--color-canvas'),
                surface: {
                    DEFAULT: withAlpha('--color-surface'),
                    raised: withAlpha('--color-surface-raised'),
                    sunken: withAlpha('--color-surface-sunken'),
                    hover: withAlpha('--color-surface-hover'),
                    active: withAlpha('--color-surface-active'),
                },
                line: {
                    DEFAULT: withAlpha('--color-border'),
                    strong: withAlpha('--color-border-strong'),
                },
                content: {
                    DEFAULT: withAlpha('--color-text'),
                    muted: withAlpha('--color-text-muted'),
                    subtle: withAlpha('--color-text-subtle'),
                    inverse: withAlpha('--color-text-inverse'),
                },
                brand: {
                    DEFAULT: withAlpha('--color-brand'),
                    hover: withAlpha('--color-brand-hover'),
                    active: withAlpha('--color-brand-active'),
                    disabled: withAlpha('--color-brand-disabled'),
                    text: withAlpha('--color-brand-text'),
                    tint: withAlpha('--color-brand-tint'),
                    'tint-strong': withAlpha('--color-brand-tint-strong'),
                    border: withAlpha('--color-brand-border'),
                    on: withAlpha('--color-on-brand'),
                },
                emergency: {
                    DEFAULT: withAlpha('--color-emergency'),
                    hover: withAlpha('--color-emergency-hover'),
                    active: withAlpha('--color-emergency-active'),
                    solid: withAlpha('--color-emergency-solid'),
                    text: withAlpha('--color-emergency-text'),
                    tint: withAlpha('--color-emergency-tint'),
                    'tint-strong': withAlpha('--color-emergency-tint-strong'),
                    border: withAlpha('--color-emergency-border'),
                    on: withAlpha('--color-on-emergency'),
                },
                warning: {
                    DEFAULT: withAlpha('--color-warning'),
                    tint: withAlpha('--color-warning-tint'),
                },
                info: {
                    DEFAULT: withAlpha('--color-info'),
                    tint: withAlpha('--color-info-tint'),
                },
                success: withAlpha('--color-success'),
                presence: {
                    available: withAlpha('--color-presence-available'),
                    busy: withAlpha('--color-presence-busy'),
                    away: withAlpha('--color-presence-away'),
                    'off-shift': withAlpha('--color-presence-off-shift'),
                },
                focusring: withAlpha('--color-focus'),
            },

            fontFamily: {
                sans: ['var(--font-sans)', ...defaultTheme.fontFamily.sans],
                mono: ['var(--font-mono)', ...defaultTheme.fontFamily.mono],
            },

            fontSize: {
                '2xs': ['var(--text-2xs)', { lineHeight: 'var(--leading-tight)' }],
                xs: ['var(--text-xs)', { lineHeight: 'var(--leading-tight)' }],
                sm: ['var(--text-sm)', { lineHeight: 'var(--leading-snug)' }],
                base: ['var(--text-base)', { lineHeight: 'var(--leading-normal)' }],
                lg: ['var(--text-lg)', { lineHeight: 'var(--leading-snug)' }],
                xl: ['var(--text-xl)', { lineHeight: 'var(--leading-snug)' }],
                '2xl': ['var(--text-2xl)', { lineHeight: 'var(--leading-tight)' }],
                '3xl': ['var(--text-3xl)', { lineHeight: 'var(--leading-tight)' }],
            },

            borderRadius: {
                xs: 'var(--radius-xs)',
                sm: 'var(--radius-sm)',
                DEFAULT: 'var(--radius-md)',
                md: 'var(--radius-md)',
                lg: 'var(--radius-lg)',
                xl: 'var(--radius-xl)',
                full: 'var(--radius-full)',
            },

            boxShadow: {
                xs: 'var(--shadow-xs)',
                sm: 'var(--shadow-sm)',
                DEFAULT: 'var(--shadow-sm)',
                md: 'var(--shadow-md)',
                lg: 'var(--shadow-lg)',
                emergency: 'var(--shadow-emergency)',
            },

            transitionDuration: {
                instant: 'var(--duration-instant)',
                fast: 'var(--duration-fast)',
                normal: 'var(--duration-normal)',
                slow: 'var(--duration-slow)',
            },

            transitionTimingFunction: {
                standard: 'var(--ease-standard)',
                out: 'var(--ease-out)',
            },

            zIndex: {
                sticky: 'var(--z-sticky)',
                dropdown: 'var(--z-dropdown)',
                huddle: 'var(--z-huddle)',
                modal: 'var(--z-modal)',
                toast: 'var(--z-toast)',
                emergency: 'var(--z-emergency)',
            },

            keyframes: {
                'emergency-pulse': {
                    '0%, 100%': { boxShadow: '0 0 0 0 rgb(var(--alert-500) / 0.55)' },
                    '50%': { boxShadow: '0 0 0 8px rgb(var(--alert-500) / 0)' },
                },
                'fade-in-up': {
                    from: { opacity: '0', transform: 'translateY(4px)' },
                    to: { opacity: '1', transform: 'translateY(0)' },
                },
                // A new message in a room you are not looking at. Deliberately
                // finite: it draws the eye, then leaves the bold row and the
                // count to carry the information.
                'room-blink': {
                    '0%, 100%': { backgroundColor: 'transparent' },
                    '50%': { backgroundColor: 'rgb(var(--color-brand) / 0.22)' },
                },
            },

            animation: {
                'emergency-pulse': 'emergency-pulse 1.8s var(--ease-standard) infinite',
                'fade-in-up': 'fade-in-up var(--duration-normal) var(--ease-out)',
                'room-blink': 'room-blink 0.8s var(--ease-standard) 3',
            },
        },
    },

    plugins: [forms],
};
