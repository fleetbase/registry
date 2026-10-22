# Fleetbase Extensions Registry

The Registry Service is the single system of record for Fleetbase extensions: accounts and
publishers, the public catalog, releases and their review, package delivery for npm and
Composer, licences and purchases. Every Fleetbase instance (cloud or self-hosted), the `flb`
CLI and the publisher website are clients of it.

This branch (`v2`) holds the rewrite described in the implementation plan. The previous
Verdaccio-based registry is kept under [`legacy/`](legacy/LEGACY.md) and still deploys from
there until the publishing cutover.

## Status

Phase 1, Workstreams A to C:

| Area | State |
| --- | --- |
| Data model (18 tables + verification codes), category seeder | done |
| Account auth: register, email verification code, login, logout, personal tokens | done |
| Public catalog API (`/v1/catalog/*`) with 60 s cache | done |
| Docker image, compose stack (app, queue, MySQL, MinIO, Mailpit), CI | done |
| Instance API: registration codes, register, me, updates, installs, licence seats | done |
| `registry:import` from the cloud registry-bridge tables (dry run, anomaly report, tarball copy) | done |
| npm packument and tarball endpoints, Composer v2 repository | next |
| Releases upload, checks, review; Stripe | later workstreams |

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

Run the tests:

```bash
composer test
```

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
| `POST /v1/account/tokens` | name, scopes (`read`, `publish`, `install`, `instance:register`, `*`), expires_in_days (max 365), optional organization. Plaintext returned once |
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
  Console/Commands/            registry:import
  Import/                      importer: SQL dump loader, source connection, mapping, report
  Http/Filter/                 request-driven query filters (ExtensionFilter)
  Http/Resources/              Fleetbase-style JSON resources
  Models/                      one model per table; Model.php is the base
  Support/                     Http, Utils and Compat (semver ranges) helpers
database/migrations/           the data model
database/seeders/              CategorySeeder (41 categories from registry-bridge)
docker/                        compose helpers
legacy/                        the Verdaccio registry (v1)
tests/Feature/{Auth,Catalog,Instance,Import}   Pest feature tests (SQLite in memory)
tests/Fixtures/import/         mysqldump fixture of the cloud registry tables
```

## Deployment

`docker-bake.hcl` at the root builds the `aws-app` target (the image wrapped with
`ssm-parent`, as the v1 deploy does). The `deploy/*` workflow still builds the Verdaccio
image from `legacy/` until the catalog cutover; point it at the root bake file when the
service goes to staging.

## License

AGPL-3.0-or-later. See [LICENSE.md](LICENSE.md).
