# Commerce (Stripe)

Paid extensions are sold through Stripe with Connect Express accounts for publishers and hosted
Checkout for buyers. Every commerce route answers 503 until `STRIPE_SECRET` is set.

**Setup.** Set `STRIPE_KEY` (publishable, for Connect.js on the website) and `STRIPE_SECRET`.
Create two webhook endpoints in the Stripe Dashboard, both pointing at
`https://<registry>/v1/webhooks/stripe`:

- *Your account*: `checkout.session.completed`, `checkout.session.async_payment_succeeded`,
  `checkout.session.expired`, `charge.refunded`, `charge.dispute.created`.
- *Connected accounts*: `account.updated`.

Put both signing secrets in `STRIPE_WEBHOOK_SECRET`, comma-separated. After `registry:import`,
run `php artisan registry:stripe-sync` once: it reads every imported Connect account's state and
mirrors every paid price that has no Stripe price yet (checkout also does this lazily).

| Setting | Default | Meaning |
| --- | --- | --- |
| `REGISTRY_FACILITATOR_FEE_BPS` | `1000` | Fee before the first `facilitator_fees` row takes effect |
| `REGISTRY_LICENCE_MAX_ACTIVATIONS` | `5` | Seats (instances) on a paid licence |
| `REGISTRY_STRIPE_TAX` | `true` | Stripe Tax on Checkout, with the publisher's account liable |
| `REGISTRY_STRIPE_TAX_CODE`, `REGISTRY_STRIPE_TAX_BEHAVIOR` | none, `exclusive` | Product tax code; whether listing prices exclude tax |
| `REGISTRY_CHECKOUT_EXPIRES_MINUTES` | `60` | How long a Checkout Session stays open (30 to 1440) |
| `REGISTRY_CONNECT_COUNTRY` | none | One country for every new Connect account. Empty: the publisher chooses (`country` on `POST …/stripe/account`, asked on the Payouts page). Stripe cannot change an account's country, and without one it would use the platform's |
| `REGISTRY_COMMERCE_NOTIFY` | none | Comma-separated emails told about new disputes |

**How money moves.** A Checkout Session is a destination charge: the buyer pays the platform,
`application_fee_amount` (the facilitator fee in effect when the session starts, on the price
before tax) stays with Fleetbase, and the rest is transferred to the publisher's account. Every
charge is made `on_behalf_of` the publisher: the publisher is the settlement merchant, so the charge
settles in the publisher's country and a platform in one region can pay publishers in any country
Stripe supports. With Stripe Tax on, the publisher's account is also liable for tax, so the tax
collected reaches the account that files it. Platform-side Stripe
fees and losses fall on Fleetbase (`controller.fees.payer` and `losses.payments` are
`application`, as the console's accounts are today).

**Prices.** Every listing is priced and sold in USD (`Price::CURRENCY`), so sales, fees, transfers
and the finance reports are in USD; Stripe converts a publisher's USD balance to its bank account's
currency at payout. A listing still carrying another currency (an import) cannot be bought until its
publisher sets a USD price. One Stripe product per listing on the platform account, the regular
price, and the sale price as a second Stripe price; `on_sale` decides which one Checkout charges.
Stripe prices cannot change, so a new amount creates a new price and archives the old one.

**Licences and seats.** A purchase gives the buyer organization one paid licence with 5 seats.
A seat is one Fleetbase instance registered to that organization: installing on an instance
takes a seat, uninstalling frees it, and every company (tenant) on that instance is covered by
the one install. Buying again adds another licence with 5 more seats; installs use the seats
of all the organization's active licences. Another registry organization buys its own licence.
Staff can change seats, revoke and reinstate licences ([Admin API](api.md#admin-api)).

## Organization routes

Personal token or website session.

| Method and path | Who | Purpose |
| --- | --- | --- |
| `GET /v1/organizations/{slug}/stripe[?refresh=1]` | members (refresh: owners, admins) | `connected`, `payout_status` (`pending`, `restricted`, `enabled`, `rejected`), `can_receive_payments`, `requirements_due`, country, default currency |
| `POST /v1/organizations/{slug}/stripe/account` | owners, admins | Create the Express account (once; 201, then 200). `country` (ISO 3166-1 alpha-2, the organization's country; required unless `REGISTRY_CONNECT_COUNTRY` is set) decides onboarding and payout currency and cannot change later |
| `POST /v1/organizations/{slug}/stripe/account-session` | owners, admins | `client_secret` and `publishable_key` for Connect.js: onboarding, account management, notification banner, payouts, tax settings and registrations |
| `GET /v1/organizations/{slug}/payouts` | owners, admins, billing | Connect status, `balance` (`available`, `pending`, `in_transit` per currency), payout `schedule`, the last 10 payouts |
| `GET /v1/organizations/{slug}/payouts/transactions?from=&to=&status=&extension=&page=` | owners, admins, billing | Sales of the organization's extensions: `gross`, `fee`, `net`, `tax`, `refunded`, `status`, `customer`; `totals` per currency, leaving out refunded sales |
| `GET /v1/organizations/{slug}/payouts/transactions.csv` | owners, admins, billing | The same sales (same filters) as CSV |
| `GET /v1/organizations/{slug}/billing/purchases` | owners, admins, billing | What the organization bought: amount, tax, who bought it, status, Stripe receipt URL, licence |
| `GET /v1/organizations/{slug}/billing/licences` | members | Licences with seat use and the instances activated on each |
| `POST /v1/purchases/checkout` | owners, admins, billing of the buyer | Website checkout: `extension`, `organization`. Returns a hand-off (the object shown under [Instance checkout](#instance-checkout)); Stripe returns the buyer to `/account/billing/{slug}?handoff=` |
| `GET /v1/purchases/{handoff}` | members of the buyer | The hand-off |

## Staff finance and refunds

Admin routes: the `review` scope and membership of an `is_staff` organization.

The finance view reads the registry's own `purchases`, not Stripe, so it works without Stripe
and matches what the webhooks recorded. A purchase counts in the range its sale date falls in,
with its refunds and dispute, so a November refund of an October sale changes October. Amounts
are minor units per currency. Every bucket carries:

| Field | Meaning |
| --- | --- |
| `sales`, `gross` | Purchases and `amount_total` (tax included) |
| `refunds`, `refunded` | Purchases with a refund, and `meta.amount_refunded` (all of `gross` when `refunded` without it) |
| `fee`, `fee_refunded`, `fee_revenue` | `fee_amount`; the fee returned on refunds, in proportion to the amount refunded (`refund_application_fee`); fee less fee returned |
| `publisher_net` | `net_amount` less the publisher's share of refunds (the reversed transfer) |
| `disputes`, `disputed` | Purchases with `meta.dispute`, and the disputed amount. Not subtracted: the outcome is decided in Stripe |

| Method and path | Purpose |
| --- | --- |
| `GET /v1/admin/finance?from=&to=` | `totals` per currency, `months` per month and currency, `publishers` per organization and currency (highest fee revenue first). Default range: the last 12 months including this one |
| `GET /v1/admin/finance/purchases?from=&to=&status=&publisher=&extension=&page=` | Purchases newest first: publisher, customer, gross, fee, net, `refunded`, `fee_refunded`, `refundable` (what is left; 0 while disputed), `dispute`, Stripe ids |
| `GET /v1/admin/finance/monthly.csv?from=&to=[&by=publisher]` | One row per month and currency (and publisher), the fields above in major units |
| `POST /v1/admin/purchases/{id}/refund` | `amount` (minor units, defaults to what is left), `reason` (`requested_by_customer`, `duplicate`, `fraudulent`), `note`. Creates a Stripe refund on the payment intent with `reverse_transfer` and `refund_application_fee`, so the publisher and Fleetbase each give back their share; records `purchase.refund_requested`. Answers 202: the purchase turns `refunded` and its licence `revoked` when `charge.refunded` arrives. 409 when already refunded or disputed (answer disputes in the Stripe Dashboard), 422 above what is left, 502 when Stripe refuses |

Refund from the Finance page (`/review/finance`) rather than the Stripe Dashboard: a Dashboard
refund does not reverse the transfer or return the fee unless both boxes are ticked.

## Webhooks

`POST /v1/webhooks/stripe` checks the `Stripe-Signature` header against each secret (400 when
none matches or the timestamp is more than 5 minutes off) and stores each event id once in
`events.external_id`, in the same transaction as its effects: a redelivery answers
`{"status": "duplicate"}` and a failure answers 500 with nothing written, so Stripe's retry is
safe.

| Event | Effect |
| --- | --- |
| `checkout.session.completed` (paid), `checkout.session.async_payment_succeeded` | Purchase (`amount_total`, `fee_amount` from the PaymentIntent's application fee, `net_amount` = total − fee, tax and subtotal in `meta`, receipt URL from the charge), a `paid` licence with 5 seats, the hand-off `complete`, a receipt email to the buyer |
| `checkout.session.expired` | The hand-off `expired` |
| `charge.refunded` | Full refund: purchase `refunded`, licence `revoked` (new installs and updates stop; running instances keep their seat). Partial refund: `meta.amount_refunded` only |
| `charge.dispute.created` | Purchase `disputed` with the dispute in `meta`, licence `revoked` (`revoked_reason: disputed`; staff reinstate it if the dispute is won), email to `REGISTRY_COMMERCE_NOTIFY` |
| `account.updated` | The organization's `payout_status` and `meta.stripe` |

## Instance checkout

This is the contract [registry-bridge](https://github.com/fleetbase/registry-bridge) follows.

A console buys a paid extension for its instance's organization in four steps. All calls use the
instance token (`Authorization: Bearer flbi_…`).

1. **Start.** `POST /v1/instances/me/purchases/checkout`

   ```json
   {
     "extension": "route-optimizer",
     "return_url": "https://console.example.com/extensions/purchased",
     "cancel_url": "https://console.example.com/extensions",
     "email": "ops@example.com"
   }
   ```

   `extension` is a slug, `ext_` id or package name. `return_url` (http or https) is where Stripe
   sends the operator after paying; `cancel_url` defaults to it. Both must be on the host the
   instance registered (`instances.host`) or its domain: an instance registered as `api.acme.com`
   may use `api.acme.com`, `acme.com` or `console.acme.com`; `localhost` and IP addresses match
   only themselves (any port). `email` prefills Checkout and is optional. Answers `201`:

   ```json
   {
     "handoff": "chk_x1y2z3a4b5",
     "status": "open",
     "source": "instance",
     "extension": { "id": "ext_…", "slug": "route-optimizer", "name": "Route Optimizer" },
     "organization": { "id": "org_…", "slug": "globex", "name": "Globex" },
     "amount": 4900,
     "currency": "USD",
     "url": "https://checkout.stripe.com/c/pay/cs_…",
     "expires_at": "2026-10-24T13:00:00.000000Z",
     "completed_at": null,
     "purchase": null,
     "created_at": "2026-10-24T12:00:00.000000Z"
   }
   ```

   `amount` is the price before tax (the sale price while a sale is on). Refusals use the usual
   `{"errors": [...]}` envelope: 404 unknown extension; 409 when the organization publishes the
   extension; 422 when a return URL is off the instance's host, the extension is not published,
   is free, does not match the instance's hosting kind, or the publisher cannot take payments yet;
   503 when the registry has no Stripe configuration; 502 when Stripe refuses. An organization
   that already holds a licence may buy again: each purchase is another licence with 5 more seats.

2. **Redirect** the operator's browser to `url` (a full-page redirect; Checkout cannot be framed).

3. **Return.** Stripe sends the operator to `return_url` with `handoff=<id>` appended
   (`…/purchased?handoff=chk_x1y2z3a4b5`), or to `cancel_url` with `handoff` and `cancelled=1`.
   The return proves nothing about payment.

4. **Poll** `GET /v1/instances/me/purchases/{handoff}` (any checkout of the instance's
   organization) every 2 seconds, showing *Finalizing*, until `status` is no longer `open`:

   - `complete`: `purchase` is set, with `status`, `amount_total` (with tax), `amount_tax`,
     `receipt_url` and `licence` (`kind: paid`, `max_activations`). Install as usual; the install
     claims a seat.
   - `expired`: the session ran out (or `expires_at` passed) without payment. Offer to start again.

   The webhook usually lands within seconds. If it has not after about a minute, tell the
   operator the purchase will appear once Stripe confirms it; the licence arrives by webhook even
   if the console tab is closed, and `GET /v1/instances/me` lists it.
