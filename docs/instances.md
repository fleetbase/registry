# Instance API

An organization owner or admin mints a registration code, pastes it into the instance (console
admin page or `flb instance register`), and the instance exchanges it for an instance token.
Instance tokens are `flbi_` + 40 random characters, shown once and stored as SHA-256 in
`instances.token_hash`. They go through the same `TokenGuard` as personal tokens with a kind
check: the `auth:instance` guard accepts only `flbi_` tokens and `auth:token` only `flbp_`
tokens, so neither works on the other's routes. Setting `instances.status` to `revoked`
disables a token.

| Method and path | Purpose |
| --- | --- |
| `POST /v1/instances/register` | No bearer token. Body: `code`, `name`, `kind` (`self_hosted`; `cloud` is 422, since only the signed `register/cloud` makes a cloud instance), optional `host`, `console_version`, `api_version`, `previous_token`. Redeems the code (single use, 15 minutes) and returns `{token, token_type, rotated, instance}`; the token is never shown again. 201 for a new instance, 200 when an existing one was rotated (see [Re-registration](#re-registration)) |
| `GET /v1/instances/me` | The instance, its organization, and the organization's licences with `seats_used` and `activated_here` |
| `GET /v1/instances/me/updates?channel=` | Every extension installed on the instance: the installed release, the newest compatible published release, `update_available`, and `latest_patch` / `latest_minor`: the newest compatible release newer than the installed one within a patch (`~x.y.z`) or minor (`^x.y.z`, so 0.x stays on its minor) update policy, or null. `channel=beta` includes beta releases |
| `GET /v1/instances/me/compatibility?console=&api=&channel=&host[<package>]=&policy=` | Pre-upgrade check (see [Pre-upgrade compatibility](#pre-upgrade-compatibility)): for a target console and/or API version, every installed extension with `installed_compatible`, the newest compatible published release (`compatible`), `compatible_release_exists` and an `action` (`keep`, `update`, `downgrade`, `blocked`). `meta.compatible` is false while any extension is `blocked`; `meta.blocking_extensions` lists their slugs. Staff read any instance at `GET /v1/admin/instances/{inst_id}/compatibility` with the same query |
| `GET /v1/instances/me/releases/{release}/dist` | The release's prebuilt engine dist (`rel_` id), as `dist.url` names it. Free extensions: any active instance. Paid: 403 unless the instance's organization owns the extension or holds an active licence, checked on every request. `X-Checksum-Sha256`, ETag, 304; yanked releases stay downloadable; 404 when the release has no dist or the listing is suspended |
| `POST /v1/instances/me/installs` | Body: `extension` (slug, `ext_` id or an npm/Composer package name), `version` or `release` (`rel_` id), `status` (`installing`, `installed`, `failed`), optional `error`, `installed_by`. Returns `{install, activation}` |
| `DELETE /v1/instances/me/installs/{extension}` | Reports an uninstall (scoped npm names work in the path). Returns `{install, deactivated}` |
| `POST /v1/instances/register/cloud` | Fleetbase Cloud only. No bearer token and no code: signed with the platform secret (see [Fleetbase Cloud](#fleetbase-cloud)). Body: `host` (required), optional `name`, `console_version`, `api_version`, `previous_token`. Same response as `register` |
| `GET /v1/instances/me/tenant-installs?page=&per_page=` | Cloud instance only: every installed extension per cloud company (`company_uuid`, `extension`, `packages`, `release`), 500 a page |

## Fleetbase Cloud

The cloud console's API registers one instance per deployment (kind `cloud`,
`meta.platform`) in the organization `REGISTRY_CLOUD_ORGANIZATION`, on boot and without a code:
`X-Registry-Timestamp` (unix seconds, within 5 minutes), `X-Registry-Nonce` (16 to 64 characters,
single use) and `X-Registry-Signature: v1=<hex HMAC-SHA256 of "{timestamp}.{nonce}.{body}" with
REGISTRY_CLOUD_PLATFORM_SECRET>`. A missing secret answers 403, a bad, expired or replayed
signature 401. Re-registration rotates the same instance by `previous_token`, else by host,
else it adopts the tokenless instance `registry:import` prepared. That instance serves every cloud
company: `X-Registry-Tenant: <company uuid>` on any instance route makes it act for that company's
organization (licences in `me`, install rows and seats, checkouts and hand-offs); an unknown company
gets an organization named from `X-Registry-Tenant-Name`. Other instances never act for anyone. The
package gate lets the cloud instance download a paid package while one of its companies holds an
active seat on it.

## Pre-upgrade compatibility

How `GET /v1/instances/me/compatibility` decides:

- The target is the core an upgrade moves to: `console` is the console's `package.json`
  version and `api` the installed `fleetbase/core-api` version, as an instance reports them at
  registration. At least one is required; both must be versions (`v0.8.1` and `0.8` are fine,
  `latest` or `dev-main` are 422), because an unparseable version matches every range.
- Ranges are the release's `compat` (from `extension.json`), evaluated exactly like
  `me/updates`. A side that is not given does not constrain. Only `published` releases are
  offered; `channel=beta` adds beta releases. The installed release may be yanked and still
  count as `keep` when its ranges accept the target.
- `host[@fleetbase/ember-ui]=…&host[@fleetbase/ember-core]=…` (the versions the target
  console ships) adds `dist: {built_against, compatible, reasons}` for the release the action
  points at, compared like the installer's host-modules check (`policy=minor` by default, or
  `exact`, `major`). A dist that does not fit never blocks: the installer rebuilds the engine.
- Private extensions (once `extensions.visibility` exists) are described only to their own
  organization's instances and to organizations with an active licence; other instances get
  `{id, slug, name: null}` with `action: blocked`, `reason: no_access` and no release data.
  Staff see everything.

## Re-registration

- Registering an instance that is already registered rotates it instead of creating a second
  one: the same `instances` row (same `inst_` id) gets the new name, host and versions and a
  new token, the old token stops working, and its installs and licence seats stay where they
  are. The response is 200 with `rotated: true`.
- The instance is matched by `previous_token` (its current token, which the console and
  `flb instance register` send) when it belongs to an active instance of the code's
  organization; else by `host`, when an active instance of that organization has the same host
  (scheme, case and a trailing slash ignored; the most recently seen one when there are
  several). A console that lost its token is therefore still rotated from the same host.
- A new host, an empty host, or a host registered only in another organization creates a new
  instance (201).
- A `previous_token` of an instance in another organization moves the console: a new instance
  is created in the code's organization, the old one is revoked with its seats released, and
  its open installs move to the new instance, which claims seats in its own organization on its
  next install report.
- The `instance.registered` or `instance.reregistered` event records `matched_by` (`token` or
  `host`) and, for a move, the `replaced` instance.

## Install rules

- One `installs` row per attempt: `installing` opens it, `installed` or `failed` closes it.
  When a release is installed, earlier `installed` rows for that extension become
  `uninstalled`, so each instance counts once in the catalog's install counts.
- Each row records its lifecycle for analytics: `installed_at` when it succeeds, then
  `replaced_at` when a newer install on the instance takes over (that row gets
  `previous_release_uuid`: an update) or `uninstalled_at` when the instance removes it.
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
