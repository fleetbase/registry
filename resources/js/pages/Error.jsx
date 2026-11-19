import { Link, usePage } from '@inertiajs/react';
import SiteLayout from '../layouts/SiteLayout';
import { EmptyState } from '../components/Ui';

const COPY = {
    403: { icon: 'lock', title: 'You can’t open this page', body: 'Your account doesn’t have access to it.' },
    404: { icon: 'search', title: 'Nothing here', body: 'This page, extension or publisher doesn’t exist, or it isn’t published.' },
    500: { icon: 'alert', tone: 'fail', title: 'Something went wrong on our side', body: 'Nothing was saved or changed. Try again in a moment.' },
    503: { icon: 'alert', tone: 'fail', title: 'The registry is down for maintenance', body: 'It will be back shortly. Installed extensions keep working.' },
};

/** Website error pages: what happened, what is safe, and one next step. */
export default function Error({ status, message }) {
    const { auth } = usePage().props;
    const copy = COPY[status] ?? COPY[500];
    // A 403 with a reason ("The review console is for Fleetbase staff.") leads with that reason
    // and says who is signed in, so the visitor can tell whether they used the wrong account.
    const denied = status === 403 && message;
    const title = denied ? message.replace(/\.$/, '') : copy.title;
    const body = denied ? (auth?.account ? `You’re signed in as ${auth.account.email}. Looking for your own submissions?` : copy.body) : (message ?? copy.body);
    return (
        <SiteLayout title={title}>
            <div className="wrap" style={{ paddingTop: 64, paddingBottom: 96, maxWidth: 720 }}>
                <div className="card" data-error-status={status}>
                    <EmptyState
                        icon={copy.icon}
                        tone={copy.tone}
                        title={title}
                        actions={
                            status === 403 && auth?.account ? (
                                <Link href="/studio" className="btn btn-primary">
                                    Go to Studio
                                </Link>
                            ) : (
                                <Link href="/" className="btn btn-primary">
                                    Browse extensions
                                </Link>
                            )
                        }
                    >
                        {body}
                    </EmptyState>
                </div>
            </div>
        </SiteLayout>
    );
}
