import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Field, Note, Segmented } from '../Ui';
import { useToast } from '../Toasts';
import { api } from '../../lib/api';
import { money } from '../../lib/format';

const CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'SGD'];

/** Fleetbase's facilitator fee on a sale, as a fraction. */
export const FEE = 0.1;

/** "29.50" → 2950 minor units, or null when it is not a positive amount. */
export function toMinor(value) {
    const text = String(value ?? '').trim().replace(/,/g, '');
    if (!/^\d+(\.\d{1,2})?$/.test(text)) {
        return null;
    }
    const minor = Math.round(Number(text) * 100);
    return minor > 0 ? minor : null;
}

/**
 * Pricing: Free or a one-time licence. Saved on its own (`PUT …/price`). Paid pricing needs the
 * organization's Stripe Connect account; the service answers 422 until then.
 */
export default function PricingSection({ extension }) {
    const toast = useToast();
    const price = extension.price ?? { model: 'free', amount: 0, currency: 'USD' };
    const [model, setModel] = useState(price.model === 'one_time' ? 'one_time' : 'free');
    const [amount, setAmount] = useState(price.model === 'one_time' && price.amount ? (price.amount / 100).toFixed(2) : '');
    const [currency, setCurrency] = useState(price.currency || 'USD');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const minor = toMinor(amount);
    const changed = model !== (price.model === 'one_time' ? 'one_time' : 'free') || (model === 'one_time' && (minor !== price.amount || currency !== price.currency));
    const valid = model === 'free' || minor !== null;

    async function save() {
        setBusy(true);
        setError(null);
        try {
            await api.put(`/publisher/extensions/${extension.slug}/price`, model === 'free' ? { model: 'free' } : { model, amount: minor, currency });
            router.reload({ only: ['extension'] });
            toast('Price saved.');
        } catch (e) {
            setError(e);
        } finally {
            setBusy(false);
        }
    }

    const fieldError = (key) => error?.field?.(key) ?? null;
    const general = error && !Object.keys(error.fields ?? {}).length ? error.message : null;

    return (
        <section id="s-pricing" className="sd-sec card" aria-labelledby="h-price">
            <div className="card-head">
                <h2 id="h-price" className="h3">
                    Pricing
                </h2>
                <span className="small muted">Saved separately from the listing</span>
            </div>
            <div className="card-body stack">
                <Segmented
                    role="radio"
                    label="Pricing model"
                    value={model}
                    onChange={(value) => {
                        setModel(value);
                        setError(null);
                    }}
                    options={[
                        { value: 'free', label: 'Free' },
                        { value: 'one_time', label: 'One-time licence' },
                    ]}
                />
                {model === 'one_time' && (
                    <div className="grid2">
                        <Field label="Price" error={fieldError('amount')} help={minor ? `You receive about ${money(Math.round(minor * (1 - FEE)), currency)} per sale after Fleetbase’s 10% fee, before Stripe’s processing fees.` : 'The one-time price operators pay.'}>
                            {(id, aria) => <input id={id} {...aria} className="input num" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="29.00" autoComplete="off" />}
                        </Field>
                        <Field label="Currency" error={fieldError('currency')}>
                            {(id, aria) => (
                                <select id={id} {...aria} className="select" value={currency} onChange={(e) => setCurrency(e.target.value)}>
                                    {[...new Set([...CURRENCIES, currency])].map((code) => (
                                        <option key={code} value={code}>
                                            {code}
                                        </option>
                                    ))}
                                </select>
                            )}
                        </Field>
                    </div>
                )}
                {general ? (
                    <Note tone="you" role="alert" data-price-error>
                        <span className="small">{general}</span>
                    </Note>
                ) : (
                    <Note tone="info">
                        <span className="small">Charging for an extension needs a payout account for {extension.organization?.name ?? 'your organization'}. Payouts open with Stripe Connect soon; until then listings are free. Changing the price of a published extension sends its next release to review.</span>
                    </Note>
                )}
                <div className="row" style={{ justifyContent: 'flex-end' }}>
                    <button type="button" className="btn btn-primary" onClick={save} disabled={!changed || !valid || busy} aria-busy={busy || undefined}>
                        {busy ? 'Saving…' : 'Save price'}
                    </button>
                </div>
            </div>
        </section>
    );
}
