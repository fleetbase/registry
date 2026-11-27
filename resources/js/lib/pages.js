const pages = import.meta.glob(['../pages/**/*.jsx', '!../pages/**/*.test.jsx']);

export function resolvePage(name) {
    const page = pages[`../pages/${name}.jsx`];
    if (!page) {
        throw new Error(`Unknown page: ${name}`);
    }
    return page();
}

export function pageTitle(title) {
    return title ? `${title} · Fleetbase Extensions` : 'Fleetbase Extensions';
}
