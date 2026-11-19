import { liveRelease, openRelease } from '../../lib/statuses';
import { plural } from '../../lib/format';
import { daysAgo, submittedAgo } from './submitted';

/** Studio URLs for a listing. */
export const studioUrl = {
    editor: (extension) => `/studio/${extension.slug}`,
    releases: (extension) => `/studio/${extension.slug}/releases`,
    release: (extension, release) => `/studio/${extension.slug}/releases/${release.id}`,
    review: (extension) => `/studio/${extension.slug}/review`,
    history: (extension) => `/studio/${extension.slug}/history`,
};

const WITH_FLEETBASE = ['submitted', 'in_review', 'approved'];

/** Everything the Studio home shows for one listing, derived from its release summaries. */
export function listingState(extension) {
    const releases = extension.releases ?? [];
    const open = openRelease(releases);
    const live = liveRelease(releases);
    const missing = extension.readiness?.missing ?? [];
    const incomplete = extension.status === 'draft' && missing.length > 0;

    let action;
    if (open?.status === 'rejected') {
        action = { label: 'See feedback', href: studioUrl.review(extension), primary: true };
    } else if (open?.status === 'failed_checks') {
        action = { label: 'Fix checks', href: studioUrl.release(extension, open), primary: true };
    } else if (incomplete) {
        action = { label: 'Finish listing', href: studioUrl.editor(extension), primary: true };
    } else if (open) {
        action = { label: 'View review', href: studioUrl.review(extension), primary: open.status === 'uploaded' };
    } else {
        action = { label: 'New release', href: studioUrl.releases(extension), primary: false };
    }

    return { open, live, missing, incomplete, action };
}

/**
 * The Studio inbox: "Your move" (changes requested, failed checks, a release ready to submit,
 * a draft listing that still needs something) and "With Fleetbase" (submitted, in review,
 * approved). One entry per listing, the most urgent.
 */
export function groupInbox(extensions = [], now = new Date()) {
    const yours = [];
    const fleetbase = [];

    for (const extension of extensions) {
        const { open, missing, incomplete } = listingState(extension);
        const base = { extension, release: open };
        const name = extension.name;
        const version = open ? `v${open.version}` : '';

        if (open?.status === 'rejected') {
            yours.push({ ...base, kind: 'rejected', title: `${name} ${version}: changes requested`, detail: 'Read the reviewer’s notes, fix, upload again and resubmit.', href: studioUrl.review(extension) });
        } else if (open?.status === 'failed_checks') {
            yours.push({ ...base, kind: 'failed_checks', title: `${name} ${version}: checks failed`, detail: 'Fix what the checks found and upload the same version again.', href: studioUrl.release(extension, open) });
        } else if (open?.status === 'uploaded' && missing.length > 0) {
            yours.push({ ...base, kind: 'incomplete', title: `${name}: listing needs ${plural(missing.length, 'thing')}`, detail: `${summarizeMissing(missing)} Then submit ${version}.`, href: studioUrl.editor(extension) });
        } else if (open?.status === 'uploaded') {
            yours.push({ ...base, kind: 'ready', title: `${name} ${version} is ready to submit`, detail: 'Checks passed and the listing is complete.', href: studioUrl.review(extension) });
        } else if (open && WITH_FLEETBASE.includes(open.status)) {
            const title = { submitted: `${name} ${version} is waiting for review`, in_review: `${name} ${version} is in review`, approved: `${name} ${version} is approved` }[open.status];
            const detail =
                open.status === 'approved'
                    ? [open.decided_at && `Approved ${daysAgo(open.decided_at, now)}.`, 'It goes live when Fleetbase publishes it.'].filter(Boolean).join(' ')
                    : [submittedAgo(open, now) && `${submittedAgo(open, now)}.`, 'Reviews usually take a few business days.'].filter(Boolean).join(' ');
            fleetbase.push({ ...base, kind: open.status, title, detail, href: studioUrl.review(extension) });
        } else if (incomplete) {
            yours.push({ ...base, kind: 'incomplete', title: `${name}: listing needs ${plural(missing.length, 'thing')}`, detail: summarizeMissing(missing), href: studioUrl.editor(extension) });
        }
    }

    return { yours, fleetbase };
}

/** "Support URL, privacy policy URL, an icon." from the server's readiness messages. */
export function summarizeMissing(missing) {
    const short = missing.map((message) =>
        message
            .replace(/^The listing needs /, '')
            .replace(/\.$/, '')
            .replace(/^a valid /, '')
            .replace(/^at least /, '')
    );
    const shown = short.slice(0, 3);
    const text = shown.join(', ') + (short.length > 3 ? `, and ${short.length - 3} more` : '');
    return text ? `${text.charAt(0).toUpperCase()}${text.slice(1)}.` : '';
}
