import Icon from './Icon';

/** The three trust marks; each means one fact the registry can prove. */
export function Verified({ short = false, className = '' }) {
    return (
        <span className={`trust ${className}`} title="Fleetbase confirmed who the publisher is">
            <Icon name="shield" size={16} />
            {short ? 'Verified' : 'Verified publisher'}
        </span>
    );
}

export function Reviewed({ short = false, className = '' }) {
    return (
        <span className={`trust ${className}`} title="A person at Fleetbase reviewed the latest release">
            <Icon name="reviewed" size={16} />
            {short ? 'Reviewed' : 'Reviewed by Fleetbase'}
        </span>
    );
}

export function WorksWith({ version, className = '' }) {
    return (
        <span className={`trust ${className}`} title="The latest release's console range includes this version">
            <Icon name="monitor" size={16} />
            Works with {version}
        </span>
    );
}
