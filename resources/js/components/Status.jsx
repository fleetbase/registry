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

export function ListingStatus({ status, ...rest }) {
    const s = listingStatus(status);
    return (
        <StatusChip tone={s.tone} icon={s.icon} data-status={status} {...rest}>
            {s.label}
        </StatusChip>
    );
}
