import Icon from './Icon';

const STATE_ICON = { done: 'check', now: 'eye', you: 'alert', fail: 'x' };

/**
 * The waypoint route: a release's stops (uploaded, checks, submitted, review, live). Each stop is
 * `{ label, sub?, state: 'done'|'now'|'you'|'fail'|'todo', icon? }`. `mini` draws dots only.
 */
export default function Route({ stops, mini = false, label, className = '' }) {
    return (
        <ol className={`route ${mini ? 'mini' : ''} ${className}`} aria-label={label} style={{ listStyle: 'none', margin: 0, padding: 0 }}>
            {stops.map((stop, index) => (
                <li key={index} className={`stop ${stop.state === 'todo' ? '' : stop.state}`} aria-current={stop.state === 'now' || stop.state === 'you' ? 'step' : undefined}>
                    <span className="dot">{!mini && (stop.icon || STATE_ICON[stop.state]) && <Icon name={stop.icon || STATE_ICON[stop.state]} strokeWidth={2.6} />}</span>
                    {!mini || stop.label ? <span className="lbl">{stop.label}</span> : null}
                    {!mini && stop.sub && <span className="sub">{stop.sub}</span>}
                    <span className="sr-only">{srState(stop.state)}</span>
                </li>
            ))}
        </ol>
    );
}

function srState(state) {
    return { done: ', done', now: ', in progress', you: ', your move', fail: ', failed', todo: ', not yet' }[state] ?? '';
}

/**
 * The route for a release, from its status: uploaded → checks → submitted → review → live.
 */
export function releaseStops(release, { submittedAt } = {}) {
    const status = release?.status;
    const order = ['uploaded', 'checks', 'submitted', 'review', 'live'];
    const reached = {
        checking: 1,
        failed_checks: 1,
        uploaded: 2,
        submitted: 3,
        in_review: 3,
        approved: 4,
        rejected: 3,
        published: 5,
        yanked: 5,
    }[status] ?? 0;
    const stops = order.map((key, index) => ({ key, state: index < reached ? 'done' : 'todo' }));
    const set = (key, state, sub) => Object.assign(stops.find((s) => s.key === key), { state, sub });

    set('uploaded', 'done');
    if (status === 'checking') set('checks', 'now', 'running');
    if (status === 'failed_checks') set('checks', 'fail', 'failed');
    if (['uploaded', 'submitted', 'in_review', 'approved', 'rejected', 'published', 'yanked'].includes(status)) set('checks', 'done', 'passed');
    if (status === 'uploaded') set('submitted', 'you', 'your move');
    if (['submitted', 'in_review', 'approved', 'rejected', 'published', 'yanked'].includes(status)) set('submitted', 'done', submittedAt);
    if (status === 'submitted') set('review', 'now', 'waiting');
    if (status === 'in_review') set('review', 'now', 'in review');
    if (status === 'rejected') set('review', 'fail', 'changes requested');
    if (status === 'approved') {
        set('review', 'done', 'approved');
        set('live', 'now', 'publishing');
    }
    if (status === 'published') set('live', 'done');

    const labels = { uploaded: 'Uploaded', checks: 'Checks', submitted: 'Submitted', review: 'Review', live: 'Live' };
    return stops.map((stop) => ({ ...stop, label: labels[stop.key], icon: stop.state === 'now' && stop.key === 'checks' ? 'spinner' : undefined }));
}
