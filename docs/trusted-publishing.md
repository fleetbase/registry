# Trusted publishing (GitHub Actions)

A workflow can publish without a stored registry token. An owner or admin adds a trusted
publisher to the listing (Studio → Access): repository `owner/repo`, workflow file name,
optional GitHub environment, and allowed refs (`refs/tags/v*`). The account that adds it is the
one releases are published as, and must keep a publishing role.

In the workflow (`permissions: id-token: write`), `flb publish` (or the
`fleetbase/fleetbase-cli/action` composite action) asks GitHub for an OIDC token with the
registry's host name as audience and exchanges it:

| Method and path | Purpose |
| --- | --- |
| `POST /v1/auth/oidc/github` | `token` (the GitHub OIDC JWT), `extension` (slug, id or a package name). Verifies the RS256 signature against GitHub's JWKS (cached an hour, refetched for an unknown `kid`), `iss`, `aud`, `exp` and `nbf` (60 s leeway); then matches `repository`, `repository_owner`, `job_workflow_ref` (`{repo}/.github/workflows/{workflow}@{ref}`), `ref` and `environment` against the listing's trusted publishers, and `repository_id`/`repository_owner_id` against the ids pinned on first use. 200 with `{token: "flbt_…", expires_at, scopes: ["publish"], extension, trusted_publisher}`; 401 when the token does not verify; 403 when nothing matches (the same for an unknown listing); 503 when GitHub's keys cannot be fetched |

The `flbt_` token lives 15 minutes (`REGISTRY_TRUSTED_PUBLISHING_TOKEN_MINUTES`), is pinned to
the listing's organization and reaches only that listing's release routes
(`TokenGuard::TRUSTED_ROUTES`: list and show the listing, apply extension.json's `listing` block,
list, upload, show and submit releases). It is never listed among the account's tokens. Releases it uploads carry
`meta.trusted_publisher`. `REGISTRY_OIDC_AUDIENCE` overrides the expected audience (default: the
host of `REGISTRY_PUBLIC_URL`, else `APP_URL`).
