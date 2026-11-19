import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { router, usePage } from '@inertiajs/react';
import Icon from './Icon';
import ExtensionIcon from './ExtensionIcon';
import { api } from '../lib/api';
import { priceLabel } from '../lib/format';

/**
 * ⌘K / Ctrl K from any page: searches extensions, publishers and categories as you type, and
 * for signed-in people doubles as navigation. ↑↓ move, Enter opens, ⌘/Ctrl Enter copies an
 * extension's install command.
 */
export default function CommandPalette({ open, onClose }) {
    const { auth } = usePage().props;
    const account = auth?.account;
    const [query, setQuery] = useState('');
    const [extensions, setExtensions] = useState([]);
    const [categories, setCategories] = useState([]);
    const [active, setActive] = useState(0);
    const [copied, setCopied] = useState(null);
    const input = useRef(null);

    useEffect(() => {
        if (!open) {
            return;
        }
        setQuery('');
        setActive(0);
        input.current?.focus();
        if (!categories.length) {
            api.get('catalog/categories').then(setCategories).catch(() => {});
        }
    }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const q = query.trim();
        const timer = setTimeout(() => {
            api.get('catalog/extensions', { q: q || undefined, limit: 5 })
                .then((result) => setExtensions(result.data ?? []))
                .catch(() => setExtensions([]));
        }, 120);
        return () => clearTimeout(timer);
    }, [query, open]);

    const items = useMemo(() => {
        const q = query.trim().toLowerCase();
        const list = extensions.map((extension) => ({ group: 'Extensions', key: `x-${extension.slug}`, extension, href: `/extensions/${extension.slug}`, title: extension.name, meta: `${extension.publisher?.name ?? ''} · ${priceLabel(extension.price)}` }));

        const publishers = new Map();
        extensions.forEach((extension) => extension.publisher && publishers.set(extension.publisher.slug, extension.publisher));
        [...publishers.values()].forEach((publisher) => list.push({ group: 'Publishers', key: `p-${publisher.slug}`, href: `/publishers/${publisher.slug}`, title: publisher.name, meta: publisher.is_verified ? 'Verified publisher' : 'Publisher', icon: 'building' }));

        categories
            .filter((category) => q && category.name.toLowerCase().includes(q))
            .slice(0, 3)
            .forEach((category) => list.push({ group: 'Categories', key: `c-${category.slug}`, href: `/?category=${category.slug}`, title: category.name, meta: `${category.extensions_count ?? 0} extensions`, icon: 'warehouse' }));

        const goto = account
            ? [
                  ['My extensions', '/studio', 'box'],
                  ['New extension', '/studio/new', 'plus'],
                  ['Tokens', '/account/tokens', 'key'],
                  ['Organizations', '/account/organizations', 'building'],
                  ...(account.is_staff ? [['Review queue', '/review', 'gavel']] : []),
              ]
            : [
                  ['Sign in', '/sign-in', 'user'],
                  ['Create an account', '/register', 'plus'],
              ];
        goto.filter(([title]) => !q || title.toLowerCase().includes(q)).forEach(([title, href, icon]) => list.push({ group: 'Go to', key: `g-${href}`, href, title, icon }));
        return list;
    }, [extensions, categories, query, account]);

    useEffect(() => setActive((index) => Math.min(index, Math.max(items.length - 1, 0))), [items.length]);

    if (!open) {
        return null;
    }

    function choose(item) {
        if (!item) {
            return;
        }
        onClose();
        router.visit(item.href);
    }

    async function copyInstall(item) {
        if (!item?.extension) {
            return;
        }
        try {
            await navigator.clipboard.writeText(`flb install ${item.extension.slug}`);
            setCopied(item.key);
        } catch {
            // Clipboard refused; Enter still opens it.
        }
    }

    function onKeyDown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            onClose();
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((index) => (index + 1) % Math.max(items.length, 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => (index - 1 + items.length) % Math.max(items.length, 1));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (event.metaKey || event.ctrlKey) {
                copyInstall(items[active]);
            } else {
                choose(items[active]);
            }
        }
    }

    let lastGroup = null;
    return createPortal(
        <div className="scrim" style={{ position: 'fixed', inset: 0, zIndex: 70, display: 'flex', justifyContent: 'center', alignItems: 'flex-start', padding: '10vh 16px 16px' }} onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <div className="dialog" role="dialog" aria-modal="true" aria-label="Search the registry" style={{ maxWidth: 640 }} onKeyDown={onKeyDown}>
                <div className="row" style={{ padding: '14px 16px', borderBottom: '1px solid var(--line)', gap: 12 }}>
                    <Icon name="search" size={20} className="muted" />
                    <input
                        ref={input}
                        className="grow"
                        role="combobox"
                        aria-expanded="true"
                        aria-controls="palette-results"
                        aria-activedescendant={items[active] ? `palette-${items[active].key}` : undefined}
                        aria-label="Search extensions, publishers and pages"
                        placeholder="Search extensions, publishers and pages"
                        value={query}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setActive(0);
                        }}
                        style={{ border: 0, outline: 0, background: 'transparent', color: 'var(--ink)', font: '400 18px/1 var(--font)', minHeight: 36 }}
                    />
                    <kbd className="kbd">Esc</kbd>
                </div>
                <ul id="palette-results" role="listbox" aria-label="Results" style={{ listStyle: 'none', margin: 0, padding: '6px 8px 8px', maxHeight: '55vh', overflowY: 'auto' }}>
                    {items.length === 0 && <li className="small muted" style={{ padding: 16 }}>No matches. Try a broader term.</li>}
                    {items.map((item, index) => {
                        const header = item.group !== lastGroup ? item.group : null;
                        lastGroup = item.group;
                        return (
                            <li key={item.key} role="presentation">
                                {header && <div className="xs muted" style={{ padding: '10px 12px 4px', fontWeight: 600 }}>{header}</div>}
                                <div
                                    id={`palette-${item.key}`}
                                    role="option"
                                    aria-selected={index === active}
                                    onMouseEnter={() => setActive(index)}
                                    onClick={() => choose(item)}
                                    className="row"
                                    style={{ gap: 12, minHeight: 52, padding: '0 12px', borderRadius: 10, cursor: 'pointer', background: index === active ? 'var(--accent-soft)' : 'transparent', outline: index === active ? '1px solid var(--accent-line)' : 'none' }}
                                >
                                    {item.extension ? (
                                        <ExtensionIcon extension={item.extension} size="sm" />
                                    ) : (
                                        <span className="ext-icon sm" style={{ background: 'var(--bg-tint)', color: 'var(--ink-2)' }} aria-hidden="true">
                                            <Icon name={item.icon} size={16} />
                                        </span>
                                    )}
                                    <span className="col grow" style={{ gap: 0 }}>
                                        <span className="h4">{item.title}</span>
                                        {item.meta && <span className="xs muted">{item.meta}</span>}
                                    </span>
                                    {copied === item.key ? <span className="xs" style={{ color: 'var(--ok)' }}>Install command copied</span> : index === active && <kbd className="kbd">↵</kbd>}
                                </div>
                            </li>
                        );
                    })}
                </ul>
                <div className="row between xs muted" style={{ padding: '10px 16px', borderTop: '1px solid var(--line)' }}>
                    <span className="row" style={{ gap: 8 }}>
                        <kbd className="kbd">↑</kbd>
                        <kbd className="kbd">↓</kbd> move <kbd className="kbd">↵</kbd> open <kbd className="kbd">⌘ ↵</kbd> copy install command
                    </span>
                </div>
            </div>
        </div>,
        document.body
    );
}
