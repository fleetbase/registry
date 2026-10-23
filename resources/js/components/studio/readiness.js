import { openRelease } from '../../lib/statuses';

/**
 * Client-side mirror of app/Releases/ListingReadiness.php: what a listing needs before its first
 * release can be submitted, computed from the editor's unsaved draft so the "Ready for review?"
 * rail updates as the publisher types. `message` is the server's wording, so `missing` matches
 * `extension.readiness.missing` for the same listing. Published listings are not re-checked.
 *
 * The draft carries the listing's fields (`name`, `subtitle`, `description`, `promotional_text`,
 * `tags`, `copyright`, `website_url`, `support_url`, `privacy_url`, `category`, `fa_icon`,
 * `icon_url`, `status`); `category` is the selected category's slug (or the `category` object).
 */

/** PHP `mb_strlen(trim($value))`: code points after trimming PHP's whitespace set (" \t\n\r\0\x0B"). */
const PHP_WHITESPACE = new Set([' ', '\t', '\n', '\r', String.fromCharCode(0), String.fromCharCode(11)]);

export function trimmedLength(value) {
    const chars = [...String(value ?? '')];
    let start = 0;
    let end = chars.length;
    while (start < end && PHP_WHITESPACE.has(chars[start])) start++;
    while (end > start && PHP_WHITESPACE.has(chars[end - 1])) end--;
    return end - start;
}

/** `filter_var($value, FILTER_VALIDATE_URL)` plus `^https?://`, as the server checks links. */
export function isHttpUrl(value) {
    if (typeof value !== 'string' || !/^https?:\/\//i.test(value) || /\s/.test(value)) {
        return false;
    }
    try {
        const url = new URL(value);
        return (url.protocol === 'http:' || url.protocol === 'https:') && url.hostname.length > 0;
    } catch {
        return false;
    }
}

function hasCategory(category) {
    if (category && typeof category === 'object') {
        return Boolean(category.id || category.slug);
    }
    return Boolean(category);
}

/** The listing rules, in the server's order. `section` is the editor anchor for "Fix". */
const RULES = [
    { key: 'name', label: 'Name, 4+ characters', section: 's-basics', message: 'The listing needs a name longer than 3 characters.', test: (d) => trimmedLength(d.name) > 3 },
    { key: 'subtitle', label: 'Subtitle', section: 's-basics', message: 'The listing needs a subtitle.', test: (d) => trimmedLength(d.subtitle) > 0 },
    { key: 'description', label: 'Short description', section: 's-basics', message: 'The listing needs a description longer than 12 characters.', test: (d) => trimmedLength(d.description) > 12 },
    { key: 'promotional_text', label: 'Overview', section: 's-overview', message: 'The listing needs promotional text.', test: (d) => trimmedLength(d.promotional_text) > 0 },
    { key: 'tags', label: 'At least one tag', section: 's-basics', message: 'The listing needs at least one tag.', test: (d) => Array.isArray(d.tags) && d.tags.length > 0 },
    { key: 'copyright', label: 'Copyright', section: 's-links', message: 'The listing needs a copyright notice.', test: (d) => trimmedLength(d.copyright) > 0 },
    { key: 'website_url', label: 'Website URL', section: 's-links', message: 'The listing needs a valid website URL.', test: (d) => isHttpUrl(d.website_url) },
    { key: 'support_url', label: 'Support URL', section: 's-links', message: 'The listing needs a valid support URL.', test: (d) => isHttpUrl(d.support_url) },
    { key: 'privacy_url', label: 'Privacy policy URL', section: 's-links', message: 'The listing needs a valid privacy policy URL.', test: (d) => isHttpUrl(d.privacy_url) },
    { key: 'icon', label: 'Icon', section: 's-media', message: 'The listing needs an icon.', test: (d) => Boolean(d.icon_url) || Boolean(d.fa_icon) },
    { key: 'category', label: 'Category', section: 's-basics', message: 'The listing needs a category.', test: (d) => hasCategory(d.category) },
];

/** Releases whose checks have passed (or that already went further). */
const PASSED = ['uploaded', 'submitted', 'in_review', 'approved', 'rejected'];

/**
 * @returns {{ items: Array<{key, label, ok, section?, href?, message?}>, missing: string[], listingReady: boolean,
 *             done: number, total: number, ready: boolean, release: object|null }}
 */
export function readiness(draft = {}, releases = []) {
    const published = draft.status === 'published';
    const items = RULES.map((rule) => ({ key: rule.key, label: rule.label, section: rule.section, message: rule.message, ok: published || rule.test(draft) }));
    const missing = items.filter((item) => !item.ok).map((item) => item.message);

    const release = openRelease(releases);
    const releaseOk = Boolean(release && PASSED.includes(release.status));
    let releaseLabel = 'A release that passed its checks';
    if (release) {
        releaseLabel = releaseOk
            ? `Release v${release.version} passed checks`
            : release.status === 'checking'
              ? `Release v${release.version}: checks running`
              : `Release v${release.version}: checks failed`;
    }
    items.push({ key: 'release', label: releaseLabel, ok: releaseOk, href: release ? 'release' : 'releases' });

    const done = items.filter((item) => item.ok).length;
    return { items, missing, listingReady: missing.length === 0, done, total: items.length, ready: done === items.length, release };
}
