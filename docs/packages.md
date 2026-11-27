# Packages: npm and Composer

Package routes sit outside `/v1` because the clients treat them as registry roots. Errors
carry npm's `error` string beside the usual `errors` array.

| Method and path | Returns |
| --- | --- |
| `GET /npm/{name}` | Packument from published, non-yanked releases: `versions` (stored `package.json` + `dist.tarball`, `dist.shasum`, `dist.integrity`), `dist-tags` (`latest` = highest stable, `beta` = highest beta; `latest` falls back to the highest beta when nothing is stable), `time`. Scoped names work encoded (`@scope%2fname`) or not. ETag, 304 |
| `GET /npm/{name}/-/{basename}-{version}.tgz` | The engine tarball. Yanked versions stay downloadable for lockfiles |
| `GET /npm/{name}/-/dist/{version}` | The release's prebuilt engine dist as uploaded (`fleetbase-build-engine-release` output, tar or tar.gz), which a console installs as a runtime engine without a rebuild. Not an npm protocol route. `X-Checksum-Sha256`, ETag, 304; 404 when the release has no dist. Yanked versions stay downloadable. Catalog releases carry its `dist: {size, sha256, integrity, built_against}` |
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

**Licence gate.** Free packages are open. For a paid package the packument, tarball, prebuilt dist,
p2 and Composer dist routes need `Authorization: Bearer` with a personal token carrying the `install`
scope or an instance token (`flbi_…`). The token's organizations are its pinned
organization, or every organization of the account when it is not pinned; access is
granted when one of them owns the extension or holds an active, unexpired licence. 401
without a usable token (a revoked instance counts as none), 403 with one. Paid responses
are `Cache-Control: private`.

Downloads check the organization's licence only, not a seat on the calling instance.
Seats are claimed when the instance reports the install (`POST /v1/instances/me/installs`
with `installing`, which returns 403 when the licence has no free seat), and a personal
`install` token of the same organization could fetch the package anyway, so a seat check
at download time would add no enforcement. It would also break downloads from developer
machines and CI, which have no instance.

**Package store.** `App\Services\ReleaseStorage` writes objects to the
`REGISTRY_STORAGE_DISK` disk under `tarballs/`, `dist/`, `icons/` and `screenshots/`,
one `files` row each with `checksum` (sha256), `shasum` (sha1) and `integrity` (SRI
sha512). Package tarballs keep their decoded `package.json` or `composer.json` in
`files.meta`. Set `REGISTRY_PUBLIC_URL` when the service runs behind a proxy so the
URLs written into metadata use the public host.

**Reserved namespaces.** `@fleetbase/*` and `fleetbase/*` package names can only belong
to extensions of an organization flagged `is_staff`
(`php artisan registry:staff <slug> [--revoke]`).
