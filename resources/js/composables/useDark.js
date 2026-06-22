import { ref } from 'vue';

// Dark mode has been removed — the app is light-only.
// `isDark` stays permanently false so all theme-conditional UI resolves to light.
const isDark = ref(false);

export function useDark() {
    // Actively clear any legacy dark preference / class so returning users
    // who previously selected dark mode are reset to light.
    function init() {
        document.documentElement.classList.remove('dark');
        localStorage.removeItem('cihrms-theme');
    }

    // No-op: theme switching is no longer supported.
    function toggle() {}

    return { isDark, toggle, init };
}
