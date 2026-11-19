import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Field, Note, Segmented } from '../Ui';
import { useToast } from '../Toasts';
import { api } from '../../lib/api';
import { money } from '../../lib/format';

const CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'SGD'];

/** Fleetbase's facilitator fee on a sale, as a fraction, when the page does not say. */
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
 * Pricing: Free or a one-time licence, with an optional sale price. Saved on its own
 * (`PUT …/price`), which mirrors it to Stripe. Paid pricing needs the organization's Stripe
 * account (Studio → Payouts); the service answers 422 until then.
 */
export default function PricingSection({ extension }) {
    const toast = useToast();
    const fee = (usePage().props.commerce?.fee_bps ?? FEE * 10000) / 10000;
    const price = extension.price ?? { model: 'free', amount: 0, currency: 'USD' };
    const [model, setModel] = useState(price.model === 'one_time' ? 'one_time' : 'free');
    const [amount, setAmount] = useState(price.model === 'one_time' && price.amount ? (price.amount / 100).toFixed(2) : '');
    const [currency, setCurrency] = useState(price.currency || 'USD');
    const [sale, setSale] = useState(price.sale_amount ? (price.sale_amount / 100).toFixed(2) : '');
    const [onSale, setOnSale] = useState(Boolean(price.on_sale));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const minor = toMinor(amount);
    const saleMinor = toMinor(sale);
    const changed =
        model !== (price.model === 'one_time' ? 'one_time' : 'free') ||
        (model === 'one_time' && (minor !== price.amount || currency !== price.currency || (saleMinor ?? null) !== (price.sale_amount ?? null) || (onSale && saleMinor !== null) !== Boolean(price.on_sale)));
    const valid = model === 'free' || (minor !== null && (sale.trim() === '' || (saleMinor !== null && saleMinor < minor)));
    const net = (value) => money(Math.round(value * (1 - fee)), currency);
    const percent = `${Math.round(fee * 1000) / 10}%`;

    async function save() {
        setBusy(true);
        setError(null);
        try {
            await api.put(
                `/publisher/extensions/${extension.slug}/price`,
                model === 'free' ? { model: 'free' } : { model, amount: minor, currency, sale_amount: saleMinor, on_sale: saleMinor !== null && onSale },
            );
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
                        <Field label="Price" error={fieldError('amount')} help={minor ? `You receive about ${net(minor)} per sale after Fleetbase’s ${percent} fee, plus any tax collected.` : 'The one-time price operators pay, before tax.'}>
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
                        <Field label="Sale price" optional error={fieldError('sale_amount')} help={saleMinor ? `Below the price. You receive about ${net(saleMinor)} per sale while it is on.` : 'Leave empty for no sale.'}>
                            {(id, aria) => <input id={id} {...aria} className="input num" inputMode="decimal" value={sale} onChange={(e) => setSale(e.target.value)} placeholder="19.00" autoComplete="off" />}
                        </Field>
                        <label className="row small" style={{ gap: 8, alignSelf: 'end', minHeight: 44 }}>
                            <input type="checkbox" checked={onSale && saleMinor !== null} disabled={saleMinor === null} onChange={(e) => setOnSale(e.target.checked)} />
                            On sale now
                        </label>
                    </div>
                )}
                {general ? (
                    <Note tone="you" role="alert" data-price-error>
                        <span className="small">{general}</span>
                    </Note>
                ) : (
                    <Note tone="info">
                        <span className="small">
                            Charging for an extension needs payouts set up for {extension.organization?.name ?? 'your organization'}
                            {extension.organization?.slug && (
                                <>
                                    {' '}
                                    (<Link href={`/studio/payouts/${extension.organization.slug}`}>Payouts</Link>)
                                </>
                            )}
                            . Changing the price of a published extension sends its next release to review.
                        </span>
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
