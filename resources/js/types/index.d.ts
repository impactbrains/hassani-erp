import type { route as routeFn } from 'ziggy-js';

declare global {
    const route: typeof routeFn;
}

export interface StoreRef { id: number; code?: string; name: string; name_ur?: string | null }

export interface SharedProps {
    app: { name: string; timezone: string; company: { id: number; name: string; currency: string; timezone: string } | null };
    auth: { user: { id: number; name: string; email: string; is_owner: boolean; has_pin: boolean; two_factor: boolean }; permissions: string[]; stores: StoreRef[]; store_id: number | null } | null;
    locale: 'en' | 'ur';
    flash: { success?: string | null; error?: string | null; secret?: { label: string; value: string; note?: string } | null };
    errors: Record<string, string>;
    [key: string]: unknown;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}

export type Row = Record<string, any>;
