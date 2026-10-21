import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ur } from './ur';

/**
 * English is the source text. Urdu comes from a hand-written dictionary
 * (lib/ur.ts); anything not yet translated falls back to English. Product
 * names are never machine-translated: their Urdu names are typed in Products.
 */
let forced: 'en' | 'ur' | null = null;

/** Languages written right-to-left. Add a code here when a new RTL language ships. */
const RTL_LOCALES: ReadonlyArray<string> = ['ur', 'ar', 'fa', 'he'];
export const isRtl = (locale: string): boolean => RTL_LOCALES.includes(locale);

/**
 * The language the user just picked, held until the server confirms it. Reactive, so every
 * t() call and every date/number format on screen re-renders the instant it changes.
 */
const pending = ref<'en' | 'ur' | null>(null);

export function currentLocale(): 'en' | 'ur' {
    if (forced) return forced;
    if (pending.value) return pending.value;
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
    document.documentElement.dir = isRtl(locale) ? 'rtl' : 'ltr';
}

/**
 * The one language switch for the whole app (ERP layout and POS terminal both call this).
 * Direction, language and text change immediately, with no page reload and no remount, so
 * carts, half-filled forms and scroll position all stay put. The choice is saved in the
 * background; if saving fails, the screen goes back to the saved language.
 */
export function switchLocale(next: 'en' | 'ur'): void {
    const saved = ((usePage().props as any).locale as 'en' | 'ur') ?? 'en';
    pending.value = next;
    applyDocumentLocale(next);
    router.post(route('locale.update'), { locale: next }, {
        preserveState: true,
        preserveScroll: true,
        onError: () => {
            pending.value = null;
            applyDocumentLocale(saved);
        },
        onSuccess: () => {
            pending.value = null;
        },
    });
}

/**
 * Keeps <html dir/lang> in step with the language the server reports, after every visit and
 * every partial reload, so any other place that changes the language (Profile, user admin,
 * sign-in) flips the layout live as well.
 */
export function installLocaleSync(initial: 'en' | 'ur'): void {
    applyDocumentLocale(initial);
    const sync = (page: { props: Record<string, unknown> }) => {
        if (pending.value) return; // a switch is in flight; the screen already shows it
        applyDocumentLocale(((page.props.locale as 'en' | 'ur') ?? 'en'));
    };
    router.on('navigate', (event) => sync(event.detail.page as any));
    router.on('success', (event) => sync(event.detail.page as any));
}
