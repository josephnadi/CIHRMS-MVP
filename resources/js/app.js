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
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
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
