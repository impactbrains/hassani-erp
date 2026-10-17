import { reactive } from 'vue';
import { get } from '@/lib/http';

/** Drop-down lists (locations, payment methods, reasons ...) fetched once per page. */
const cache = reactive<Record<string, any[]>>({});
const pending: Record<string, Promise<void>> = {};

export function useOptions(keys: string[]) {
    const missing = keys.filter((k) => !(k in cache) && !pending[k]);
    if (missing.length) {
        const request = get(route('lookup.options', { only: missing.join(',') })).then((data: Record<string, any[]>) => {
            Object.assign(cache, data);
        });
        missing.forEach((k) => (pending[k] = request));
    }
    return cache;
}
