import { useState } from 'react';
import CopyCommand from '../CopyCommand';
import Icon from '../Icon';
import { useToast } from '../Toasts';
import { Note } from '../Ui';
import { api } from '../../lib/api';
import { dateTime } from '../../lib/format';

/**
 * Connect a Fleetbase instance to an organization: owners and admins mint a one-time
 * registration code (POST /v1/account/instance-codes, valid 15 minutes) and paste it into the
 * console's registry settings, or pass it to `flb instance register`.
 */
export default function InstanceCodeCard({ organization }) {
    const toast = useToast();
    const [code, setCode] = useState(null);
    const [busy, setBusy] = useState(false);

    async function mint() {
        setBusy(true);
        try {
            setCode(await api.post('account/instance-codes', { organization: organization.slug }));
        } catch (error) {
            toast(error.message, { type: 'error' });
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="card" aria-labelledby="h-instances" data-instance-codes>
            <div className="card-head">
                <h2 id="h-instances" className="h4">
                    Fleetbase instances
                </h2>
                <button type="button" className="btn btn-sm" style={{ minHeight: 44 }} onClick={mint} disabled={busy} data-mint-instance-code>
                    {busy ? <Icon name="spinner" spin /> : <Icon name="plus" />}
                    {code ? 'New code' : 'Create registration code'}
                </button>
            </div>
            <div className="card-body stack">
                <p className="small ink2">
                    Register a Fleetbase console with {organization.name} so it can install, update and buy extensions with this organization’s licences.
                </p>
                {code ? (
                    <div className="stack" data-instance-code>
                        <CopyCommand value={code.code} prompt={null} label="registration code" />
                        <span className="small muted">Single use. Expires {dateTime(code.expires_at)}.</span>
                        <Note tone="info">
                            In the console, open <strong>Admin › Extensions Registry</strong>, paste the code and choose <strong>Register this instance</strong>. Or on the server that runs it, from your Fleetbase checkout:
                        </Note>
                        <CopyCommand value={`flb instance register --path /path/to/fleetbase --code ${code.code}`} label="register command" />
                    </div>
                ) : (
                    <span className="small muted">A code is valid for 15 minutes and works once.</span>
                )}
            </div>
        </section>
    );
}
