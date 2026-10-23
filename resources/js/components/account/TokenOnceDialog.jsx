import { useEffect, useRef, useState } from 'react';
import Dialog from '../Dialog';
import Icon from '../Icon';
import { shortDate } from '../../lib/format';
import { hideToken, maskToken, scopeLabel } from './tokens';
import './account.css';

/**
 * The new token, shown in full this once. It cannot be dismissed (no close button, no Escape,
 * Done refuses) until the "stored it" box is ticked. The parent drops the plaintext from its state on close,
 * so nothing of it stays in the page.
 */
export default function TokenOnceDialog({ created, organizationName, registryUrl, onClose }) {
    const [revealed, setRevealed] = useState(true);
    const [stored, setStored] = useState(false);
    const [copied, setCopied] = useState(null);
    const [nudge, setNudge] = useState(false);
    const timer = useRef();

    useEffect(() => () => clearTimeout(timer.current), []);

    // Escape closes once the token is stored. (The dialog itself stays non-dismissible: switching
    // it would add a close button and move focus while the person is reading.)
    useEffect(() => {
        if (!stored) {
            return undefined;
        }
        function onKey(event) {
            if (event.key === 'Escape') {
                onClose();
            }
        }
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [stored, onClose]);

    if (!created) {
        return null;
    }

    const token = created.token;
    const details = created.token_details ?? {};
    const command = `flb set-auth ${token} --registry ${registryUrl}`;

    async function copy(what, value) {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(what);
            clearTimeout(timer.current);
            timer.current = setTimeout(() => setCopied(null), 1500);
        } catch {
            // Clipboard access can be refused; the token stays selectable.
        }
    }

    function done() {
        if (!stored) {
            setNudge(true);
            return;
        }
        onClose();
    }

    return (
        <Dialog
            open
            size={640}
            title="Copy your token now"
            labelledBy="token-once-title"
            dismissible={false}
            onClose={onClose}
            footer={
                <button type="button" className="btn btn-primary" aria-disabled={!stored} onClick={done} data-token-done>
                    Done
                </button>
            }
        >
            <div className="row" style={{ gap: 14, alignItems: 'flex-start' }}>
                <span className="empty-glyph" style={{ width: 48, height: 48, borderRadius: 14 }} aria-hidden="true">
                    <Icon name="key" size={22} />
                </span>
                <p className="ink2">“{details.name}” is ready. This is the only time you will see it in full.</p>
            </div>

            <div className="secret">
                <code aria-label="Your new token" data-token-value>
                    {revealed ? token : hideToken(token)}
                </code>
                <div className="row" style={{ gap: 8 }}>
                    <button type="button" className="icon-btn" aria-label={revealed ? 'Hide token' : 'Show token'} aria-pressed={!revealed} onClick={() => setRevealed(!revealed)} data-token-reveal>
                        <Icon name={revealed ? 'eye' : 'lock'} />
                    </button>
                    <button type="button" className="btn btn-primary btn-lg" onClick={() => copy('token', token)} data-autofocus data-token-copy>
                        <Icon name={copied === 'token' ? 'check' : 'copy'} />
                        <span aria-live="polite">{copied === 'token' ? 'Copied' : 'Copy token'}</span>
                    </button>
                </div>
            </div>

            <dl className="grid3 small" style={{ margin: 0, gap: 12 }}>
                <div>
                    <dt className="muted">Scopes</dt>
                    <dd style={{ margin: '2px 0 0' }}>{(details.scopes ?? []).map((scope) => scopeLabel(scope, true)).join(', ')}</dd>
                </div>
                <div>
                    <dt className="muted">Acts for</dt>
                    <dd style={{ margin: '2px 0 0' }}>{organizationName ?? 'All your organizations'}</dd>
                </div>
                <div>
                    <dt className="muted">Expires</dt>
                    <dd style={{ margin: '2px 0 0' }}>{shortDate(created.expires_at ?? details.expires_at)}</dd>
                </div>
            </dl>

            <div className="col" style={{ gap: 8 }}>
                <span className="small ink2">
                    Set it up in your terminal, or store it as a CI secret named <code>FLB_TOKEN</code>:
                </span>
                <div className="cmd">
                    <span className="p" aria-hidden="true">
                        $
                    </span>
                    <code style={{ minWidth: 0, flex: '1 1 auto', overflowWrap: 'anywhere' }}>
                        flb set-auth {revealed ? token : maskToken(token)} --registry {registryUrl}
                    </code>
                    <button type="button" className={`copy ${copied === 'command' ? 'done' : ''}`} onClick={() => copy('command', command)} aria-label={copied === 'command' ? 'Copied set-auth command' : 'Copy set-auth command'} data-token-copy-command>
                        <Icon name={copied === 'command' ? 'check' : 'copy'} size={14} />
                        <span aria-live="polite">{copied === 'command' ? 'Copied' : 'Copy'}</span>
                    </button>
                </div>
            </div>

            <div className="note note-you">
                <Icon name="warning" />
                <span className="small">After you close this, the registry keeps only a hash. Lost it? Revoke it and create a new one; nothing else breaks.</span>
            </div>

            <label className="check">
                <input
                    type="checkbox"
                    checked={stored}
                    onChange={(event) => {
                        setStored(event.target.checked);
                        setNudge(false);
                    }}
                    aria-describedby={nudge ? 'token-once-nudge' : undefined}
                    data-token-stored
                />
                I have stored this token somewhere safe
            </label>
            {nudge && (
                <span id="token-once-nudge" className="err" role="alert">
                    <Icon name="alert" size={14} strokeWidth={2.4} />
                    Tick the box once the token is stored. You won’t see it again.
                </span>
            )}
        </Dialog>
    );
}
