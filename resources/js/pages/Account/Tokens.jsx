import { useCallback, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import SiteLayout from '../../layouts/SiteLayout';
import AccountHeader from '../../components/account/AccountHeader';
import NewTokenForm from '../../components/account/NewTokenForm';
import TokenOnceDialog from '../../components/account/TokenOnceDialog';
import { EXPIRY_WARNING_DAYS, daysLeft, scopeLabel } from '../../components/account/tokens';
import CopyCommand from '../../components/CopyCommand';
import Dialog from '../../components/Dialog';
import Icon from '../../components/Icon';
import { StatusChip } from '../../components/Status';
import { useToast } from '../../components/Toasts';
import { EmptyState } from '../../components/Ui';
import { api } from '../../lib/api';
import { shortDate, timeAgo } from '../../lib/format';

/**
 * Personal tokens for the CLI, CI, npm and Composer: the list (masked), a form for a new one,
 * the new token shown once, and revoking.
 */
export default function Tokens({ tokens = [] }) {
    return (
        <SiteLayout title="Tokens">
            <TokensPage tokens={tokens} />
        </SiteLayout>
    );
}

function TokensPage({ tokens }) {
    const { auth, registry } = usePage().props;
    const account = auth.account;
    const organizations = account.organizations ?? [];
    const toast = useToast();
    const [form, setForm] = useState(tokens.length === 0 ? { initial: null } : null);
    const [created, setCreated] = useState(null);
    const [revoking, setRevoking] = useState(null);
    const [busy, setBusy] = useState(false);

    const organizationName = (id) => organizations.find((organization) => organization.id === id)?.name ?? null;

    function onCreated(response) {
        setForm(null);
        setCreated(response);
        router.reload({ only: ['tokens'] });
    }

    // Closing forgets the plaintext: it is in no state, prop or element after this.
    const closeCreated = useCallback(() => setCreated(null), []);
    const closeRevoking = useCallback(() => setRevoking(null), []);

    async function revoke() {
        setBusy(true);
        try {
            await api.delete(`account/tokens/${revoking.id}`);
            toast(`“${revoking.name}” was revoked.`);
            setRevoking(null);
            router.reload({ only: ['tokens'] });
        } catch (error) {
            toast(error.message, { type: 'error' });
        } finally {
            setBusy(false);
        }
    }

    function openForm(initial = null) {
        setForm({ initial, key: Date.now() });
        window.scrollTo?.({ top: 0, behavior: 'smooth' });
    }

    return (
        <div className="wrap" style={{ paddingTop: 40, paddingBottom: 80 }}>
            <div className="stack-lg">
                <AccountHeader
                    title="Tokens"
                    current="tokens"
                    description="For the Fleetbase CLI, CI pipelines, npm and Composer. Each one is named, scoped and expires. You see a token in full once, when you create it."
                    actions={
                        !form && (
                            <button type="button" className="btn btn-primary" onClick={() => openForm()} data-new-token>
                                <Icon name="plus" />
                                New token
                            </button>
                        )
                    }
                />

                {form && <NewTokenForm key={form.key} organizations={organizations} isStaff={account.is_staff} initial={form.initial} onCreated={onCreated} onCancel={() => setForm(null)} />}

                {tokens.length > 0 ? (
                    <section className="card" aria-label="Your tokens">
                        <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                            {tokens.map((token) => (
                                <TokenRow key={token.id} token={token} organizationName={organizationName(token.organization)} isStaff={account.is_staff} onRevoke={() => setRevoking(token)} onReplace={() => openForm(token)} />
                            ))}
                        </ul>
                    </section>
                ) : (
                    !form && (
                        <EmptyState
                            icon="key"
                            title="No tokens yet"
                            actions={
                                <button type="button" className="btn btn-primary" onClick={() => openForm()}>
                                    New token
                                </button>
                            }
                        >
                            Create one for the CLI or your CI pipeline. It acts as you, with only the scopes you give it.
                        </EmptyState>
                    )
                )}

                <section className="card card-pad stack" aria-labelledby="h-use">
                    <h2 id="h-use" className="h4">
                        Use a token with the CLI
                    </h2>
                    <div className="stack">
                        <CopyCommand value="npm i -g @fleetbase/cli" label="install command" />
                        <CopyCommand value={`flb login --token <token> --registry ${registry.url}`} label="login command" />
                        <CopyCommand value="flb publish" label="publish command" />
                    </div>
                    <p className="small muted">
                        In CI, store the token as a secret named <code>FLB_TOKEN</code> instead of running <code>flb login</code>.
                    </p>
                </section>
            </div>

            {created && <TokenOnceDialog created={created} organizationName={organizationName(created.token_details?.organization)} registryUrl={registry.url} onClose={closeCreated} />}

            <Dialog
                open={Boolean(revoking)}
                title={`Revoke “${revoking?.name}”?`}
                labelledBy="revoke-token-title"
                onClose={closeRevoking}
                footer={
                    <>
                        <button type="button" className="btn" onClick={() => setRevoking(null)}>
                            Cancel
                        </button>
                        <button type="button" className="btn btn-danger-solid" onClick={revoke} disabled={busy} data-confirm-revoke>
                            {busy && <Icon name="spinner" spin />}
                            Revoke token
                        </button>
                    </>
                }
            >
                <p className="ink2">Anything using {revoking?.masked ? <code>{revoking.masked}</code> : 'it'} stops working straight away: the CLI, CI jobs, npm and Composer. This can’t be undone.</p>
            </Dialog>
        </div>
    );
}

function TokenRow({ token, organizationName, isStaff, onRevoke, onReplace }) {
    const left = daysLeft(token.expires_at);
    const expiring = left !== null && left <= EXPIRY_WARNING_DAYS;

    return (
        <li className="tok" data-token={token.id}>
            <div className="col" style={{ gap: 2, minWidth: 0 }}>
                <span className="h4" style={{ overflowWrap: 'anywhere' }}>
                    {token.name}
                </span>
                <span className="mono small muted">{token.masked}</span>
            </div>
            <div className="row wrapflex" style={{ gap: 6 }}>
                {(token.scopes ?? []).map((scope) => (
                    <span key={scope} className="tag">
                        {scopeLabel(scope, isStaff)}
                    </span>
                ))}
                <span className="tag">
                    <Icon name="building" size={12} />
                    <span style={{ marginLeft: 4 }}>{token.organization ? (organizationName ?? 'One organization') : 'All organizations'}</span>
                </span>
            </div>
            <div className="col small" style={{ gap: 2 }}>
                {token.last_used_at ? (
                    <>
                        <span>Used {timeAgo(token.last_used_at)}</span>
                        {token.last_used_ip && <span className="muted">from {token.last_used_ip}</span>}
                    </>
                ) : (
                    <span className="muted">Never used</span>
                )}
            </div>
            <div className="row wrapflex" style={{ gap: 8 }}>
                {expiring ? (
                    <StatusChip tone="you" icon="clock">
                        {left === 0 ? 'Expires today' : `Expires in ${left} ${left === 1 ? 'day' : 'days'}`}
                    </StatusChip>
                ) : (
                    token.expires_at && <span className="small muted">Expires {shortDate(token.expires_at)}</span>
                )}
                {expiring && (
                    <button type="button" className="btn btn-sm" style={{ minHeight: 44 }} onClick={onReplace} aria-label={`Replace ${token.name}`}>
                        Replace
                    </button>
                )}
                <button type="button" className="btn btn-sm btn-danger" style={{ minHeight: 44 }} onClick={onRevoke} aria-label={`Revoke ${token.name}`} data-revoke>
                    Revoke
                </button>
            </div>
        </li>
    );
}
