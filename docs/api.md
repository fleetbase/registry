# HTTP API reference

All routes on this page live under `/v1`. Errors use the Fleetbase envelope `{"errors": ["..."]}`;
validation failures add a `fields` map. Resources are returned unwrapped; paged lists return
`{"data": [...], "meta": {total, per_page, current_page, last_page, from, to, time}}`.

## Auth and account

| Method and path | Purpose |
| --- | --- |
| `POST /v1/auth/register` | name, email, password, optional username. Creates the account (status `pending_verification`) and a personal organization, emails a 6-digit code |
| `POST /v1/auth/verify` | email + code. Activates the account |
| `POST /v1/auth/verify/resend` | email. Sends a new code for unverified accounts |
| `POST /v1/auth/password/forgot` | email. Always 202 with the same body; an existing, unsuspended account gets a 6-digit reset code by email (30 minutes) |
| `POST /v1/auth/password/reset` | email, code, password, password_confirmation (registration's password rule). Sets the password, revokes every token and website session of the account, marks the email verified. 422 on `code` when it is wrong, used or expired |
| `POST /v1/auth/login` | email + password (+ token_name, scopes, expires_in_days). Returns a personal token once |
| `POST /v1/auth/logout` | Revokes the token used for the request |
| `GET /v1/auth/session` | The website's session: `{authenticated, account}`. Always 200; sets the session and `XSRF-TOKEN` cookies |
| `POST /v1/auth/session` | email + password. Signs the website in with a cookie session (same errors as `login`); 400 with a Bearer header |
| `DELETE /v1/auth/session` | Signs the website out: revokes the session token and invalidates the session |
| `GET /v1/account` | The caller's account with organizations, roles and `is_staff` |
| `PATCH /v1/account` | name, username, timezone |
| `POST /v1/account/password` | current_password, password, password_confirmation. Also ends the account's other website sessions |
| `DELETE /v1/account/github` | Disconnects GitHub; returns the account. 422 when GitHub is not connected, or (`code: password_required`) when the account has no password to sign in with |
| `GET /v1/account/tokens` | The caller's tokens, masked (`flbp_…a1b2`) |
| `POST /v1/account/tokens` | name, scopes (`read`, `publish`, `install`, `instance:register`, `review`, `*`), expires_in_days (max 365), optional organization. Plaintext returned once |
| `DELETE /v1/account/tokens/{id}` | Revoke |
| `GET /v1/organizations` | The caller's organizations with role and member count |
| `POST /v1/organizations` | name, optional slug, website, bio. The caller becomes owner |
| `GET /v1/organizations/{slug}` | Profile and members (members only) |
| `PATCH /v1/organizations/{slug}` | name, website, bio, avatar_url (owner or admin; the slug never changes) |
| `POST /v1/organizations/{slug}/members` | email + role. The email must belong to a registry account; personal organizations have one member |
| `PATCH /v1/organizations/{slug}/members/{account}` | role. Only owners grant or change the owner role |
| `DELETE /v1/organizations/{slug}/members/{account}` | Owner or admin, or a member leaving. The last owner cannot be removed or demoted |
| `POST /v1/account/instance-codes` | Optional organization (slug or id). Mints a single-use instance registration code (`XXXX-XXXX-XXXX-XXXX`) valid 15 minutes. Needs the `instance:register` scope and the owner or admin role; defaults to the token's organization, else the personal one |

**Website sessions.** Every account, organization, publisher and admin route takes either a
Bearer `flbp_` token or the website's session cookie (`auth:token,web`). Signing in through
`POST /v1/auth/session` issues an `flbs_` token row that never leaves the server; the session
holds its id, it expires with `SESSION_LIFETIME` and slides forward while used, and it is
revoked on sign-out. Requests without a Bearer header that carry the session or `XSRF-TOKEN`
cookie (and `/v1/auth/session` itself) run through Laravel's session and CSRF middleware, so
POST, PUT, PATCH and DELETE need `X-XSRF-TOKEN` (419 otherwise). Bearer clients, the catalog and
the instance API never get cookies. Set `SESSION_SECURE_COOKIE=true` behind HTTPS.

**Codes.** Verification and password-reset codes are six digits, one live code per account and
purpose (a verification code never resets a password), valid 30 minutes and dead after five
wrong guesses; the `auth` rate limit (10 a minute per IP, 5 per email and IP) covers every
route that takes one.

**Sign in with GitHub.** Set `GITHUB_CLIENT_ID` and `GITHUB_CLIENT_SECRET` (a GitHub OAuth app
whose callback is `GITHUB_REDIRECT_URI`, by default `{APP_URL}/auth/github/callback`); the
website shows "Continue with GitHub" only then (`auth.providers.github`). `GET /auth/github`
asks for `read:user user:email`. The callback signs in the account linked to that GitHub user,
else the account whose email is the GitHub user's verified primary email (and links it), else
creates an account (personal organization, the GitHub login as username when free, email
verified, no password), then signs the website in like `POST /v1/auth/session`. Signed in
already, the callback connects GitHub to the current account instead (refused when another
account has that GitHub user). Suspended accounts, a cancelled or expired GitHub round trip and
a GitHub user without a verified email go back to `/sign-in` with a message.

Tokens are `flbp_` + 40 random characters, stored as SHA-256, expiring, scoped and revocable.
Send them as `Authorization: Bearer flbp_…`. Passwords are bcrypt hashes checked with
`Hash::check`, the same as core-api's `Auth::checkPassword`, so cloud users import without
a reset. Keep `BCRYPT_ROUNDS` at or above the cost of imported hashes (cloud uses 10; the
default here is 12) because Laravel's `hashed` cast refuses hashes above the configured cost.

## Public catalog

No auth. Responses are cached for 60 seconds.

| Method and path | Returns |
| --- | --- |
| `GET /v1/catalog/extensions?q=&category=&pricing=&hosting=&featured=&console=&compatible=&sort=&page=&limit=` | Paged listings with publisher, category, latest release, install count, compat (`console`, `api`, and `extensions`: other extensions the release needs, package name => range), price, package names, `featured_at` / `is_featured`. `featured=1` keeps staff-featured listings; `sort` is `featured` (featured first, most recently featured on top), `popular` (default), `newest`, `updated`, `name` or `price`. `console=<version>` adds `compatibility: {console, compatible, newest_compatible}` to each item (does the latest release work, and the newest published release that does); with `compatible=1` only listings that have such a release |
| `GET /v1/catalog/extensions/{slug}` | Full listing, screenshots, publisher, versions summary, and `requires_extensions: [{package, range, name, slug}]` from the latest release's `compat.extensions` (`name` and `slug` when the catalog lists that package, else null) |
| `GET /v1/catalog/extensions/{slug}/releases?channel=` | Published (and yanked) releases: versions, channels, dates, sizes, notes, compat |
| `GET /v1/catalog/publishers/{slug}` | Publisher profile and their published extensions |
| `GET /v1/catalog/categories` | Categories with published extension counts |

Catalog releases (`latest_release`, each `versions` entry, `/releases`) and the instance
`updates` releases carry `dist`: `null`, or `{url, size, sha256, integrity, built_against}`
for a release with a prebuilt engine dist, where `built_against` holds the
`@fleetbase/ember-ui` and `@fleetbase/ember-core` versions it was built with. `url` is
`GET /v1/instances/me/releases/{release}/dist`, which an instance fetches with its token;
the same archive is also served at `GET /npm/{engine}/-/dist/{version}`.

Filters: `pricing=free|paid`, `hosting=cloud|self_hosted|both` (listings marked `both`
match either side), `category=<slug or id>[,…]`, `publisher=<slug>`, `tag=<tag>`.
Sorts: `popular` (default, by installs), `newest`, `updated`, `name`, `price`.
Slugs and public ids (`ext_…`, `org_…`) are both accepted where a slug is expected.

## Publisher API

Personal token with the `publish` scope, or the website session.

The caller needs the owner, admin or publisher role in the listing's organization; a token
pinned to an organization reaches only that organization. Other publishers' listings and
releases answer 404.

| Method and path | Purpose |
| --- | --- |
| `GET /v1/publisher/extensions` | The caller's listings, with package names, price, `readiness` and release summaries (`submitted_at`, `decided_at`: last approved or rejected) |
| `POST /v1/publisher/extensions` | name, `packages: {npm, composer}` (one or both), optional slug, organization and listing fields. Creates a draft listing with a free price (201). Idempotent on package names: names that already belong to one of the caller's listings return it (200) and add any package kind it lacks; a different name for a kind it has, names spread over two listings, or names of another publisher answer 409 |
| `GET /v1/publisher/packages/availability` | Query `organization` (slug; default as for create), `npm` and/or `composer`. For each name given, `{name, available, reason}` under `npm`/`composer`, by the rules create applies (format, reserved `@fleetbase`/`fleetbase`, the organization's namespaces, names already registered; a name one of the caller's listings holds is not available for a new one) |
| `GET /v1/publisher/extensions/{slug}` | Listing with its releases |
| `PATCH /v1/publisher/extensions/{slug}` | Listing fields (name, subtitle, description, promotional_text, category, tags, languages, highlights, setup_steps, hosting, website/repo/support/privacy/tos URLs, copyright, fa_icon). No review. `visibility` (`public`, `private`; owners and admins) switches the listing (see [Private extensions](private-extensions.md)). A field extension.json sets (`manifest_fields`) answers 422 *Set in extension.json; change it there and publish* unless the value is unchanged |
| `PUT /v1/publisher/extensions/{slug}/listing-manifest` | extension.json's `listing` block, as `flb publish` sends it: the same listing fields (category as a slug), never name, price, visibility or media. The fields present are saved and become the listing's `manifest_fields` (Studio shows them read-only); fields left out are handed back to Studio, so `{}` releases them all. No review. A trusted publishing token may call it |
| `POST`/`DELETE /v1/publisher/extensions/{slug}/icon` | Multipart `icon` (PNG, JPG or WebP, up to 1 MB) replaces the icon; DELETE removes it |
| `POST /v1/publisher/extensions/{slug}/screenshots` | Multipart `screenshot` (PNG, JPG or WebP, up to 5 MB), optional `caption`; at most 8 |
| `PATCH /v1/publisher/extensions/{slug}/screenshots` | `order`: every screenshot id once, cover first. `DELETE …/screenshots/{id}` removes one |
| `PUT /v1/publisher/extensions/{slug}/price` | `model` (`free`, `one_time`), `amount` (US cents), optional `currency` (`USD` only, the default), `sale_amount` (below `amount`) and `on_sale`. Paid prices need the organization's Stripe account (see [Commerce](commerce.md)). Saving mirrors the price to Stripe first (a refused price is not saved, 502); a price change sends the next release to review |
| `GET /v1/publisher/extensions/{slug}/releases` | Every release with its checks |
| `GET /v1/publisher/extensions/{slug}/stats?from=&to=&group=` | The listing's analytics (see [Publisher analytics](analytics.md)); `stats.csv` with the same query is the series as CSV |
| `POST /v1/publisher/extensions/{slug}/releases` | Multipart: `manifest` (extension.json), `engine` (npm tarball, gzipped) and/or `api` (Composer tar or tar.gz), optional `dist` (the `<name>/` folder from ember-core's `fleetbase-build-engine-release`, tarred), optional `channel` (`stable`, `beta`; default from the manifest), `notes`, `submit` (submit as soon as the checks pass). Returns the release (201) |
| `GET /v1/publisher/releases/{id}` | Files, checks, submission state, review policy outcome, the review timeline with reasons and items |
| `PATCH /v1/publisher/releases/{id}` | `notes` (nullable, up to 20,000 characters, as on upload). Any status but `yanked` (422). No review; a published release's notes show in the catalog at once |
| `POST /v1/publisher/releases/{id}/submit` | 200 once submitted (or published, see the [review policy](releases.md#release-states-and-review)); 202 while checks are still running, then it is submitted when they pass; 422 with reasons when checks failed or the listing is not ready |
| `POST /v1/publisher/releases/{id}/withdraw` | Out of review, back to `uploaded`. Optional reason |
| `POST /v1/publisher/releases/{id}/yank` | Published → yanked: gone from metadata and the catalog's latest version; the archives stay downloadable for lockfiles. Optional reason |
| `GET /v1/publisher/extensions/{slug}/access` | Owners and admins: `{visibility, pending_visibility, grants}` (see [Private extensions](private-extensions.md)) |
| `POST /v1/publisher/extensions/{slug}/access` | `organization` (slug or id), optional `instances` (`inst_` ids of that organization; none = every instance), `max_activations` (null = unlimited), `expires_at`, `note`. 201 with the grant; 409 when the organization already has an active one; 422 for the owner itself or a foreign instance |
| `PATCH /v1/publisher/extensions/{slug}/access/{grant}` | Any of `instances`, `max_activations` (at least the seats in use), `expires_at`, `note`. Instances dropped from the list lose their seat |
| `DELETE /v1/publisher/extensions/{slug}/access/{grant}` | Revoke (optional `reason`): the next request is refused and the grant's seats are released |
| `GET /v1/publisher/access/organizations/{organization}` | Find an organization to grant to: `{id, slug, name, instances}`; `instances` only for staff and the organization's members, else null |
| `GET`/`POST /v1/publisher/extensions/{slug}/trusted-publishers`, `DELETE …/{id}` | Owners and admins: GitHub Actions workflows allowed to publish (see [Trusted publishing](trusted-publishing.md)). `repository` (`owner/repo`), `workflow` (file name), optional `environment`, `refs` (patterns such as `refs/tags/v*`). Deleting revokes the tokens it issued |

## Admin API

Personal token with the `review` scope, held by a member of an `is_staff` organization.

| Method and path | Purpose |
| --- | --- |
| `GET /v1/admin/releases?status=&extension=&limit=` | The review queue, oldest first. Default statuses `submitted`, `in_review`, `approved`. Each release has `previous_version`: the extension's latest published (not yanked) release before it, null for a first release |
| `GET /v1/admin/releases/{id}` | A release with checks, policy reasons and timeline, `previous_version`, and `previous` (`id`, `version`, `published_at`, `compat`, `manifest`, `size_bytes` of that release, or null). The claim, approve, reject and publish responses have the same shape |
| `POST /v1/admin/releases/{id}/claim` | submitted → in_review |
| `POST /v1/admin/releases/{id}/approve` | Optional `reason`, `items`; publishes unless `publish: false` |
| `POST /v1/admin/releases/{id}/reject` | Required `reason` and `items: [{code, passed, note?}]` (at least one). From submitted, in_review or approved (request changes on an approval before it is published); otherwise 409 |
| `POST /v1/admin/releases/{id}/publish` | An approved release |
| `POST /v1/admin/extensions/{slug}/suspend` | Required `reason`. The listing leaves the catalog and the package endpoints; uploads and publishing stop |
| `POST /v1/admin/extensions/{slug}/unsuspend` | Optional `reason`. Restores the status saved at suspension |
| `PUT /v1/admin/extensions/{slug}/featured` | `{featured: bool}`. Features a published listing in the catalog (`featured_at`) or stops featuring it; recorded as `extension.featured` / `extension.unfeatured` |
| `GET /v1/admin/releases/{id}/files/{engine,api,dist}` | Downloads an archive for review; recorded as `release.downloaded` |
| `GET /v1/admin/review-items` | The review requirements R1 to R6 (`registry.review.items`); approve and reject accept only these codes |
| `GET /v1/admin/facilitator-fees` | The fee in effect (`current_rate_bps`), the configured default, and every scheduled fee, newest first |
| `GET`/`POST /v1/admin/extensions/{slug}/access`, `PATCH`/`DELETE …/access/{grant}` | Access grants on any listing, as in the [publisher API](#publisher-api) |
| `GET /v1/admin/licences?organization=&extension=&status=&kind=` | Licences (100 newest) with organization, purchase and seats in use |
| `POST /v1/admin/licences/{id}/revoke` | Required `reason`. New installs and updates stop; running instances keep their seat |
| `POST /v1/admin/licences/{id}/reinstate` | Optional `reason`. For a won dispute or a mistaken revoke |
| `PATCH /v1/admin/licences/{id}` | `max_activations`: seats, at least the seats in use |
| `POST /v1/admin/facilitator-fees` | `rate_bps` (0 to 5000; 1000 = 10%), optional `effective_from` (now or later, never earlier) and `note`. Adds a row; rows are never edited |
| `GET /v1/admin/finance`, `/finance/purchases`, `/finance/monthly.csv`, `POST /v1/admin/purchases/{id}/refund` | Staff finance and refunds; see [Commerce](commerce.md#staff-finance-and-refunds) |

## Maintenance mode

`php artisan registry:maintenance on [--message=]` (or `REGISTRY_MAINTENANCE=true`)
answers 503 `{errors: [message], maintenance: true}` with `Retry-After` on publishing (publisher
writes, review approve and publish) and on both checkouts, for the cutover window
([cutover runbook](cutover.md)). Catalog, packages, installs and Stripe webhooks keep working.
