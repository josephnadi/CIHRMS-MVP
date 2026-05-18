import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import PwaInstallPrompt   from './Components/PwaInstallPrompt.vue';
import SkipLink           from './Components/SkipLink.vue';
import AriaLiveAnnouncer  from './Components/AriaLiveAnnouncer.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    // Each authenticated page declares `defineOptions({ layout: AuthenticatedLayout })`
    // in its <script setup>, which becomes a static `.layout` on the component.
    // Inertia v2 then wraps the page in that layout via h(layout, props, () => page),
    // and KEEPS the layout instance alive across navigations — Vue diffs only the
    // page slot, so the sidebar/header don't unmount. That's the persistent layout.
    // Public pages (Welcome, Careers/Show, Auth/Login, etc.) intentionally omit
    // defineOptions and render their own shells.
    resolve: (name) => {
        console.log('[NAVDIAG] resolve:', name);
        return resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        );
    },
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            // PWA install + update + offline banner â€” registered globally so
            // layouts can drop in <PwaInstallPrompt /> without importing.
            .component('PwaInstallPrompt',  PwaInstallPrompt)
            // WCAG 2.1 AA primitives â€” global so any layout can use them.
            .component('SkipLink',          SkipLink)
            .component('AriaLiveAnnouncer', AriaLiveAnnouncer)
            .mount(el);
    },
    progress: {
        color: '#0d1452',
    },
});

router.on('start',   (e) => console.log('[NAVDIAG] visit start  :', e.detail.visit.url.pathname));
router.on('success', (e) => console.log('[NAVDIAG] visit success:', e.detail.page.url, '· component:', e.detail.page.component));
