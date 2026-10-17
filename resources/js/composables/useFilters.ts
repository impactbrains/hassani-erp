import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

/**
 * List filters kept in the URL. Changing any value reloads the page data
 * (debounced for text), keeping scroll and other state.
 */
export function useFilters<T extends Record<string, any>>(routeName: string, initial: T, params: Record<string, unknown> = {}) {
    const filters = reactive({ ...initial }) as T;
    let timer: ReturnType<typeof setTimeout> | undefined;
    watch(() => ({ ...filters }), () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            const query = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== null && v !== undefined && v !== false));
            router.get(route(routeName, params as any), query, { preserveState: true, preserveScroll: true, replace: true });
        }, 300);
    }, { deep: true });

    return filters;
}
