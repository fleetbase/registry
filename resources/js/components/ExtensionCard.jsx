import { Link } from '@inertiajs/react';
import ExtensionIcon from './ExtensionIcon';
import { Verified, WorksWith } from './Trust';
import { isPaid, priceLabel, shortDate } from '../lib/format';

/** A catalog card. The whole card is one link to the extension page. */
export default function ExtensionCard({ extension, console: consoleVersion }) {
    const paid = isPaid(extension.price);
    return (
        <Link href={`/extensions/${extension.slug}`} className="ext-card" data-extension={extension.slug}>
            <div className="row" style={{ alignItems: 'flex-start' }}>
                <ExtensionIcon extension={extension} />
                <div className="grow">
                    <div className="h4">{extension.name}</div>
                    <div className="small muted">{extension.publisher?.name}</div>
                </div>
                <span className={`price ${paid ? '' : 'price-free'}`}>{priceLabel(extension.price)}</span>
            </div>
            <p className="small ink2" style={{ minHeight: 44 }}>
                {extension.subtitle}
            </p>
            <div className="row wrapflex" style={{ gap: 14 }}>
                {consoleVersion && extension.compatible === true && <WorksWith version={consoleVersion} className="xs" />}
                {extension.publisher?.is_verified && <Verified short className="xs" />}
                {extension.hosting === 'self_hosted' && <span className="tag">Self-hosted only</span>}
                {extension.price?.on_sale && <span className="tag">On sale</span>}
            </div>
            <div className="row between xs muted">
                <span>
                    {extension.latest_version ? `v${extension.latest_version}` : 'No release'}
                    {extension.category ? ` · ${extension.category.name}` : ''}
                </span>
                <span className="num">{extension.installs_count} installs</span>
            </div>
            <span className="sr-only">Updated {shortDate(extension.updated_at)}</span>
        </Link>
    );
}
