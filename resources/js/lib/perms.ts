import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from '@/types';

/** For showing and hiding controls only; the server checks every action again. */
export function can(...permissions: string[]): boolean {
    const auth = (usePage().props as unknown as SharedProps).auth;
    if (!auth) return false;
    return permissions.some((p) => auth.permissions.includes(p));
}
