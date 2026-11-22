/**
 * Minimal JSON client for same-origin endpoints that are not Inertia page
 * visits (editor autosave, upload registration). Sends Laravel's CSRF token
 * and turns validation responses into readable errors.
 */

export class HttpError extends Error {
    constructor(
        message: string,
        public status: number,
        public errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export async function requestJson<T>(
    method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    url: string,
    body?: unknown,
): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    const data = (await response.json().catch(() => ({}))) as {
        message?: string;
        errors?: Record<string, string[]>;
    };

    if (!response.ok) {
        const errors = data.errors ?? {};
        const firstError = Object.values(errors)[0]?.[0];

        throw new HttpError(
            firstError ??
                data.message ??
                (response.status === 419
                    ? 'Your session expired. Reload the page.'
                    : `Request failed (${response.status}).`),
            response.status,
            errors,
        );
    }

    return data as T;
}

export const postJson = <T>(url: string, body?: unknown) =>
    requestJson<T>('POST', url, body ?? {});

/**
 * GET a JSON endpoint (same-origin, with the session cookie).
 */
export async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new HttpError(
            `Request failed (${response.status}).`,
            response.status,
        );
    }

    return (await response.json()) as T;
}
