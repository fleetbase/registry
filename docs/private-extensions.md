# Private extensions

A listing is `public` (the catalog) or `private` (`extensions.visibility`). A private listing
is meant for specific client organizations: it never appears in the catalog, search, publisher
pages, category counts, the website, Composer `packages.json` or npm metadata for anyone
without access, and every route answers **404** for it (never 401 or 403), so its name does not
leak. Paid or free, the gate applies.

**Who has access** (`App\Services\ExtensionAccess`): the owning organization, and any
organization holding an active `grant` or `paid` licence for it that covers the caller.
Automatic `free` licences never open a private listing. The caller is resolved from the
instance token (`flbi_`), a personal token with the `read` or `install` scope (downloads need
`install`), or the website session.

**Grants** are `licences` of kind `grant`: access at no charge, given by the owning organization's owners and
admins (Studio → Access, or the publisher API) or by staff (`/v1/admin/extensions/{slug}/access`).
A grant can be limited to some of the organization's instances (`licences.instance_uuids`): then
only those instance tokens see, download or install it, and the organization's personal tokens
do not. A grant can have seats (`max_activations`) and an expiry. Revoking one refuses the next
request (nothing is cached against it) and releases its seats. On a public paid listing a grant
is a complimentary licence.

**Caching.** Catalog responses for a caller with private access are cached server-side under a
key that names exactly the private listings it may see (`CatalogCache::key`), so a revoked grant
changes the key on the next request, and are sent `Cache-Control: private, no-cache`. Package
metadata and archives of private listings are always private. Every catalog and package
metadata response carries `Vary: Authorization`; a CDN in front of `/v1/catalog`, `/npm` and
`/composer` must forward `Authorization` (or bypass its cache when the header is present),
otherwise instances never see their private listings through it.

**Instances.** Granted organizations see private listings in their console's Explore: the
bridge sends its instance token on catalog calls. `POST /v1/instances/me/installs` claims a
seat on the grant; `GET /v1/instances/me/updates` reports `access: false` and no `latest` for a
private listing whose grant was revoked. Checkout of a private listing is refused (404) to an
organization without access.

**Review.** Releases of a private listing run every automated check (secrets scan included)
and skip human review: submitted with passing checks, they are approved and published at once
(`review_policy.visibility: private`). Each release records the visibility it went out with
(`meta.visibility`). Switching to private is immediate. Switching to public is immediate only
when nothing was released; otherwise the listing stays private with `pending_visibility:
public` until its next release passes human review as a first release, and publishing that
release makes it public. Releases published while private are never a baseline for the review policy.
