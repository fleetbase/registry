# Importing from Fleetbase Cloud

`registry:import` copies the cloud registry-bridge tables into the service. It reads `registry_extensions`, `registry_extension_bundles`,
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
| `--from` | `mysql://user:pass@host:3306/db` (or `mariadb://`; `?unix_socket=` and `?ssl_ca=` accepted), `sqlite:///path/file.sqlite`, a path to a `.sql` / `.sql.gz` dump, or `disk:path` for a dump on a filesystem disk (`import_source:registry-cutover/cloud.sql.gz`). Dumps are loaded into a temporary SQLite file without a MySQL server; mysqldump's extended inserts and one-tuple-per-line dumps both work, and statements for other tables are skipped. `scripts/cloud-registry-dump.sh` dumps exactly the rows the importer reads |
| `--dry-run` | Runs the whole mapping in a transaction that is rolled back, so the counts and anomalies are exactly what a real run would produce |
| `--source-disk` | Filesystem disk holding the cloud `files` objects. Default `REGISTRY_IMPORT_SOURCE_DISK` (`import_source`, an S3 disk configured by `IMPORT_SOURCE_AWS_*`) |
| `--report` | Also writes the full report, every anomaly included, as JSON |
| `--examples` | Anomaly examples printed per type (default 10) |
| `--memory-limit` | PHP memory limit for the run (default `512M`, never lowered; the source tables are held in memory). The peak is printed and kept in the report |
| `--cloud-host` | Host the cloud API registers with (default `REGISTRY_CLOUD_HOST`); cloud installs attach to that host's cloud instance |
| `--verify` | Compares source and target instead of importing; exits 1 on drift. A dry run against the target must create or update nothing; every extension, release, purchase, licence and install has its row except the ones the importer skips; a seeded sample of rows (`--sample`, default 25; `--seed`, default today) matches field by field, tarballs by SHA-256 of the source object, the copy and its record |

What it writes:

| Cloud | Service | Rule |
| --- | --- | --- |
| `registry_developer_accounts` | `accounts`, personal `organizations`, `memberships` (owner) | Status kept (`active`, `suspended`, `pending_verification`); bcrypt hash copied |
| `companies` | `organizations` | One per company the registry tables reference (publishers, registry users, buyers, installers); `stripe_connect_id` copied |
| `registry_users` (cloud) + `users` | `accounts`, `memberships` | One account per user (registry_users has one row per token); owner if the user owns the company, else publisher; password hash copied; tokens not migrated |
| `categories` | `categories` | Matched to seeded categories by `internal_id`, then slug; others created |
| `registry_extensions` | `extensions`, `prices`, `extension_packages` | Package names from the current bundle's `package.json` and `composer.json`, then older bundles, then the `package_name` column; listings with no bundle and no package name become drafts; `self_managed` maps to hosting `self_hosted`; icon and screenshots copied |
| `registry_extension_bundles` | `releases` | Version from `extension.json`; `bundle_number` kept in `manifest`; status pending → uploaded, awaiting_review → in_review, approved, published, rejected; `compat` from `extension.json` when present. The bundle archive is copied to `tarballs/imported/…` as a `source_bundle` file with a SHA-256 checksum; a bundle whose file is missing imports as `yanked` |
| `registry_extension_purchases` | `purchases`, `licences` | One paid licence per purchase with 5 seats; a soft-deleted purchase revokes its licence |
| `registry_extension_installs` | `instances`, `installs`, `licence_activations` | One cloud instance for all companies (the cloud host's, else a tokenless placeholder its first registration adopts); each install is for its company's organization; soft-deleted installs become `uninstalled`; live installs claim a seat on the company's paid licence or an automatic free licence. Per-company instances from earlier runs are revoked and their seats moved |

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
