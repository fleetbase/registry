# Website

The website lives in this app: Laravel routes in `routes/web.php` render Inertia pages from
`app/Http/Controllers/Web`, and every path that is not `/v1`, `/npm`, `/composer` or `/up` is
a website page (unknown paths render the website's 404).

- **Reads.** Page controllers call the `/v1` API controllers in process
  (`WebController::api`), so page props have exactly the API's JSON shapes and share the
  catalog cache.
- **Writes.** Pages call `/v1` from the browser with the session cookie and the `XSRF-TOKEN`
  header (`resources/js/lib/api.js`), then reload their props.
- **Sessions.** Signing in (`POST /v1/auth/session`) puts an `flbs_` token in the Laravel
  session; the `web` guard resolves it. Website routes use `auth:web`, which redirects to
  `/sign-in`.
- **Console version.** The "works with my console" checks read `?console=` or the
  `registry_console` cookie (unencrypted, so the page can read it too), set by `POST /console`.
- **Pages.** `resources/js/pages` (Discover, Extension, Publisher, Auth, Account, Studio,
  Review), shared components in `resources/js/components`, the layout in
  `resources/js/layouts/SiteLayout.jsx`, the design tokens in `resources/css/registry.css`
  (`data-theme` on `<html>`: light, dark, or the system setting).
- **SSR.** `npm run build` also builds `bootstrap/ssr/ssr.js`, one self-contained file run
  with Node. The Docker image runs it as an s6 service (`docker/s6/inertia-ssr`) beside nginx
  and php-fpm, so first page loads arrive rendered; `INERTIA_SSR_ENABLED=false` turns it off.
  Whenever the renderer is not running, pages render in the browser instead.

| Path | Page |
| --- | --- |
| `/`, `/extensions/{slug}`, `/publishers/{slug}` | Discover, extension, publisher (private listings only for members of an organization with access) |
| `/sign-in`, `/register`, `/verify`, `/forgot-password` | Account access |
| `/auth/github`, `/auth/github/callback` | Sign in with GitHub (redirects; see [Sign in with GitHub](api.md#auth-and-account)) |
| `/account/profile`, `/account/organizations/{slug?}`, `/account/tokens` | Account |
| `/account/billing/{slug?}[?handoff=]` | Billing: purchases, receipts, licences and their instances; Finalizing after a website checkout |
| `/studio`, `/studio/new`, `/studio/{slug}` (listing), `/studio/{slug}/releases[/{id}]`, `/studio/{slug}/review`, `/studio/{slug}/history` | Publisher Studio |
| `/studio/{slug}/access` | Access: visibility (public or private), access grants to organizations and their instances, trusted publishers for GitHub Actions (owners and admins) |
| `/studio/insights/{organization?}[?extension=&from=&to=&group=]` | Insights: installs, uninstalls, active instances, purchases and net revenue against the previous period, a trend chart, active installs by version, recent events, CSV (see [Publisher analytics](analytics.md)) |
| `/studio/payouts/{slug?}` | Payouts: Stripe onboarding and account management (Connect.js), balance, schedule, sales and CSV |
| `/review`, `/review/publish`, `/review/releases/{id}`, `/review/finance` | Staff review console (finance: fee revenue, refunds, disputes, per-publisher totals, refunds) |
