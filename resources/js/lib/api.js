import axios from 'axios';

/**
 * An error answered by the Registry Service, with its envelope: `errors` (messages),
 * `fields` (validation messages per field) and `code` when the service sets one.
 */
export class ApiError extends Error {
    constructor(status, payload = {}) {
        const errors = Array.isArray(payload?.errors) && payload.errors.length ? payload.errors : [status === 0 ? 'The registry could not be reached. Nothing was saved.' : 'Something went wrong.'];
        super(errors[0]);
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors;
        this.fields = payload?.fields ?? {};
        this.code = payload?.code ?? null;
        this.payload = payload;
    }

    /** First message for a field, e.g. `error.field('packages.npm')`. */
    field(name) {
        const messages = this.fields?.[name];
        return Array.isArray(messages) ? messages[0] : null;
    }
}

/**
 * The /v1 API on the same origin. The session cookie authenticates; axios sends Laravel's
 * X-XSRF-TOKEN header from the XSRF-TOKEN cookie on every unsafe request. A 419 (the CSRF
 * token rotated) refreshes the session once and retries.
 */
export const client = axios.create({
    baseURL: '/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

async function send(config, retried = false) {
    try {
        const response = await client.request(config);
        return response.data;
    } catch (error) {
        const status = error.response?.status ?? 0;
        if (status === 419 && !retried) {
            await client.get('auth/session');
            return send(config, true);
        }
        throw new ApiError(status, error.response?.data);
    }
}

export const api = {
    get: (url, params) => send({ method: 'get', url, params }),
    post: (url, data) => send({ method: 'post', url, data }),
    put: (url, data) => send({ method: 'put', url, data }),
    patch: (url, data) => send({ method: 'patch', url, data }),
    delete: (url, data) => send({ method: 'delete', url, data }),

    /** Multipart upload; `fields` is an object of strings, Files and booleans. */
    upload(url, fields, { onProgress } = {}) {
        const form = new FormData();
        for (const [key, value] of Object.entries(fields)) {
            if (value === undefined || value === null || value === '') {
                continue;
            }
            form.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : value);
        }
        return send({
            method: 'post',
            url,
            data: form,
            onUploadProgress: onProgress ? (event) => event.total && onProgress(Math.round((event.loaded / event.total) * 100)) : undefined,
        });
    },
};
