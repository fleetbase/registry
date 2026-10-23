# Fleetbase Extensions Registry

The Registry Service is the single system of record for Fleetbase extensions: accounts and
publishers, the public catalog, releases and their review, package delivery for npm and
Composer, licences and purchases. Every Fleetbase instance (cloud or self-hosted), the `flb`
CLI and the publisher website are clients of it.

This branch (`v2`) holds the rewrite described in the implementation plan. The previous
Verdaccio-based registry is kept under [`legacy/`](legacy/LEGACY.md) and still deploys from
there until the publishing cutover.

## Status

Phase 1, Workstreams A to C, and Phase 2 Workstream E:

| Area | State |
| --- | --- |
| Data model (18 tables + verification codes), category seeder | done |
| Account auth: register, email verification code, login, logout, personal tokens | done |
| Public catalog API (`/v1/catalog/*`) with 60 s cache | done |
| Docker image, compose stack (app, queue, MySQL, MinIO, Mailpit), CI | done |
| Package store (`ReleaseStorage`), npm packument and tarball endpoints, Composer v2 repository, licence gate, `@fleetbase` namespace protection | done |
| Instance API: registration codes, register, me, updates, installs, licence seats | done |
| `registry:import` from the cloud registry-bridge tables (dry run, anomaly report, tarball copy) | done |
| Releases pipeline: publisher API, upload, queued checks, state machine, D6 review policy, staff review API, publishing | done |
| Website backend (Workstream F): cookie sessions, organizations and members, listing media and pricing, staff downloads and review items | done |
| Website (Workstream F): Inertia (React) catalog, account, Studio and review console, light and dark | done |
| Commerce (Phase 4, K to M): Stripe Connect Express, prices mirrored to Stripe, Checkout from instances and the website, webhooks, payouts and billing APIs and pages, staff finance and refunds | done |
| CLI v2 (G), staff finance view | later workstreams |

## Stack

Laravel 12 on PHP 8.3, MySQL 8, S3-compatible object storage. The website is Inertia v3 with
React 19, Tailwind v4 and Vite, served by the same Laravel app. The Fleetbase core-api
conventions the plan relies on (`Model` with UUID keys and public ids, `HasUuid`,
`HasPublicId`, the `Money` cast, `Http\Filter\Filter`, `FleetbaseResource`) are ported
into `app/` with the same names and behaviour, so code written against them reads like
any other Fleetbase API package.

## Local development

Zero-dependency (SQLite, mail to log):

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Full stack with Docker:

```bash
docker compose up --build
```

| Service | URL |
| --- | --- |
| API | http://localhost:8000 |
| MinIO console | http://localhost:9001 (minio / miniosecret) |
| Mailpit (verification emails) | http://localhost:8025 |
| MySQL | localhost:3307 (registry / registry) |

The website needs its assets. Build them once, or run Vite beside `artisan serve` for hot
reload (Node 22). `docker compose` mounts the checkout, so build on the host there too:

```bash
npm install
npm run build                    # public/build (client) and bootstrap/ssr (SSR bundle)
php artisan inertia:start-ssr    # optional: server-side rendering on 127.0.0.1:13714
npm run dev                      # or: Vite dev server with hot reload
```

Release checks run on the queue. `docker compose` starts a worker; with `php artisan serve`
run `php artisan queue:work` beside it (or set `QUEUE_CONNECTION=sync`).

Run the tests:

```bash
composer test        # Pest
npm test             # Vitest (components and page logic)
npm run lint         # ESLint
npm run test:e2e     # Playwright smoke test (run npm run build first)
```

The Playwright test boots its own server (`scripts/e2e-server.sh`: a fresh SQLite database in
`storage/e2e`, `registry:seed-website`, a synchronous queue) on port 8123.

`php artisan registry:seed-website [--json]` (never in production) adds what the website's
smoke test needs on top of that: `staff@registry.test` in a staff organization and
`publisher@registry.test` owning `acme`, both with password `password123`, a submitted release
in the review queue and a draft listing.

Seed a published free and a paid extension from the fixture tarballs in
`database/fixtures/packages`, with install tokens for a licensed and an unlicensed
organization, then drive real npm and Composer clients against them:

```bash
php artisan registry:seed --seed-release        # add --json for scripts
php artisan serve &
tests/Conformance/packages.sh http://127.0.0.1:8000
```

CI runs the same script in the `package-conformance` job.

## Website

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
| `/`, `/extensions/{slug}`, `/publishers/{slug}` | Discover, extension, publisher |
| `/sign-in`, `/register`, `/verify`, `/forgot-password` | Account access |
| `/auth/github`, `/auth/github/callback` | Sign in with GitHub (redirects; see below) |
| `/account/profile`, `/account/organizations/{slug?}`, `/account/tokens` | Account |
| `/account/billing/{slug?}[?handoff=]` | Billing: purchases, receipts, licences and their instances; Finalizing after a website checkout |
| `/studio`, `/studio/new`, `/studio/{slug}` (listing), `/studio/{slug}/releases[/{id}]`, `/studio/{slug}/review`, `/studio/{slug}/history`, `/studio/insights` | Publisher Studio |
| `/studio/payouts/{slug?}` | Payouts: Stripe onboarding and account management (Connect.js), balance, schedule, sales and CSV |
| `/review`, `/review/publish`, `/review/releases/{id}`, `/review/finance` | Staff review console (finance: fee revenue, refunds, disputes, per-publisher totals, refunds) |

## API overview

All routes live under `/v1`. Errors use the Fleetbase envelope `{"errors": ["..."]}`;
validation failures add a `fields` map. Resources are returned unwrapped; paged lists return
`{"data": [...], "meta": {total, per_page, current_page, last_page, from, to, time}}`.

### Auth and account

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

### Public catalog (no auth, cached 60 s)

| Method and path | Returns |
| --- | --- |
| `GET /v1/catalog/extensions?q=&category=&pricing=&hosting=&console=&compatible=&sort=&page=&limit=` | Paged listings with publisher, category, latest release, install count, compat, price, package names. `console=<version>` adds `compatibility: {console, compatible, newest_compatible}` to each item (does the latest release work, and the newest published release that does); with `compatible=1` only listings that have such a release |
| `GET /v1/catalog/extensions/{slug}` | Full listing, screenshots, publisher, versions summary |
| `GET /v1/catalog/extensions/{slug}/releases?channel=` | Published (and yanked) releases: versions, channels, dates, sizes, notes, compat |
| `GET /v1/catalog/publishers/{slug}` | Publisher profile and their published extensions |
| `GET /v1/catalog/categories` | Categories with published extension counts |

Filters: `pricing=free|paid`, `hosting=cloud|self_hosted|both` (listings marked `both`
match either side), `category=<slug or id>[,…]`, `publisher=<slug>`, `tag=<tag>`.
Sorts: `popular` (default, by installs), `newest`, `updated`, `name`, `price`.
Slugs and public ids (`ext_…`, `org_…`) are both accepted where a slug is expected.

### Packages (npm and Composer)

Package routes sit outside `/v1` because the clients treat them as registry roots. Errors
carry npm's `error` string beside the usual `errors` array.

| Method and path | Returns |
| --- | --- |
| `GET /npm/{name}` | Packument from published, non-yanked releases: `versions` (stored `package.json` + `dist.tarball`, `dist.shasum`, `dist.integrity`), `dist-tags` (`latest` = highest stable, `beta` = highest beta; `latest` falls back to the highest beta when nothing is stable), `time`. Scoped names work encoded (`@scope%2fname`) or not. ETag, 304 |
| `GET /npm/{name}/-/{basename}-{version}.tgz` | The engine tarball. Yanked versions stay downloadable for lockfiles |
| `PUT`/`POST`/`DELETE`/`PATCH /npm/…` | 405: publish with `flb publish` |
| `GET /npm/-/ping`, `GET /npm/-/whoami` | `npm ping`, `npm whoami` |
| `GET /composer/packages.json` | `metadata-url`, `available-packages` (every package with a published release) |
| `GET /composer/p2/{vendor}/{package}.json` | Every published release, newest first, `composer/2.0` minified, `dist: {type: tar, url, shasum, reference}`. `~dev.json` is always empty. ETag, 304 |
| `GET /composer/dist/{vendor}/{package}/{version}.tar` | The API archive |

Client setup:

```ini
# .npmrc
@acme:registry=https://registry.fleetbase.io/npm/
//registry.fleetbase.io/npm/:_authToken=flbp_…
```

```json
{
    "repositories": [{"type": "composer", "url": "https://registry.fleetbase.io/composer"}]
}
```

with `COMPOSER_AUTH='{"bearer": {"registry.fleetbase.io": "flbp_…"}}'` for paid packages.

**Licence gate.** Free packages are open. For a paid package the packument, tarball, p2
and dist routes need `Authorization: Bearer` with a personal token carrying the `install`
scope or an instance token (`flbi_…`). The token's organizations are its pinned
organization, or every organization of the account when it is not pinned; access is
granted when one of them owns the extension or holds an active, unexpired licence. 401
without a usable token, 403 with one. Paid responses are `Cache-Control: private`.

**Package store.** `App\Services\ReleaseStorage` writes objects to the
`REGISTRY_STORAGE_DISK` disk under `tarballs/`, `dist/`, `icons/` and `screenshots/`,
one `files` row each with `checksum` (sha256), `shasum` (sha1) and `integrity` (SRI
sha512). Package tarballs keep their decoded `package.json` or `composer.json` in
`files.meta`. Set `REGISTRY_PUBLIC_URL` when the service runs behind a proxy so the
URLs written into metadata use the public host.

**Reserved namespaces.** `@fleetbase/*` and `fleetbase/*` package names can only belong
to extensions of an organization flagged `is_staff`
(`php artisan registry:staff <slug> [--revoke]`).

### Publisher API (personal token, `publish` scope)

The caller needs the owner, admin or publisher role in the listing's organization; a token
pinned to an organization reaches only that organization. Other publishers' listings and
releases answer 404.

| Method and path | Purpose |
| --- | --- |
| `GET /v1/publisher/extensions` | The caller's listings, with package names, price, `readiness` and release summaries (`submitted_at`, `decided_at`: last approved or rejected) |
| `POST /v1/publisher/extensions` | name, `packages: {npm, composer}` (one or both), optional slug, organization and listing fields. Creates a draft listing with a free price (201). Idempotent on package names: names that already belong to one of the caller's listings return it (200) and add any package kind it lacks; a different name for a kind it has, names spread over two listings, or names of another publisher answer 409 |
| `GET /v1/publisher/packages/availability` | Query `organization` (slug; default as for create), `npm` and/or `composer`. For each name given, `{name, available, reason}` under `npm`/`composer`, by the rules create applies (format, reserved `@fleetbase`/`fleetbase`, the organization's namespaces, names already registered; a name one of the caller's listings holds is not available for a new one) |
| `GET /v1/publisher/extensions/{slug}` | Listing with its releases |
| `PATCH /v1/publisher/extensions/{slug}` | Listing fields (name, subtitle, description, promotional_text, category, tags, languages, hosting, website/repo/support/privacy/tos URLs, copyright, fa_icon). No review |
| `POST`/`DELETE /v1/publisher/extensions/{slug}/icon` | Multipart `icon` (PNG, JPG or WebP, up to 1 MB) replaces the icon; DELETE removes it |
| `POST /v1/publisher/extensions/{slug}/screenshots` | Multipart `screenshot` (PNG, JPG or WebP, up to 5 MB), optional `caption`; at most 8 |
| `PATCH /v1/publisher/extensions/{slug}/screenshots` | `order`: every screenshot id once, cover first. `DELETE …/screenshots/{id}` removes one |
| `PUT /v1/publisher/extensions/{slug}/price` | `model` (`free`, `one_time`), `amount` (minor units), `currency`, optional `sale_amount` (below `amount`) and `on_sale`. Paid prices need the organization's Stripe account (see Commerce). Saving mirrors the price to Stripe first (a refused price is not saved, 502); a price change sends the next release to review |
| `GET /v1/publisher/extensions/{slug}/releases` | Every release with its checks |
| `POST /v1/publisher/extensions/{slug}/releases` | Multipart: `manifest` (extension.json), `engine` (npm tarball, gzipped) and/or `api` (Composer tar or tar.gz), optional `dist` (the `<name>/` folder from ember-core's `fleetbase-build-engine-release`, tarred), optional `channel` (`stable`, `beta`; default from the manifest), `notes`, `submit` (submit as soon as the checks pass). Returns the release (201) |
| `GET /v1/publisher/releases/{id}` | Files, checks, submission state, review policy outcome, the review timeline with reasons and items |
| `PATCH /v1/publisher/releases/{id}` | `notes` (nullable, up to 20,000 characters, as on upload). Any status but `yanked` (422). No review; a published release's notes show in the catalog at once |
| `POST /v1/publisher/releases/{id}/submit` | 200 once submitted (or published, see D6); 202 while checks are still running, then it is submitted when they pass; 422 with reasons when checks failed or the listing is not ready |
| `POST /v1/publisher/releases/{id}/withdraw` | Out of review, back to `uploaded`. Optional reason |
| `POST /v1/publisher/releases/{id}/yank` | Published → yanked: gone from metadata and the catalog's latest version; the archives stay downloadable for lockfiles. Optional reason |

Upload rules: the manifest must be a JSON object with a SemVer `version` (no `v`) and the
request needs an engine or an api archive (422 otherwise). The archives are limited by
`REGISTRY_MAX_BUNDLE_BYTES` (50 MB each) and `REGISTRY_MAX_DIST_BYTES` (100 MB). Uploading a
version whose release was never submitted, failed its checks or was rejected replaces it
(files, checks and timeline deleted; the timeline is kept in `events`); any other existing
version answers 409.

**Namespaces.** `@fleetbase/*` and `fleetbase/*` are for staff organizations. A scope or vendor
that another organization's packages use is refused. Otherwise a new scope must be the
organization's slug, one it already publishes under, or one staff granted in
`organizations.meta.namespaces`. Unscoped npm names are allowed.

**Listing readiness** (the bridge's `isExtensionReadyForSubmission`, server-side): before a
listing's first release can be submitted it needs a name over 3 characters, subtitle,
description over 12 characters, promotional text, a tag, copyright, website, support and
privacy URLs, an icon (`fa_icon` counts) and a category. Published listings are not
re-checked.

### Release checks

`RunReleaseChecks` (queue `REGISTRY_CHECKS_QUEUE`, default queue otherwise) copies the stored
archives locally, reads each once without extracting it, and writes one `release_checks` row
per check with `passed` and `output` (`summary`, `errors`, `warnings`, details):

| Check | Fails when |
| --- | --- |
| `manifest` | extension.json does not validate against `resources/schemas/extension.schema.json` (draft 2020-12, shared with registry-bridge); `engine`/`api` declared without the archive or the reverse; version or channel differ from the release |
| `compat` | `compat.console` or `compat.api` does not parse as a composer/semver range (missing ranges are a warning) |
| `package_names` | the `package.json` / `composer.json` name is not the listing's `extension_packages` name of that kind, or disagrees with the manifest; the engine's `package.json` version is not the release version |
| `version` | not greater than every published or yanked release on the same channel; not installable by Composer (`-next.1` and the like) when there is an api archive |
| `size` | an archive over its limit, more than `REGISTRY_MAX_ARCHIVE_ENTRIES` entries, unpacked size over `REGISTRY_MAX_UNPACKED_BYTES`, a file over `REGISTRY_MAX_FILE_BYTES` |
| `archive` | not a readable tar (header checksums are verified); engine not gzipped; absolute paths, `..`, drive letters or backslashes; symlinks, hard links, devices, FIFOs; a forbidden extension (`registry.releases.forbidden_extensions`); a native executable by magic number (ELF, Mach-O, PE) |
| `secrets_scan` | the built-in gitleaks-style ruleset (`App\Releases\Secrets\SecretRules`: AWS, GitHub, GitLab, Slack, Stripe, private keys, Google, npm, SendGrid, Twilio, Mailgun, OpenAI, Anthropic, Fleetbase tokens; `.env`, SSH keys, key stores, `.npmrc`/`auth.json` with literal tokens) finds something. With `REGISTRY_SECRETS_SCANNER=auto` gitleaks also runs when its binary is on the path (`REGISTRY_GITLEAKS_BINARY`); without it the check warns that only the built-in rules ran. `off` skips the check with a warning. Matches are redacted; `gitleaks:allow` on a line suppresses it |
| `dist` | only with a `dist` upload: no `manifest.json`, no `builtAgainst` version for `@fleetbase/ember-ui` or `@fleetbase/ember-core`, name or version not the engine's, no `runtime` entry, a listed file missing or with a different sha256. Skipped (passed) without a dist |

The job records `release.meta.package_names` and `release.meta.dist` (`built_against`) for the
review policy and the installer. If the job itself fails the release goes to `failed_checks`.
It may run for up to 900 s, so the worker's queue needs `retry_after` above that
(`DB_QUEUE_RETRY_AFTER=960` in the compose stack) and `queue:work --timeout=900`. The worker
needs the same storage disk and cache store as the API, because an auto-approved release is
published from the job.

### Release states and review (D6)

```
uploaded ──check──▶ checking ──pass_checks──▶ uploaded (ready to submit)
                       └──fail_checks──▶ failed_checks
uploaded | rejected ──submit──▶ submitted ──claim──▶ in_review
submitted | in_review ──approve──▶ approved ──publish──▶ published ──yank──▶ yanked
submitted | in_review | approved ──reject──▶ rejected
submitted | in_review | approved ──withdraw──▶ uploaded
```

Every transition writes a `reviews` row (`action`, `from_status`, `to_status`, `actor`
`publisher`/`staff`/`system`, reviewer, `reason`, `items`) in the same transaction, with the
release row locked. Other moves answer 409.

On submit, review policy D6 decides. A human review is required for an extension's first
release and when, compared with the last published (or yanked) release, the package names,
the price (model, amount, currency; snapshotted on each release at publish) or the manifest's
`permissions` changed, or the listing is not published. The reasons are stored in
`release.meta.review_policy` and returned as `review.reasons`. Otherwise the release is
approved by `system` and published straight away.

Publishing sets `published_at`, snapshots the price, publishes a draft listing (status and
`published_at`), flushes the catalog cache (`App\Support\CatalogCache` bumps a generation key,
so it works on every cache store), and emails the uploader. npm dist-tags and Composer
metadata are built from published releases, so they follow at once. Rejections and failed
checks are emailed too.

### Admin API (personal token, `review` scope, member of an `is_staff` organization)

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
| `GET /v1/admin/releases/{id}/files/{engine,api,dist}` | Downloads an archive for review; recorded as `release.downloaded` |
| `GET /v1/admin/review-items` | The review requirements R1 to R6 (`registry.review.items`); approve and reject accept only these codes |
| `GET /v1/admin/facilitator-fees` | The fee in effect (`current_rate_bps`), the configured default, and every scheduled fee, newest first |
| `POST /v1/admin/facilitator-fees` | `rate_bps` (0 to 5000; 1000 = 10%), optional `effective_from` (now or later, never earlier) and `note`. Adds a row; rows are never edited |
| `GET /v1/admin/finance`, `/finance/purchases`, `/finance/monthly.csv`, `POST /v1/admin/purchases/{id}/refund` | Staff finance and refunds; see Commerce |

### Instance API (instance token)

An organization owner or admin mints a registration code, pastes it into the instance (console
admin page or `flb instance register`), and the instance exchanges it for an instance token.
Instance tokens are `flbi_` + 40 random characters, shown once and stored as SHA-256 in
`instances.token_hash`. They go through the same `TokenGuard` as personal tokens with a kind
check: the `auth:instance` guard accepts only `flbi_` tokens and `auth:token` only `flbp_`
tokens, so neither works on the other's routes. Setting `instances.status` to `revoked`
disables a token.

| Method and path | Purpose |
| --- | --- |
| `POST /v1/instances/register` | No bearer token. Body: `code`, `name`, `kind` (`cloud` or `self_hosted`), optional `host`, `console_version`, `api_version`. Redeems the code (single use, 15 minutes) and returns `{token, token_type, instance}`; the token is never shown again |
| `GET /v1/instances/me` | The instance, its organization, and the organization's licences with `seats_used` and `activated_here` |
| `GET /v1/instances/me/updates?channel=` | Every extension installed on the instance: the installed release, the newest compatible published release, `update_available`. `channel=beta` includes beta releases |
| `POST /v1/instances/me/installs` | Body: `extension` (slug, `ext_` id or an npm/Composer package name), `version` or `release` (`rel_` id), `status` (`installing`, `installed`, `failed`), optional `error`, `installed_by`. Returns `{install, activation}` |
| `DELETE /v1/instances/me/installs/{extension}` | Reports an uninstall (scoped npm names work in the path). Returns `{install, deactivated}` |

Install rules:

- One `installs` row per attempt: `installing` opens it, `installed` or `failed` closes it.
  When a release is installed, earlier `installed` rows for that extension become
  `uninstalled`, so each instance counts once in the catalog's install counts.
- `installing` and `installed` claim a licence seat (seat = instance) and return the
  activation. Free extensions get an automatic `free` licence for the instance's organization
  on first install, with unlimited seats (`max_activations` null). Paid extensions need an
  active licence with a free seat, else 403. A revoked or expired licence counts as none.
- A new install (`installing`) needs a published listing, a published release and a hosting
  value that matches the instance kind (422 otherwise). `installed` and `failed` also accept
  a yanked release, since an instance may already have it.
- `failed` releases the seat, unless an earlier release of the extension is still installed
  (a failed upgrade).
- Compatibility comes from `releases.compat` (`{"console": "<range>", "api": "<range>"}`,
  npm/Composer range syntax, evaluated with composer/semver). A side with no range accepts any
  version; an instance that does not report a version, or reports one that is not semver,
  is not constrained on that side; a range that does not parse never matches.

## Commerce (Stripe)

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
| `REGISTRY_LICENCE_MAX_ACTIVATIONS` | `5` | Seats (instances) on a paid licence (D4) |
| `REGISTRY_STRIPE_TAX` | `true` | Stripe Tax on Checkout, with the publisher's account liable |
| `REGISTRY_STRIPE_TAX_CODE`, `REGISTRY_STRIPE_TAX_BEHAVIOR` | none, `exclusive` | Product tax code; whether listing prices exclude tax |
| `REGISTRY_CHECKOUT_EXPIRES_MINUTES` | `60` | How long a Checkout Session stays open (30 to 1440) |
| `REGISTRY_CONNECT_COUNTRY` | none | Country for new Connect accounts; empty lets the publisher choose |
| `REGISTRY_COMMERCE_NOTIFY` | none | Comma-separated emails told about new disputes |

**How money moves.** A Checkout Session is a destination charge: the buyer pays the platform,
`application_fee_amount` (the facilitator fee in effect when the session starts, on the price
before tax) stays with Fleetbase, and the rest is transferred to the publisher's account. With
Stripe Tax on, the charge is made `on_behalf_of` the publisher and the publisher's account is
liable for tax, so the tax collected reaches the account that files it. Platform-side Stripe
fees and losses fall on Fleetbase (`controller.fees.payer` and `losses.payments` are
`application`, as the console's accounts are today).

**Prices.** One Stripe product per listing on the platform account, the regular price, and the
sale price as a second Stripe price; `on_sale` decides which one Checkout charges. Stripe prices
cannot change, so a new amount or currency creates a new price and archives the old one.

### Organization routes (personal token or website session)

| Method and path | Who | Purpose |
| --- | --- | --- |
| `GET /v1/organizations/{slug}/stripe[?refresh=1]` | members (refresh: owners, admins) | `connected`, `payout_status` (`pending`, `restricted`, `enabled`, `rejected`), `can_receive_payments`, `requirements_due`, country, default currency |
| `POST /v1/organizations/{slug}/stripe/account` | owners, admins | Create the Express account (once; 201, then 200) |
| `POST /v1/organizations/{slug}/stripe/account-session` | owners, admins | `client_secret` and `publishable_key` for Connect.js: onboarding, account management, notification banner, payouts, tax settings and registrations |
| `GET /v1/organizations/{slug}/payouts` | owners, admins, billing | Connect status, `balance` (`available`, `pending`, `in_transit` per currency), payout `schedule`, the last 10 payouts |
| `GET /v1/organizations/{slug}/payouts/transactions?from=&to=&status=&extension=&page=` | owners, admins, billing | Sales of the organization's extensions: `gross`, `fee`, `net`, `tax`, `refunded`, `status`, `customer`; `totals` per currency, leaving out refunded sales |
| `GET /v1/organizations/{slug}/payouts/transactions.csv` | owners, admins, billing | The same sales (same filters) as CSV |
| `GET /v1/organizations/{slug}/billing/purchases` | owners, admins, billing | What the organization bought: amount, tax, who bought it, status, Stripe receipt URL, licence |
| `GET /v1/organizations/{slug}/billing/licences` | owners, admins, billing | Licences with seat use and the instances activated on each |
| `POST /v1/purchases/checkout` | owners, admins, billing of the buyer | Website checkout: `extension`, `organization`. Returns a hand-off (below); Stripe returns the buyer to `/account/billing/{slug}?handoff=` |
| `GET /v1/purchases/{handoff}` | members of the buyer | The hand-off |

### Staff finance and refunds (admin middleware: `review` scope, `is_staff` organization)

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

### Webhooks

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
| `charge.dispute.created` | Purchase `disputed` with the dispute in `meta` (licence stays active), email to `REGISTRY_COMMERCE_NOTIFY` |
| `account.updated` | The organization's `payout_status` and `meta.stripe` |

### Instance checkout (contract for registry-bridge)

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
   sends the operator after paying; `cancel_url` defaults to it. `email` prefills Checkout and is
   optional. Answers `201`:

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
   extension or already holds a paid licence for it; 422 when it is not published, is free, does
   not match the instance's hosting kind, or the publisher cannot take payments yet; 503 when the
   registry has no Stripe configuration; 502 when Stripe refuses.

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

## Importing from Fleetbase Cloud

`registry:import` copies the cloud registry-bridge tables into the service, following the
plan's mapping rules. It reads `registry_extensions`, `registry_extension_bundles`,
`registry_extension_purchases`, `registry_extension_installs`, `registry_users`,
`registry_developer_accounts`, `users`, `companies`, `files` and `categories`.

```bash
# Rehearse: counts and anomalies, then roll back. Nothing is written and no file is copied.
php artisan registry:import --from=/backups/fleetbase-cloud.sql.gz --dry-run

# Import, keeping the full anomaly list for the publisher follow-up.
php artisan registry:import --from=mysql://reader:secret@cloud-replica:3306/fleetbase --report=import-report.json
```

| Option | Meaning |
| --- | --- |
| `--from` | `mysql://user:pass@host:3306/db` (or `mariadb://`), `sqlite:///path/file.sqlite`, or a path to a `.sql` / `.sql.gz` dump. Dumps are loaded into a temporary SQLite file without a MySQL server; mysqldump's extended inserts and one-tuple-per-line dumps both work, and statements for other tables are skipped |
| `--dry-run` | Runs the whole mapping in a transaction that is rolled back, so the counts and anomalies are exactly what a real run would produce |
| `--source-disk` | Filesystem disk holding the cloud `files` objects. Default `REGISTRY_IMPORT_SOURCE_DISK` (`import_source`, an S3 disk configured by `IMPORT_SOURCE_AWS_*`) |
| `--report` | Also writes the full report, every anomaly included, as JSON |
| `--examples` | Anomaly examples printed per type (default 10) |

What it writes:

| Cloud | Service | Rule |
| --- | --- | --- |
| `registry_developer_accounts` | `accounts`, personal `organizations`, `memberships` (owner) | Status kept (`active`, `suspended`, `pending_verification`); bcrypt hash copied |
| `companies` | `organizations` | One per company the registry tables reference (publishers, registry users, buyers, installers); `stripe_connect_id` copied |
| `registry_users` (cloud) + `users` | `accounts`, `memberships` | One account per user (registry_users has one row per token); owner if the user owns the company, else publisher; password hash copied; tokens not migrated |
| `categories` | `categories` | Matched to seeded categories by `internal_id`, then slug; others created |
| `registry_extensions` | `extensions`, `prices`, `extension_packages` | Package names from the current bundle's `package.json` and `composer.json`, then older bundles, then the Phase 0 `package_name` column; listings with no bundle and no package name become drafts; `self_managed` maps to hosting `self_hosted`; icon and screenshots copied |
| `registry_extension_bundles` | `releases` | Version from `extension.json`; `bundle_number` kept in `manifest`; status pending → uploaded, awaiting_review → in_review, approved, published, rejected; `compat` from `extension.json` when present. The bundle archive is copied to `tarballs/imported/…` as a `source_bundle` file with a SHA-256 checksum; a bundle whose file is missing imports as `yanked` |
| `registry_extension_purchases` | `purchases`, `licences` | One paid licence per purchase with 5 seats; a soft-deleted purchase revokes its licence |
| `registry_extension_installs` | `instances`, `installs`, `licence_activations` | One synthetic cloud instance per company (no token); soft-deleted installs become `uninstalled`; live installs claim a seat on the paid licence or an automatic free licence |

Idempotency: every imported row stores the uuid of its source row in `source_uuid`, and
re-runs match on it and update in place; an unchanged source produces only "unchanged"
counts. Slugs, usernames and paid seat counts are set on first import and then left alone.
Tarballs already copied with a checksum are not copied again; a release imported as yanked
is restored when its tarball becomes retrievable. An account whose email already belongs to
another account (created in the service, or imported from the other source table) is mapped
onto it and never overwritten.

The anomaly report lists: extensions without package names or bundles, bundles whose
tarball is missing (with the reason), duplicate package names (the first live, published
listing keeps the name), users without emails, accounts merged by email, bundles without a
version or repeating a version, rows that reference missing companies, users or extensions,
installs with no usable licence, renamed slugs, unknown statuses, subscription pricing, and
icons that could not be copied.

## Repository layout

```
app/
  Actions/                     ActivateLicence, DeactivateLicence, RegisterAccount
  Auth/TokenGuard.php          bearer-token guard (auth:token, auth:instance)
  Casts/Money.php              minor-unit money cast
  Console/Commands/            registry:seed, registry:staff, registry:import
  Http/Controllers/Packages/   npm and Composer endpoints
  Http/Controllers/Publisher/  publisher extensions and releases
  Http/Controllers/Admin/      staff review API
  Http/Controllers/Web/        website pages (Inertia), reading through the /v1 controllers
  Jobs/RunReleaseChecks.php    queued release checks
  Releases/                    pipeline, state machine, review policy (D6), listing readiness,
                               namespace rules, manifest schema, checks, archive inspection, secrets
  Import/                      importer: SQL dump loader, source connection, mapping, report
  Http/Filter/                 request-driven query filters (ExtensionFilter)
  Http/Middleware/             scope:…, licence:npm|composer, HandleInertiaRequests (shared props)
  Http/Resources/              Fleetbase-style JSON resources
  Models/                      one model per table; Model.php is the base
  Services/                    ReleaseStorage, LicenceGate, Packages/ (packument, Composer metadata)
  Support/                     Http, Utils, Semver (the one version comparator), Compat (ranges),
                               Tarball, CatalogCache
database/fixtures/packages/    fixture tarballs for registry:seed --seed-release
database/migrations/           the data model
database/seeders/              CategorySeeder (41 categories from registry-bridge)
docker/                        compose helpers
legacy/                        the Verdaccio registry (v1)
resources/schemas/            extension.json JSON Schema (copy of registry-bridge's)
resources/js/                  website: pages/, components/, layouts/, lib/ (Inertia + React)
resources/css/                 Tailwind entry and the design tokens (registry.css)
e2e/, playwright.config.js     Playwright smoke test; scripts/e2e-server.sh boots its server
tests/Feature/{Auth,Catalog,Packages,Instance,Import,Releases,Website}  Pest feature tests (SQLite in memory)
tests/Conformance/packages.sh  real npm and Composer clients against a running service
tests/Fixtures/import/         mysqldump fixture of the cloud registry tables
```

## Deployment

`docker-bake.hcl` at the root builds the `aws-app` target (the image wrapped with
`ssm-parent`, as the v1 deploy does). The `deploy/*` workflow still builds the Verdaccio
image from `legacy/` until the catalog cutover; point it at the root bake file when the
service goes to staging.

## License

AGPL-3.0-or-later. See [LICENSE.md](LICENSE.md).
