import { ref } from 'vue';

// Module-level singleton — shared across all components
const isDark = ref(false);

function applyTheme(dark) {
    document.documentElement.classList.toggle('dark', dark);
    localStorage.setItem('cihrms-theme', dark ? 'dark' : 'light');
}

export function useDark() {
    function init() {
        const stored = localStorage.getItem('cihrms-theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        isDark.value = stored === 'dark' || (!stored && prefersDark);
        applyTheme(isDark.value);
    }

    function toggle() {
        isDark.value = !isDark.value;
        applyTheme(isDark.value);
    }

    return { isDark, toggle, init };
}
