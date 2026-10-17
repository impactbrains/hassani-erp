import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useOptions } from '@/composables/useOptions';

/** Active locations (shop floor, godown, damaged ...) of the store you are working in. */
export function useStoreLocations() {
    const options = useOptions(['locations']);
    const page = usePage<any>();
    const all = computed<any[]>(() => options.locations ?? []);
    const here = computed<any[]>(() => all.value.filter((l: any) => l.store_id === page.props.auth?.store_id && l.type !== 'transit'));
    return { all, here };
}
