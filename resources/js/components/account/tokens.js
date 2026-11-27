import { TOKEN_SCOPES } from '../../lib/statuses';

const DAY = 24 * 60 * 60 * 1000;

export const EXPIRY_DAYS = [30, 90, 180, 365];

/** Tokens close to expiry get the amber chip. */
export const EXPIRY_WARNING_DAYS = 14;

/** The scopes a token can be given; staff also get `review`. */
export function tokenScopes(isStaff = false) {
    return {
        ...TOKEN_SCOPES,
        ...(isStaff ? { review: { label: 'Review', description: 'Staff only: review releases and decide on them' } } : {}),
    };
}

/** The date a token created now for `days` days stops working. */
export function expiryDate(days, now = new Date()) {
    return new Date(now.getTime() + days * DAY);
}

/** Whole days until `value`, rounded up (0 = today or already past). */
export function daysLeft(value, now = new Date()) {
    if (!value) {
        return null;
    }
    const ms = new Date(value).getTime() - now.getTime();
    return Math.max(0, Math.ceil(ms / DAY));
}

/** `flbp_7Kq2…a1b2`: the prefix and a few characters each side. */
export function maskToken(token) {
    const value = String(token ?? '');
    const prefix = value.includes('_') ? value.slice(0, value.indexOf('_') + 1) : '';
    const body = value.slice(prefix.length);
    return body.length <= 8 ? value : `${prefix}${body.slice(0, 4)}…${body.slice(-4)}`;
}

/** The token with its middle hidden, keeping its length: `flbp_••••…a1b2`. */
export function hideToken(token) {
    const value = String(token ?? '');
    const prefix = value.includes('_') ? value.slice(0, value.indexOf('_') + 1) : '';
    const body = value.slice(prefix.length);
    return `${prefix}${'•'.repeat(Math.max(0, body.length - 4))}${body.slice(-4)}`;
}

export function scopeLabel(scope, isStaff = false) {
    if (scope === '*') {
        return 'all scopes';
    }
    return tokenScopes(isStaff)[scope]?.label?.toLowerCase() ?? scope;
}
