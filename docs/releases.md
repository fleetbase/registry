# Listings, releases and review

## Listings and uploads

**Listing from extension.json.** An extension's `extension.json` can carry its catalog listing in a `listing` block (schema in [`resources/schemas/extension.schema.json`](../resources/schemas/extension.schema.json)): `subtitle`, `description` and `promotional_text` (text, or the relative path of a Markdown file the CLI reads), `category` (slug), `tags`, `languages`, `highlights` and `setup_steps` (up to 5 lines of at most 120 characters each: "What you'll get" and "After you install" on the extension page), `icon` (Font Awesome name), `hosting`, `copyright` and `links` (`website`, `repo`, `support`, `privacy`, `terms`); the top-level `keywords` and `repository` fill `tags` and `links.repo` when the block leaves them out. `flb publish` applies the block on every publish (`PUT …/listing-manifest`), so the file wins for the fields it sets: they are recorded in `meta.manifest_fields`, Studio shows them read-only with *Set in extension.json; change it there and publish*, and `PATCH` refuses to change them. Fields the file does not set stay editable in Studio. The name comes from extension.json only when the listing is created; price, visibility and media (icon image, screenshots) are Studio's alone.

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

**Listing readiness.** Before a
listing's first release can be submitted it needs a name over 3 characters, subtitle,
description over 12 characters, promotional text, a tag, copyright, website, support and
privacy URLs, an icon (`fa_icon` counts) and a category. Published listings are not
re-checked. A private listing only needs a name; one switching to public needs everything.

## Release checks

`RunReleaseChecks` (queue `REGISTRY_CHECKS_QUEUE`, default queue otherwise) copies the stored
archives locally, reads each once without extracting it, and writes one `release_checks` row
per check with `passed` and `output` (`summary`, `errors`, `warnings`, details):

| Check | Fails when |
| --- | --- |
| `manifest` | extension.json does not validate against `resources/schemas/extension.schema.json` (draft 2020-12, shared with registry-bridge); `engine`/`api` declared without the archive or the reverse; version or channel differ from the release |
| `compat` | `compat.console`, `compat.api` or a `compat.extensions` range does not parse as a composer/semver range (missing console/api ranges are a warning) |
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

## Release states and review

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

On submit, the review policy decides. A human review is required for an extension's first
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
