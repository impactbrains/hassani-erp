/**
 * JSON calls for the POS and look-ups (pages themselves use Inertia).
 * Errors come back as { message, code, errors? } and are thrown as ApiError.
 *
 * The CSRF token is read from the XSRF-TOKEN cookie on EVERY request. Signing in changes the
 * session token but the app moves to the next page without a full reload, so a token copied
 * once from the page's <meta> tag goes stale. If the server still answers 419 (token expired),
 * the request is retried once with a fresh token.
 */
export class ApiError extends Error {
    constructor(message: string, public status: number, public code: string = 'error', public errors: Record<string, string[]> = {}, public details: Record<string, any> = {}) {
        super(message);
    }
}

function cookieToken(): string | null {
    const entry = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return entry ? decodeURIComponent(entry.slice('XSRF-TOKEN='.length)) : null;
}

/** Header carrying the current token. The cookie wins; the <meta> tag is only the fallback. */
function csrfHeader(): Record<string, string> {
    const fromCookie = cookieToken();
    if (fromCookie) return { 'X-XSRF-TOKEN': fromCookie };
    const meta = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content;
    return meta ? { 'X-CSRF-TOKEN': meta } : {};
}

async function send(method: string, url: string, body?: unknown): Promise<Response> {
    return fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...csrfHeader() },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
}

export async function api<T = any>(method: string, url: string, body?: unknown): Promise<T> {
    let response = await send(method, url, body);
    if (response.status === 419 && method !== 'GET') {
        // Token expired: ask for a fresh cookie and try once more. A checkout is safe to repeat (idempotency key).
        await fetch('/csrf', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).catch(() => null);
        response = await send(method, url, body);
    }
    const text = await response.text();
    let data: any = {};
    try {
        data = text ? JSON.parse(text) : {};
    } catch {
        data = { message: response.status === 419 ? 'Your session has expired. Reload the page and sign in again.' : undefined };
    }
    if (!response.ok) {
        const firstError = data.errors ? (Object.values(data.errors)[0] as string[])?.[0] : undefined;
        throw new ApiError(data.message && data.message !== 'The given data was invalid.' ? data.message : (firstError ?? 'Something went wrong. Try again.'), response.status, data.code ?? (response.status === 422 ? 'validation_failed' : 'error'), data.errors ?? {}, data.details ?? {});
    }
    return data as T;
}

export const get = <T = any>(url: string) => api<T>('GET', url);
export const post = <T = any>(url: string, body?: unknown) => api<T>('POST', url, body ?? {});
export const put = <T = any>(url: string, body?: unknown) => api<T>('PUT', url, body ?? {});
export const del = <T = any>(url: string) => api<T>('DELETE', url);

export function uuid(): string {
    return crypto.randomUUID ? crypto.randomUUID() : 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2);
}
