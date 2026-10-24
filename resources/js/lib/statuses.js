/**
 * Status vocabulary from the design: five families, each an icon and a word. `tone` maps to the
 * .st-* chip classes: ok (done), wait (with Fleetbase), you (your move), fail, idle (inactive).
 */
export const RELEASE_STATUSES = {
    uploaded: { tone: 'you', icon: 'arrowUp', label: 'Ready to submit', hint: 'Checks passed. Submit when the listing is ready.' },
    checking: { tone: 'wait', icon: 'spinner', label: 'Checking', hint: 'Eight automated checks are running.' },
    failed_checks: { tone: 'fail', icon: 'x', label: 'Checks failed', hint: 'Fix and upload the same version again.' },
    submitted: { tone: 'wait', icon: 'clock', label: 'Waiting for review', hint: 'In the queue. Nothing to do.' },
    in_review: { tone: 'wait', icon: 'eye', label: 'In review', hint: 'A reviewer has it open.' },
    approved: { tone: 'wait', icon: 'check', label: 'Approved, publishing', hint: 'Approved. Goes live when Fleetbase publishes.' },
    rejected: { tone: 'fail', icon: 'x', label: 'Changes requested', hint: 'Read the reasons, fix, resubmit.' },
    published: { tone: 'ok', icon: 'check', label: 'Live', hint: 'In the catalog, npm and Composer.' },
    yanked: { tone: 'idle', icon: 'minus', label: 'Yanked', hint: 'Hidden from new installs; lockfiles still work.' },
};

export const LISTING_STATUSES = {
    draft: { tone: 'idle', icon: 'pencil', label: 'Draft' },
    in_review: { tone: 'wait', icon: 'eye', label: 'In review' },
    approved: { tone: 'wait', icon: 'check', label: 'Approved' },
    rejected: { tone: 'fail', icon: 'x', label: 'Changes requested' },
    published: { tone: 'ok', icon: 'check', label: 'Published' },
    unpublished: { tone: 'idle', icon: 'minus', label: 'Unpublished' },
    suspended: { tone: 'fail', icon: 'ban', label: 'Suspended' },
};

/** The eight automated checks, in the order the service runs them. */
export const CHECKS = {
    manifest: { label: 'Manifest', description: 'extension.json validates against the schema' },
    compat: { label: 'Compatibility', description: 'console and api ranges parse' },
    package_names: { label: 'Package names', description: 'archives match the listing’s packages' },
    version: { label: 'Version', description: 'greater than every release on the channel' },
    size: { label: 'Size', description: 'archives within the size and entry limits' },
    archive: { label: 'Archive safety', description: 'no traversal, links, devices or binaries' },
    secrets_scan: { label: 'Secrets scan', description: 'no credentials or keys in the archives' },
    dist: { label: 'Prebuilt engine', description: 'dist manifest and hashes, when uploaded' },
};

/** Releases still moving through the pipeline. */
export const OPEN_RELEASE_STATUSES = ['uploaded', 'checking', 'failed_checks', 'submitted', 'in_review', 'approved', 'rejected'];

export const HOSTINGS = {
    both: 'Cloud and self-hosted',
    cloud: 'Fleetbase Cloud only',
    self_hosted: 'Self-hosted only',
};

export const ROLES = {
    owner: { label: 'Owner', description: 'Everything, including roles' },
    admin: { label: 'Admin', description: 'Members, listings, releases' },
    publisher: { label: 'Publisher', description: 'Listings and releases' },
    billing: { label: 'Billing', description: 'Payouts and purchases' },
    member: { label: 'Member', description: 'Read only' },
};

export const TOKEN_SCOPES = {
    publish: { label: 'Publish', description: 'flb publish: create listings, upload releases, submit' },
    install: { label: 'Install', description: 'Download paid packages with npm, Composer and flb install' },
    read: { label: 'Read', description: 'Read your account and listings' },
    'instance:register': { label: 'Register instances', description: 'Mint codes to connect a Fleetbase instance' },
};

export function releaseStatus(status) {
    return RELEASE_STATUSES[status] ?? { tone: 'idle', icon: 'minus', label: status ?? 'Unknown' };
}

export function listingStatus(status) {
    return LISTING_STATUSES[status] ?? { tone: 'idle', icon: 'minus', label: status ?? 'Unknown' };
}

/** The release that matters most for a listing: the newest still in the pipeline. */
export function openRelease(releases = []) {
    return [...releases].sort((a, b) => new Date(b.created_at) - new Date(a.created_at)).find((release) => OPEN_RELEASE_STATUSES.includes(release.status)) ?? null;
}

export function liveRelease(releases = []) {
    return [...releases].filter((release) => release.status === 'published').sort((a, b) => new Date(b.published_at) - new Date(a.published_at))[0] ?? null;
}

/** A publisher organization's Stripe Connect payout status (`account.updated`). */
export const PAYOUT_STATUSES = {
    enabled: { tone: 'ok', icon: 'check', label: 'Payouts on', hint: 'Sales are paid out on your schedule.' },
    pending: { tone: 'you', icon: 'arrowRight', label: 'Finish setup', hint: 'Stripe needs your business and bank details.' },
    restricted: { tone: 'you', icon: 'alert', label: 'Action needed', hint: 'Stripe needs more information before it pays out.' },
    rejected: { tone: 'fail', icon: 'x', label: 'Rejected', hint: 'Stripe closed this account. Contact Fleetbase.' },
};

/** A purchase's status. */
export const PURCHASE_STATUSES = {
    paid: { tone: 'ok', icon: 'check', label: 'Paid' },
    refunded: { tone: 'idle', icon: 'minus', label: 'Refunded' },
    disputed: { tone: 'fail', icon: 'alert', label: 'Disputed' },
};

export function payoutStatus(status) {
    return PAYOUT_STATUSES[status] ?? { tone: 'idle', icon: 'minus', label: 'Not set up', hint: 'Connect a Stripe account to sell extensions.' };
}

export function purchaseStatus(status) {
    return PURCHASE_STATUSES[status] ?? { tone: 'idle', icon: 'minus', label: status };
}
