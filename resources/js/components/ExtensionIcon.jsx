import { iconTone, initials } from '../lib/format';

/** The listing's icon image, else its initials on a stable gradient. */
export default function ExtensionIcon({ extension, size = '', className = '' }) {
    const url = extension?.icon_url;
    return (
        <span className={`ext-icon ${size} ${url ? '' : iconTone(extension?.slug ?? extension?.name)} ${className}`} aria-hidden="true" style={url ? { background: 'var(--surface-2)', border: '1px solid var(--line)' } : undefined}>
            {url ? <img src={url} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover', borderRadius: 'inherit' }} /> : initials(extension?.name)}
        </span>
    );
}
