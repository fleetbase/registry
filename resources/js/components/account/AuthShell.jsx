import { Link } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import Icon from '../Icon';
import './account.css';

/** Sign in, create account and verify: one card, centred under the logo. */
export default function AuthShell({ title, labelledBy, onSubmit, children }) {
    return (
        <SiteLayout title={title}>
            <div className="wrap" style={{ paddingTop: 40, paddingBottom: 72 }}>
                <div className="col" style={{ alignItems: 'center', gap: 24 }}>
                    <div className="col" style={{ alignItems: 'center', gap: 8, textAlign: 'center' }}>
                        <Link href="/" className="logo" aria-label="Fleetbase Extensions home">
                            <span className="logo-mark" aria-hidden="true">
                                <Icon name="logo" size={16} strokeWidth={2.4} />
                            </span>
                            Fleetbase <span className="tag">Extensions</span>
                        </Link>
                        <span className="small muted">One account for publishing, tokens and organizations.</span>
                    </div>
                    <form method="post" className="card auth-card" aria-labelledby={labelledBy} onSubmit={onSubmit} noValidate>
                        {children}
                    </form>
                </div>
            </div>
        </SiteLayout>
    );
}
