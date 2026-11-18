import Icon from './Icon';
import { listingStatus, releaseStatus } from '../lib/statuses';

/** A status chip: icon plus word, never colour alone. */
export function StatusChip({ tone = 'idle', icon, children, className = '', ...rest }) {
    return (
        <span className={`st st-${tone} ${className}`} {...rest}>
            {icon && <Icon name={icon} strokeWidth={2.4} />}
            {children}
        </span>
    );
}

export function ReleaseStatus({ status, ...rest }) {
    const s = releaseStatus(status);
    return (
        <StatusChip tone={s.tone} icon={s.icon} data-status={status} {...rest}>
            {s.label}
        </StatusChip>
    );
}

/**
 * "Private" for a private listing ("Private · going public" while a request to go public waits
 * for a reviewed release); nothing for a public one unless `showPublic`.
 */
export function VisibilityChip({ extension, showPublic = false, ...rest }) {
    if (extension?.visibility === 'private') {
        return (
            <StatusChip tone="idle" icon="lock" data-visibility="private" {...rest}>
                {extension.pending_visibility === 'public' ? 'Private · going public' : 'Private'}
            </StatusChip>
        );
    }
    return showPublic ? (
        <StatusChip tone="idle" icon="globe" data-visibility="public" {...rest}>
            Public
        </StatusChip>
    ) : null;
}

export function ListingStatus({ status, ...rest }) {
    const s = listingStatus(status);
    return (
        <StatusChip tone={s.tone} icon={s.icon} data-status={status} {...rest}>
            {s.label}
        </StatusChip>
    );
}
