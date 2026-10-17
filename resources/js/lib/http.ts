/**
 * JSON calls for the POS and look-ups (pages themselves use Inertia).
 * Errors come back as { message, code, errors? } and are thrown as ApiError.
 */
export class ApiError extends Error {
    constructor(message: string, public status: number, public code: string = 'error', public errors: Record<string, string[]> = {}, public details: Record<string, any> = {}) {
        super(message);
    }
}

function csrf(): string {
    return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
}

export async function api<T = any>(method: string, url: string, body?: unknown): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const text = await response.text();
    const data = text ? JSON.parse(text) : {};
    if (!response.ok) {
        const firstError = data.errors ? (Object.values(data.errors)[0] as string[])?.[0] : undefined;
        throw new ApiError(data.message && data.message !== 'The given data was invalid.' ? data.message : (firstError ?? 'Something went wrong. Try again.'), response.status, data.code ?? (response.status === 422 ? 'validation_failed' : 'error'), data.errors ?? {}, data.details ?? {});
    }
    return data as T;
}

export const get = <T = any>(url: string) => api<T>('GET', url);
export const post = <T = any>(url: string, body?: unknown) => api<T>('POST', url, body ?? {});
export const del = <T = any>(url: string) => api<T>('DELETE', url);

export function uuid(): string {
    return crypto.randomUUID ? crypto.randomUUID() : 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2);
}
