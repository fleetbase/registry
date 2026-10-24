/**
 * Stroke icons from the design (24 px grid, currentColor). Decorative unless `label` is given.
 */
const PATHS = {
    search: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM20 20l-3.5-3.5',
    moon: 'M20 14.5A8 8 0 0 1 9.5 4 7 7 0 1 0 20 14.5z',
    sun: 'M12 4V2M12 22v-2M4 12H2M22 12h-2M5.6 5.6 4.2 4.2M19.8 19.8l-1.4-1.4M5.6 18.4l-1.4 1.4M19.8 4.2l-1.4 1.4M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10z',
    user: 'M12 4a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM4 21a8 8 0 0 1 16 0',
    check: 'M5 12l5 5L20 7',
    x: 'M7 7l10 10M17 7L7 17',
    close: 'M6 6l12 12M18 6L6 18',
    plus: 'M12 5v14M5 12h14',
    minus: 'M8 12h8',
    arrowUp: 'M12 19V6M6 11l6-6 6 6',
    arrowLeft: 'M15 6l-6 6 6 6',
    arrowRight: 'M9 6l6 6-6 6',
    chevronDown: 'M6 9l6 6 6-6',
    clock: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7v5l3 2',
    eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
    alert: 'M12 8v5M12 16h.01M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z',
    warning: 'M12 9v4M12 17h.01M10.3 3.9L2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
    info: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 11v5M12 8h.01',
    ban: 'M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16zM6.5 6.5l11 11',
    pencil: 'M4 20h4L19 9l-4-4L4 16z',
    copy: 'M9 9h11v11H9zM5 15V5a1 1 0 0 1 1-1h10',
    download: 'M12 4v12M6 11l6 6 6-6M4 20h16',
    upload: 'M12 19V6M6 11l6-6 6 6M4 20h16',
    trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',
    send: 'M22 2L11 13M22 2l-7 20-4-9-9-4z',
    globe: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18',
    shield: 'M12 3l7 3v5c0 4.5-3 8.3-7 10-4-1.7-7-5.5-7-10V6zM9 12l2 2 4-4',
    reviewed: 'M11 5a6 6 0 1 0 0 12 6 6 0 0 0 0-12zM20 20l-4.3-4.3M8.5 11l1.8 1.8 3.2-3.3',
    monitor: 'M3 4h18v14H3zM8 21h8M9 11l2 2 4-4',
    terminal: 'M4 5h16v14H4zM8 10l3 2-3 2M13 15h3',
    box: 'M4 7l8-4 8 4-8 4zM4 7v10l8 4 8-4V7M12 11v10',
    file: 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8zM14 3v5h5',
    key: 'M8 11a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM11 12l9-9M17 6l3 3M14 9l2 2',
    mail: 'M3 5h18v14H3zM3 7l9 6 9-6',
    lock: 'M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4',
    image: 'M3 3h18v18H3zM8 14l3-3 5 5M9 7a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3z',
    gavel: 'M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z',
    building: 'M4 21V5h10v16M14 9h6v12M8 9h2M8 13h2M8 17h2',
    wallet: 'M4 6h16v12H4zM4 10h16M16 14h2',
    chart: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
    truck: 'M5 17h14l-1.5-6h-11zM7 17v2M17 17v2M8 11l1-4h6l1 4',
    warehouse: 'M3 10l9-6 9 6v10H3zM8 20v-6h8v6',
    route: 'M6 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM18 9a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM8 17h7a3 3 0 0 0 0-6H9a3 3 0 0 1 0-6h7',
    card: 'M4 6h16v12H4zM4 10h16M8 15h3',
    ledger: 'M5 4h14v16H5zM9 8h6M9 12h6M9 16h3',
    chat: 'M4 5h16v10H8l-4 4z',
    logo: 'M8.5 18H14a4 4 0 0 0 0-8h-4a4 4 0 0 1 0-8h5.5M6 15.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM18 3.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z',
    menu: 'M4 7h16M4 12h16M4 17h16',
    external: 'M14 4h6v6M20 4l-9 9M18 14v6H4V6h6',
    spinner: 'M12 4a8 8 0 1 0 8 8',
};

export default function Icon({ name, size = 18, strokeWidth = 2, label, className = '', spin = false }) {
    const d = PATHS[name];
    if (!d) {
        return null;
    }
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={strokeWidth}
            strokeLinecap="round"
            strokeLinejoin="round"
            className={`${className} ${spin || name === 'spinner' ? 'spin' : ''}`.trim()}
            role={label ? 'img' : undefined}
            aria-label={label}
            aria-hidden={label ? undefined : 'true'}
        >
            <path d={d} />
        </svg>
    );
}

export const ICONS = Object.keys(PATHS);
