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
| Publisher website (Workstream F), CLI v2 (G), Stripe (Phase 4) | later workstreams |

## Stack

Laravel 12 on PHP 8.3, MySQL 8, S3-compatible object storage. The Fleetbase core-api
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

Release checks run on the queue. `docker compose` starts a worker; with `php artisan serve`
run `php artisan queue:work` beside it (or set `QUEUE_CONNECTION=sync`).

Run the tests:

```bash
composer test
```

Seed a published free and a paid extension from the fixture tarballs in
`database/fixtures/packages`, with install tokens for a licensed and an unlicensed
organization, then drive real npm and Composer clients against them:

```bash
php artisan registry:seed --seed-release        # add --json for scripts
php artisan serve &
tests/Conformance/packages.sh http://127.0.0.1:8000
```

CI runs the same script in the `package-conformance` job.

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
| `POST /v1/auth/login` | email + password (+ token_name, scopes, expires_in_days). Returns a personal token once |
| `POST /v1/auth/logout` | Revokes the token used for the request |
| `GET /v1/account` | The caller's account with organizations and roles |
| `GET /v1/account/tokens` | The caller's tokens, masked (`flbp_…a1b2`) |
| `POST /v1/account/tokens` | name, scopes (`read`, `publish`, `install`, `instance:register`, `review`, `*`), expires_in_days (max 365), optional organization. Plaintext returned once |
| `DELETE /v1/account/tokens/{id}` | Revoke |
| `POST /v1/account/instance-codes` | Optional organization (slug or id). Mints a single-use instance registration code (`XXXX-XXXX-XXXX-XXXX`) valid 15 minutes. Needs the `instance:register` scope and the owner or admin role; defaults to the token's organization, else the personal one |

Tokens are `flbp_` + 40 random characters, stored as SHA-256, expiring, scoped and revocable.
Send them as `Authorization: Bearer flbp_…`. Passwords are bcrypt hashes checked with
`Hash::check`, the same as core-api's `Auth::checkPassword`, so cloud users import without
a reset. Keep `BCRYPT_ROUNDS` at or above the cost of imported hashes (cloud uses 10; the
default here is 12) because Laravel's `hashed` cast refuses hashes above the configured cost.

### Public catalog (no auth, cached 60 s)

| Method and path | Returns |
| --- | --- |
| `GET /v1/catalog/extensions?q=&category=&pricing=&hosting=&sort=&page=&limit=` | Paged listings with publisher, category, latest release, install count, compat, price, package names |
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
| `GET /v1/publisher/extensions` | The caller's listings, with package names, price and `readiness` |
| `POST /v1/publisher/extensions` | name, `packages: {npm, composer}` (one or both), optional slug, organization and listing fields. Creates a draft listing with a free price (201). Idempotent on package names: names that already belong to one of the caller's listings return it (200) and add any package kind it lacks; a different name for a kind it has, names spread over two listings, or names of another publisher answer 409 |
| `GET /v1/publisher/extensions/{slug}` | Listing with its releases |
| `PATCH /v1/publisher/extensions/{slug}` | Listing fields (name, subtitle, description, promotional_text, category, tags, languages, hosting, website/repo/support/privacy/tos URLs, copyright, fa_icon). No review |
| `GET /v1/publisher/extensions/{slug}/releases` | Every release with its checks |
| `POST /v1/publisher/extensions/{slug}/releases` | Multipart: `manifest` (extension.json), `engine` (npm tarball, gzipped) and/or `api` (Composer tar or tar.gz), optional `dist` (the `<name>/` folder from ember-core's `fleetbase-build-engine-release`, tarred), optional `channel` (`stable`, `beta`; default from the manifest), `notes`, `submit` (submit as soon as the checks pass). Returns the release (201) |
| `GET /v1/publisher/releases/{id}` | Files, checks, submission state, review policy outcome, the review timeline with reasons and items |
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
| `GET /v1/admin/releases?status=&extension=&limit=` | The review queue, oldest first. Default statuses `submitted`, `in_review`, `approved` |
| `GET /v1/admin/releases/{id}` | A release with checks, policy reasons and timeline |
| `POST /v1/admin/releases/{id}/claim` | submitted → in_review |
| `POST /v1/admin/releases/{id}/approve` | Optional `reason`, `items`; publishes unless `publish: false` |
| `POST /v1/admin/releases/{id}/reject` | Required `reason` and `items: [{code, passed, note?}]` (at least one) |
| `POST /v1/admin/releases/{id}/publish` | An approved release |
| `POST /v1/admin/extensions/{slug}/suspend` | Required `reason`. The listing leaves the catalog and the package endpoints; uploads and publishing stop |

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
  Jobs/RunReleaseChecks.php    queued release checks
  Releases/                    pipeline, state machine, review policy (D6), listing readiness,
                               namespace rules, manifest schema, checks, archive inspection, secrets
  Import/                      importer: SQL dump loader, source connection, mapping, report
  Http/Filter/                 request-driven query filters (ExtensionFilter)
  Http/Middleware/             scope:…, licence:npm|composer
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
tests/Feature/{Auth,Catalog,Packages,Instance,Import,Releases}  Pest feature tests (SQLite in memory)
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
