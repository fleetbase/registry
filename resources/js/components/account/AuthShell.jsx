import { Link } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import './account.css';
import logoMark from '../../../images/fleetbase-icon.svg';

/** Sign in, create account and verify: one card, centred under the logo. */
export default function AuthShell({ title, labelledBy, onSubmit, children }) {
    return (
        <SiteLayout title={title}>
            <div className="wrap page">
                <div className="col" style={{ alignItems: 'center', gap: 16, paddingTop: 16 }}>
                    <div className="col" style={{ alignItems: 'center', gap: 6, textAlign: 'center' }}>
                        <Link href="/" className="logo" aria-label="Fleetbase Extensions home">
                            <img className="logo-mark" src={logoMark} alt="" width={28} height={28} />
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
