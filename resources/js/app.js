import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
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
    resolve: (name) => resolvePageComponent(
        `./Pages/${name}.vue`,
        import.meta.glob('./Pages/**/*.vue'),
    ),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            // PWA install + update + offline banner — registered globally so
            // layouts can drop in <PwaInstallPrompt /> without importing.
            .component('PwaInstallPrompt',  PwaInstallPrompt)
            // WCAG 2.1 AA primitives — global so any layout can use them.
            .component('SkipLink',          SkipLink)
            .component('AriaLiveAnnouncer', AriaLiveAnnouncer)
            .mount(el);
    },
    progress: {
        color: '#0d1452',
        // Inertia defaults to a 250ms delay before the progress bar appears,
        // which makes every sub-250ms click feel "dead" (no visual feedback
        // happens at all) and adds 250ms of silence before slow clicks light
        // up the bar. Showing the bar immediately makes navigation feel
        // responsive even when the actual response time is the same.
        delay: 0,
    },
});
