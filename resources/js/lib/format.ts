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

const tz = 'Asia/Karachi';

export function date(value: string | null | undefined): string {
    if (!value) return '—';
    const d = value.length === 10 ? new Date(value + 'T00:00:00') : new Date(value);
    return d.toLocaleDateString(currentLocale() === 'ur' ? 'ur-PK' : 'en-GB', { day: '2-digit', month: 'short', year: 'numeric', ...(value.length === 10 ? {} : { timeZone: tz }) });
}

export function dateTime(value: string | null | undefined): string {
    if (!value) return '—';
    return new Date(value).toLocaleString(currentLocale() === 'ur' ? 'ur-PK' : 'en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true, timeZone: tz });
}

export function time(value: string | null | undefined): string {
    if (!value) return '—';
    return new Date(value).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: true, timeZone: tz });
}

export function today(): string {
    return new Date().toLocaleDateString('en-CA', { timeZone: tz });
}

/** Decimal-safe-enough arithmetic for cart display (server re-prices everything). */
export function round2(n: number): number {
    return Math.round((n + Number.EPSILON) * 100) / 100;
}
