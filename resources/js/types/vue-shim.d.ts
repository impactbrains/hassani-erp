import 'vue';

declare module 'vue' {
    interface ComponentCustomProperties {
        route: typeof import('ziggy-js').route;
        t: (text: string, params?: Record<string, string | number>) => string;
    }
}
