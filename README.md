# Fleetbase Extensions Registry

The Registry Service is the single system of record for Fleetbase extensions: accounts and
publishers, the public catalog, releases and their review, package delivery for npm and
Composer, licences and purchases. Every Fleetbase instance (cloud or self-hosted), the `flb`
CLI and the publisher website are clients of it.

This branch (`v2`) holds the rewrite described in the implementation plan. The previous
Verdaccio-based registry is kept under [`legacy/`](legacy/LEGACY.md) and still deploys from
there until the publishing cutover.

## Status

Phase 1, Workstreams A and B:

| Area | State |
| --- | --- |
| Data model (18 tables + verification codes), category seeder | done |
| Account auth: register, email verification code, login, logout, personal tokens | done |
| Public catalog API (`/v1/catalog/*`) with 60 s cache | done |
| Docker image, compose stack (app, queue, MySQL, MinIO, Mailpit), CI | done |
| Package store (`ReleaseStorage`), npm packument and tarball endpoints, Composer v2 repository, licence gate, `@fleetbase` namespace protection | done |
| Releases upload, checks, review; instance API; Stripe | later workstreams |

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
| `POST /v1/account/tokens` | name, scopes (`read`, `publish`, `install`, `instance:register`, `*`), expires_in_days (max 365), optional organization. Plaintext returned once |
| `DELETE /v1/account/tokens/{id}` | Revoke |

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

## Repository layout

```
app/
  Auth/TokenGuard.php          bearer-token guard (auth:token)
  Casts/Money.php              minor-unit money cast
  Console/Commands/            registry:seed, registry:staff
  Http/Controllers/Packages/   npm and Composer endpoints
  Http/Filter/                 request-driven query filters (ExtensionFilter)
  Http/Middleware/             scope:…, licence:npm|composer
  Http/Resources/              Fleetbase-style JSON resources
  Models/                      one model per table; Model.php is the base
  Services/                    ReleaseStorage, LicenceGate, Packages/ (packument, Composer metadata)
  Support/                     Http, Utils, Semver, Tarball helpers
database/fixtures/packages/    fixture tarballs for registry:seed --seed-release
database/migrations/           the data model
database/seeders/              CategorySeeder (41 categories from registry-bridge)
docker/                        compose helpers
legacy/                        the Verdaccio registry (v1)
tests/Feature/{Auth,Catalog,Packages}  Pest feature tests (SQLite in memory)
tests/Conformance/packages.sh  real npm and Composer clients against a running service
```

## Deployment

`docker-bake.hcl` at the root builds the `aws-app` target (the image wrapped with
`ssm-parent`, as the v1 deploy does). The `deploy/*` workflow still builds the Verdaccio
image from `legacy/` until the catalog cutover; point it at the root bake file when the
service goes to staging.

## License

AGPL-3.0-or-later. See [LICENSE.md](LICENSE.md).
