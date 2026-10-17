import { usePage } from '@inertiajs/vue3';
import { ur } from './ur';

/**
 * English is the source text. Urdu comes from a hand-written dictionary
 * (lib/ur.ts); anything not yet translated falls back to English. Product
 * names are never machine-translated: their Urdu names are typed in Products.
 */
let forced: 'en' | 'ur' | null = null;

export function currentLocale(): 'en' | 'ur' {
    if (forced) return forced;
    try {
        return ((usePage().props as any).locale as 'en' | 'ur') ?? 'en';
    } catch {
        return (document.documentElement.lang as 'en' | 'ur') || 'en';
    }
}

export function setLocaleOverride(locale: 'en' | 'ur' | null): void {
    forced = locale;
}

export function t(text: string, params: Record<string, string | number> = {}): string {
    const translated = currentLocale() === 'ur' ? (ur[text] ?? text) : text;
    // Longest names first, so :to never eats the start of :total.
    return Object.entries(params).sort(([a], [b]) => b.length - a.length).reduce((s, [k, v]) => s.replaceAll(`:${k}`, String(v)), translated);
}

/** Picks the Urdu field when the UI is in Urdu and one was entered. */
export function localName(item: { name?: string | null; name_ur?: string | null; label?: string | null; label_ur?: string | null; title?: string | null; title_ur?: string | null } | null | undefined): string {
    if (!item) return '';
    if (currentLocale() === 'ur') {
        const own = item.name_ur || item.label_ur || item.title_ur;
        if (own) return own;
        const english = item.name || item.label || item.title || '';
        // Built-in names (report titles, system lists) fall back to the interface dictionary.
        return ur[english] ?? english;
    }
    return item.name || item.label || item.title || '';
}

export function applyDocumentLocale(locale: 'en' | 'ur'): void {
    document.documentElement.lang = locale;
    document.documentElement.dir = locale === 'ur' ? 'rtl' : 'ltr';
}
