import { usePage } from '@inertiajs/vue3';
import { currentLocale } from './i18n';

/** Money and quantities arrive as decimal strings; format without float surprises for display. */
export function money(value: string | number | null | undefined, opts: { symbol?: boolean; decimals?: number } = {}): string {
    if (value === null || value === undefined || value === '') return '—';
    const n = Number(value);
    if (Number.isNaN(n)) return String(value);
    const decimals = opts.decimals ?? (Number.isInteger(n) ? 0 : 2);
    const formatted = new Intl.NumberFormat('en-PK', { minimumFractionDigits: decimals, maximumFractionDigits: 2 }).format(Math.abs(n));
    const sign = n < 0 ? '−' : '';
    return (opts.symbol === false ? '' : 'Rs ') + sign + formatted;
}

export function qty(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const n = Number(value);
    return Number.isNaN(n) ? String(value) : new Intl.NumberFormat('en-PK', { maximumFractionDigits: 3 }).format(n);
}

export function percent(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    return `${Number(value).toLocaleString('en-PK', { maximumFractionDigits: 1 })}%`;
}

/**
 * The time zone every time on screen is shown in: the working store's own zone (set in Settings → Stores),
 * else the company's, else Karachi. Read fresh each time so switching store switches the clock too.
 */
export function appTimezone(): string {
    try {
        return ((usePage().props as any).app?.timezone as string) || 'Asia/Karachi';
    } catch {
        return 'Asia/Karachi';
    }
}

/**
 * Turns what the server sent into a real moment in time. Postgres text such as "2026-10-19 05:05:00+00" or a
 * stamp with no offset at all means UTC; reading it as the browser's local time is what made times show hours behind.
 */
export function instant(value: string | Date): Date {
    if (value instanceof Date) return value;
    let v = value.trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(v)) return new Date(v + 'T00:00:00');
    v = v.replace(' ', 'T');
    v = v.replace(/([+-]\d{2})$/, '$1:00'); // +00 -> +00:00
    if (!/(Z|[+-]\d{2}:?\d{2})$/i.test(v)) v += 'Z'; // no offset: the server stores UTC
    return new Date(v);
}

export function date(value: string | null | undefined): string {
    if (!value) return '—';
    const dateOnly = value.length === 10;
    return instant(value).toLocaleDateString(currentLocale() === 'ur' ? 'ur-PK' : 'en-GB', { day: '2-digit', month: 'short', year: 'numeric', ...(dateOnly ? {} : { timeZone: appTimezone() }) });
}

export function dateTime(value: string | null | undefined): string {
    if (!value) return '—';
    return instant(value).toLocaleString(currentLocale() === 'ur' ? 'ur-PK' : 'en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true, timeZone: appTimezone() });
}

export function time(value: string | null | undefined): string {
    if (!value) return '—';
    return instant(value).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: true, timeZone: appTimezone() });
}

/** Today's date (YYYY-MM-DD) at the store. */
export function today(): string {
    return new Date().toLocaleDateString('en-CA', { timeZone: appTimezone() });
}

/** The hour (0-23) it is at the store right now. */
export function currentHour(): number {
    return Number(new Date().toLocaleString('en-GB', { hour: '2-digit', hour12: false, timeZone: appTimezone() })) % 24;
}

/** Decimal-safe-enough arithmetic for cart display (server re-prices everything). */
export function round2(n: number): number {
    return Math.round((n + Number.EPSILON) * 100) / 100;
}
