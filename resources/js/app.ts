import '../css/app.css';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import AppLayout from './layouts/AppLayout.vue';
import { applyDocumentLocale, t } from './lib/i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Hassani ERP';

/** Pages without the sidebar shell: sign-in, the POS and print views. */
const bare = (name: string) => name.startsWith('auth/') || name === 'pos/Terminal' || name.endsWith('Print') || name === 'catalog/Labels';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: async (name) => {
        const pages = import.meta.glob<DefineComponent>('./pages/**/*.vue');
        // Modules paused in this version fall back to a friendly "paused" screen instead of an error.
        const path = pages[`./pages/${name}.vue`] ? `./pages/${name}.vue` : './pages/Paused.vue';
        const page = await resolvePageComponent(path, pages);
        page.default.layout ??= bare(name) ? undefined : AppLayout;
        return page;
    },
    setup({ el, App, props, plugin }) {
        applyDocumentLocale((props.initialPage.props.locale as 'en' | 'ur') ?? 'en');
        router.on('navigate', (event) => applyDocumentLocale((event.detail.page.props.locale as 'en' | 'ur') ?? 'en'));
        const app = createApp({ render: () => h(App, props) }).use(plugin).use(ZiggyVue);
        app.config.globalProperties.t = t;
        app.mount(el);
    },
    progress: { color: '#E0A419', showSpinner: false },
});
