import Icon from './Icon';
import { dateTime, humanize } from '../lib/format';

const ACTIONS = {
    upload: { icon: 'arrowUp', text: 'Uploaded' },
    check: { icon: 'spinner', text: 'Checks started', tone: 'wait' },
    pass_checks: { icon: 'check', text: 'Automated checks passed', tone: 'ok' },
    fail_checks: { icon: 'x', text: 'Automated checks failed', tone: 'fail' },
    submit: { icon: 'send', text: 'Submitted for review', tone: 'wait' },
    claim: { icon: 'eye', text: 'Review started', tone: 'wait' },
    approve: { icon: 'check', text: 'Approved', tone: 'ok' },
    reject: { icon: 'x', text: 'Changes requested', tone: 'fail' },
    publish: { icon: 'globe', text: 'Published', tone: 'ok' },
    withdraw: { icon: 'arrowLeft', text: 'Withdrawn from review' },
    yank: { icon: 'minus', text: 'Yanked' },
};

function who(entry) {
    if (entry.by?.name) {
        return entry.by.name;
    }
    return { system: 'Registry', staff: 'Fleetbase review team', publisher: 'Publisher' }[entry.actor] ?? null;
}

/**
 * Review timeline entries (`reviews` rows), newest first, with reasons and failed R-items. Entries
 * arrive oldest first and several can share a second, so ties keep that order reversed.
 */
export default function Timeline({ entries = [], itemLabels = {} }) {
    const sorted = entries
        .map((entry, index) => ({ entry, index }))
        .sort((a, b) => new Date(b.entry.created_at) - new Date(a.entry.created_at) || b.index - a.index)
        .map(({ entry }) => entry);

    if (!sorted.length) {
        return <p className="small muted">Nothing yet.</p>;
    }

    return (
        <ol className="tl" style={{ listStyle: 'none', margin: 0, padding: 0 }}>
            {sorted.map((entry, index) => {
                const action = ACTIONS[entry.action] ?? { icon: entry.icon ?? 'minus', text: entry.text ?? humanize(entry.action) };
                const failed = (entry.items ?? []).filter((item) => item.passed === false);
                return (
                    <li key={index} className="tl-item" data-action={entry.action}>
                        <span className={`tl-dot ${action.tone ?? ''}`}>
                            <Icon name={action.icon} strokeWidth={2.4} />
                        </span>
                        <div>
                            <div className="tl-title">
                                {action.text}
                                {entry.version && ` · v${entry.version}`}
                                {failed.length > 0 && <span style={{ color: 'var(--fail)', fontWeight: 500 }}> · {failed.length} to fix</span>}
                            </div>
                            <div className="tl-meta">
                                {[who(entry), dateTime(entry.created_at)].filter(Boolean).join(' · ')}
                            </div>
                            {entry.reason && <div className="quote" style={{ whiteSpace: 'pre-line' }}>{entry.reason}</div>}
                            {failed.length > 0 && (
                                <ul className="col" style={{ listStyle: 'none', margin: '8px 0 0', padding: 0, gap: 6 }}>
                                    {failed.map((item) => (
                                        <li key={item.code} className="small" data-item={item.code}>
                                            <strong className="mono">{item.code}</strong> {itemLabels[item.code] ?? ''}
                                            {item.note && <span className="muted">: {item.note}</span>}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
