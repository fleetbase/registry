# Cutover runbook: cloud onto the Registry Service

One deployment in five steps (implementation plan, "Data migration and cutover", revised 24 Oct 2026):
**freeze, import, infra, deploy, unfreeze**. v1 is removed from registry-bridge 0.2.0, so the cloud
console cannot fall back to its old `registry_*` tables; rollback is redeploying the previous release
of every component together, which works because this release only reads those tables.

Planned window: **60 minutes**, of which the import itself must stay under 30. In the local rehearsal
below the window's data work (steps 2 and 4: dumps, import, verifies, second import) takes **about 4
minutes** on a fake larger than today's cloud registry and about 21 at three times that, so the window
is dominated by deploys and checks, not by the data. Allow twice the rehearsal times on the service's
0.5 vCPU task.

Contents: [Roles and conventions](#roles-and-conventions) · [Before the window](#before-the-window)
· [Go/no-go](#gono-go) · [Step 1 Freeze](#step-1-freeze) · [Step 2 Import](#step-2-import) · [Step 3
Infra](#step-3-infra) · [Step 4 Deploy](#step-4-deploy) · [Step 5 Unfreeze](#step-5-unfreeze) ·
[Smoke tests](#smoke-tests) · [Rollback](#rollback) · [Rehearsal and timings](#rehearsal-and-timings)
· [After the window](#after-the-window)

## How it fits together

- **The service** (`fleetbase/registry` `v2.0.0`) runs in the `production` stack of
  `fleetbase/registry-infra` beside Verdaccio, on `registry.fleetbase.io`. It is deployed and frozen
  (`registry:maintenance on`) before the window, with an empty database.
- **The cloud console's API** (fleetbase `v0.8.0` with registry-bridge `0.2.0`) registers itself with
  the service on boot as the one cloud instance (`registry:init` → `registry:cloud-register`), signed
  with the platform secret both sides share. That instance acts for each cloud company
  (`X-Registry-Tenant`): licences, installs and checkouts belong to the company's organization.
- **The importer** (`registry:import`) maps the cloud tables onto the service and attaches every cloud
  install and paid seat to that cloud instance. It creates the instance first, without a token; the
  API's first registration rotates onto it by host (D17), so installs and seats are already in place
  when the new console starts.
- **The import runs twice.** Once in step 2 (the bulk of the data, and the copy of every tarball),
  and once more in step 4, after the old API is gone, to pick up anything the old console or
  Verdaccio wrote meanwhile. The importer is idempotent; the second run takes about a minute. This is
  what makes the freeze safe even though the running cloud API (bridge 0.1.x) has no maintenance
  switch (see [Decisions](#decisions-this-runbook-depends-on)).
- **After the second import** the API records locally what each company had installed
  (`registry:cloud-sync-installs`), which is what makes each company's console load its engines.

## Roles and conventions

| Role | Who | Does |
| --- | --- | --- |
| Lead | backend engineer | runs the steps, calls go/no-go |
| Infra | DevOps | Pulumi, ECS, deploys |
| Checker | second engineer or staff reviewer | smoke tests in three reference organizations, Stripe dashboard |

Set these once in the shell every command below runs from (values in angle brackets come from the
pre-flight table):

```bash
export AWS_PROFILE=fleetbase-oss AWS_REGION=ap-southeast-1
export PULUMI_BACKEND_URL=https://api.pulumi.com   # the local CLI may point at another backend
export SVC_CLUSTER=fleetbase-registry-production-service
export CLOUD_CLUSTER=<cloud API ECS cluster> CLOUD_SERVICE=<cloud API ECS service> CLOUD_CONTAINER=<cloud API container>
export CLOUD_FILES_BUCKET=<cloud API files bucket, = service.import_source_bucket>
export CUTOVER=$(date -u +%Y%m%d)

# Run a command in the service's app container, in its queue container (imports: one worker
# shares the task's 1 GB instead of nginx and php-fpm), or in the cloud API.
svc() {
  local task; task=$(aws ecs list-tasks --cluster "$SVC_CLUSTER" --service-name app --query 'taskArns[0]' --output text)
  aws ecs execute-command --cluster "$SVC_CLUSTER" --task "$task" --container app --interactive --command "$*"
}
svcq() {
  local task; task=$(aws ecs list-tasks --cluster "$SVC_CLUSTER" --service-name queue --query 'taskArns[0]' --output text)
  aws ecs execute-command --cluster "$SVC_CLUSTER" --task "$task" --container queue --interactive --command "$*"
}
cloud() {
  local task; task=$(aws ecs list-tasks --cluster "$CLOUD_CLUSTER" --service-name "$CLOUD_SERVICE" --query 'taskArns[0]' --output text)
  aws ecs execute-command --cluster "$CLOUD_CLUSTER" --task "$task" --container "$CLOUD_CONTAINER" --interactive --command "$*"
}
```

Long commands run under `nohup` inside the container and are followed with `tail`, so a dropped
session does not stop them. Every import prints its peak memory; it runs with a 512 MB PHP limit
(`--memory-limit`), and the rehearsal peaked well below that (see the timings).

## Before the window

Do these in the week before. Each has a check; all of them feed the [go/no-go](#gono-go).

**Pre-flight values** (fill in and paste into the cutover channel):

| Value | Where it comes from |
| --- | --- |
| Cloud API cluster, service, container | `fleetbase/infra` production stack |
| Cloud API host (`APP_URL`) | the cloud API environment, e.g. `https://api.fleetbase.io` |
| Cloud files bucket | the cloud API's S3 bucket; also `service.import_source_bucket` |
| Staff organization slug | the organization Fleetbase's own cloud company imports as (see the dry-run report) |
| Three reference organizations | one with a paid purchase, one with several free installs, one publisher; their company uuids |
| Previous release tags | cloud API and console images now in production; registry-bridge 0.1.x |

**1. Service infrastructure** (registry-infra, `production` stack). `service.enabled` is set before the
window, not in step 3: the import in step 2 writes into the production service's database, so it has to
exist. Its listener rules only take the service's own paths (`/v1/*`, `/npm/*`, `/composer/*`, the
website pages), which Verdaccio never served, so nothing changes for current clients.

```bash
cd registry-infra && pulumi stack select production
pulumi config set --path 'service.enabled' true
pulumi config set --path 'service.import_source_bucket' "$CLOUD_FILES_BUCKET"
pulumi config set --path --secret 'service_configuration.REGISTRY_CLOUD_PLATFORM_SECRET' "$(openssl rand -hex 32)"
pulumi config set --path 'service_configuration.REGISTRY_CLOUD_HOST' '<cloud API host>'
pulumi config set --path 'service_configuration.REGISTRY_CLOUD_ORGANIZATION' '<staff organization slug>'
pulumi config set --path 'service_configuration.IMPORT_SOURCE_AWS_DEFAULT_REGION' ap-southeast-1
# Stripe live mode: STRIPE_KEY, STRIPE_SECRET, STRIPE_WEBHOOK_SECRET as secrets, the same way.
pulumi preview && pulumi up
```

Check: `pulumi stack output` shows the service; `curl -fsS https://registry.fleetbase.io/up` answers 200.

**2. Deploy the service and freeze it.** Push the `v2.0.0` tag of `fleetbase/registry` to
`deploy/production` (the workflow builds the image, runs migrations and deploys `app` and `queue`).
Then, before anything else reaches it:

```bash
svc php artisan registry:maintenance on --message="Extensions are moving to the new Fleetbase registry. Publishing and purchases are paused until the move is finished."
svc php artisan registry:maintenance status          # Maintenance: ON
svc php artisan migrate:status                        # every migration Ran
```

The freeze is stored in the service's database cache, so it holds across tasks and deploys until step 5.

**3. Cloud API configuration, staged** (`fleetbase/infra`, not deployed until step 4). The new release
reads these:

| Variable | Value |
| --- | --- |
| `REGISTRY_SERVICE_URL` | `https://registry.fleetbase.io` |
| `REGISTRY_CLOUD_AUTO_REGISTER` | `true` |
| `REGISTRY_CLOUD_PLATFORM_SECRET` | the same value as the service's (compare `sha256sum` fingerprints, never the secret) |
| `REGISTRY_MAINTENANCE` | `true` (the console comes up frozen; removed in step 5) |
| `APP_URL` | unchanged; its host must equal the service's `REGISTRY_CLOUD_HOST` |

`registry:init` must stay in the API's start-up command (it registers the instance), and the scheduler
must run (it re-registers within five minutes if the token is ever refused).

**4. Two clean dry runs on recent snapshots.** On the `qa` stack, or against production while it is
still empty, using the same dump path as the window (step 2). A run is clean when it exits 0, the
anomaly report has no class the team has not signed off, and its duration fits the budget. Record both
in the cutover channel with their dates.

```bash
cloud "sh -c 'CLOUD_DB_HOST=<db host> CLOUD_DB_USER=<user> MYSQL_PWD=<password> CLOUD_DB_NAME=fleetbase /path/to/cloud-registry-dump.sh /tmp/cloud-registry-dryrun.sql.gz && aws s3 cp /tmp/cloud-registry-dryrun.sql.gz s3://$CLOUD_FILES_BUCKET/registry-cutover/'"
svcq "sh -c 'nohup php artisan registry:import --from=import_source:registry-cutover/cloud-registry-dryrun.sql.gz --dry-run --report=/tmp/dry-run.json > /tmp/dry-run.log 2>&1 &'"
svcq tail -n 80 /tmp/dry-run.log
```

`scripts/cloud-registry-dump.sh` is in this repository; run it wherever the cloud database is reachable
and `mysqldump` and the AWS CLI exist (the bastion, or the cloud API task if its image has them; the
`cloud …` lines below assume the latter, so adapt them to the bastion otherwise). It dumps only what the importer reads, in one
consistent transaction. The dump holds password hashes of registry users: keep it in that private
bucket and delete it after the cutover.

**5. qa end to end.** On the `qa` stacks: the qa cloud console registers itself on boot
(`registry:cloud-register` reports `current` on the next run), Explore, Installed and Purchased show the
imported data for three qa organizations, an install and an uninstall work, and a test-mode purchase
completes with its webhook.

**6. Old clients that use Verdaccio as an npm registry.** Verdaccio proxies every package to npmjs, and
consoles set up before v0.8 point the `@fleetbase` scope at `https://registry.fleetbase.io`. The service
never proxies npm (D10), so after step 3 those clients get 404 for every `@fleetbase/*` package,
including the core ones. Measure it from the Verdaccio access logs for the last 14 days:

```bash
aws logs filter-log-events --log-group-name <verdaccio log group> --start-time $(( ($(date +%s) - 14*86400) * 1000 )) \
  --filter-pattern '"GET /@fleetbase"' --query 'events[].message' --output text | wc -l
```

If that is not close to zero, decide before the window between flipping `route_all` anyway (with a
published upgrade note) and keeping Verdaccio for npm reads only (skip step 3's `route_all` and
scale-down; see [Decisions](#decisions-this-runbook-depends-on)).

**7. Releases ready.** Tags built and images pushed: registry `v2.0.0`, registry-bridge `0.2.0`,
ember-core `0.4.0`, fleetbase `v0.8.0` (API and console), `@fleetbase/cli@2.0.0` published under the
`next` dist-tag. Previous production tags written down for rollback.

**8. Announce** the window (status page, console banner on the current release if available, email to
publishers): publishing and purchases pause, installed extensions keep working.

## Go/no-go

Called by the lead, 30 minutes before the window and again at each step's gate. Any "no" stops the
cutover before step 1, or triggers [rollback](#rollback) after it.

| # | Check | Evidence |
| --- | --- | --- |
| 1 | All roll-up PRs merged into `main`; tags built (registry, bridge, ember-core, fleetbase, CLI under `next`) | release links |
| 2 | Service deployed, healthy and frozen | `/up` 200; `registry:maintenance status` ON; `migrate:status` all Ran |
| 3 | Platform secret set on both sides, same fingerprint; `REGISTRY_CLOUD_HOST` = cloud API host | `sha256sum` of each, compared |
| 4 | Two clean dry runs on recent snapshots, each well under 30 minutes | dates, durations, anomaly sign-off |
| 5 | qa exercised end to end (registration, Explore, Installed, Purchased, install, test purchase) | checklist from the qa run |
| 6 | Verdaccio npm-read traffic measured and the `route_all` decision recorded | log count, decision |
| 7 | Three reference organizations chosen, their expected installs and licences written down | SQL below, run on the cloud database |
| 8 | Rollback ready: previous tags, the registry-infra values to restore, people on call | this page |
| 9 | Stripe: live webhook endpoint `https://registry.fleetbase.io/v1/webhooks/stripe` created, secret in the service | Stripe dashboard |
| 10 | Window announced; lead, infra and checker present | channel |

Expected values for the reference organizations, on the cloud database:

```sql
SELECT company_uuid, COUNT(*) AS live_installs FROM registry_extension_installs
WHERE deleted_at IS NULL AND company_uuid IN ('<uuid 1>', '<uuid 2>', '<uuid 3>') GROUP BY company_uuid;
SELECT company_uuid, COUNT(*) AS purchases FROM registry_extension_purchases
WHERE deleted_at IS NULL AND company_uuid IN ('<uuid 1>', '<uuid 2>', '<uuid 3>') GROUP BY company_uuid;
```

## Step 1 Freeze

*Gate: go/no-go items 1 to 10. Time: 5 minutes.*

1. Post the start in the channel; status page to "maintenance".
2. Confirm the service is frozen (it has been since it was deployed):

   ```bash
   svc php artisan registry:maintenance status
   ```

3. The running cloud API (bridge 0.1.x) has no maintenance switch, so purchases, installs and
   Verdaccio publishing are not technically blocked until step 4 replaces it. That is safe: anything
   written to the old tables until then is imported by the second run in step 4. Keep the window short
   and the announcement clear.

## Step 2 Import

*Gate: two clean dry runs on recent snapshots; each under 30 minutes. Time: 10 minutes (rehearsal:
about 5 with the in-window dry run).*

1. Dump the cloud tables and hand the dump to the service:

   ```bash
   cloud "sh -c 'CLOUD_DB_HOST=<db host> CLOUD_DB_USER=<user> MYSQL_PWD=<password> CLOUD_DB_NAME=fleetbase /path/to/cloud-registry-dump.sh /tmp/cloud-registry-$CUTOVER-1.sql.gz && aws s3 cp /tmp/cloud-registry-$CUTOVER-1.sql.gz s3://$CLOUD_FILES_BUCKET/registry-cutover/'"
   ```

2. A last dry run on exactly this dump (no files are copied, nothing is written):

   ```bash
   svcq "sh -c 'nohup php artisan registry:import --from=import_source:registry-cutover/cloud-registry-$CUTOVER-1.sql.gz --dry-run --report=/tmp/dry-run-final.json > /tmp/dry-run-final.log 2>&1 &'"
   svcq tail -n 80 /tmp/dry-run-final.log
   ```

   Compare its counts and anomalies with the last rehearsal; stop on a new anomaly class.

3. The import (copies every tarball, icon and screenshot into the service bucket):

   ```bash
   svcq "sh -c 'nohup php artisan registry:import --from=import_source:registry-cutover/cloud-registry-$CUTOVER-1.sql.gz --report=/tmp/import-1.json > /tmp/import-1.log 2>&1 &'"
   svcq tail -n 80 /tmp/import-1.log                 # ends with "Import complete."
   ```

4. Verify. Exits 0 when the dry run against the imported data would change nothing, every extension,
   release, purchase, licence and install has its row (except those the importer skips, which are in
   the anomaly report), and a sample of 50 rows per table matches field by field, tarball bytes
   included:

   ```bash
   svcq php artisan registry:import --from=import_source:registry-cutover/cloud-registry-$CUTOVER-1.sql.gz --verify --sample=50
   ```

   The last line reads `Cloud instance: inst_… (<cloud API host>, waiting for the cloud API to
   register)`.

5. The staff organization (reserved `@fleetbase/*` and `fleetbase/*` names):

   ```bash
   svc php artisan registry:staff <staff organization slug>
   ```

## Step 3 Infra

*Gate: qa stack exercised end to end; the `route_all` decision from go/no-go item 6. Time: 5 minutes.*

`service.enabled` is already on (see "Before the window"). Send every path on `registry.fleetbase.io`
to the service, then park Verdaccio. Its bucket, task definition and image stay for 90 days.

```bash
cd registry-infra && pulumi stack select production
pulumi config set --path 'service.route_all' true
pulumi config set --path 'verdaccio.desired_count' 0
pulumi preview      # expect: listener rule(s) changed, the Verdaccio ECS service's desiredCount 1 → 0
pulumi up
```

Checks:

```bash
curl -fsS https://registry.fleetbase.io/up
curl -fsS 'https://registry.fleetbase.io/v1/catalog/extensions?limit=1' | jq .meta.total   # published count from the import report
curl -fsS https://registry.fleetbase.io/composer/packages.json | jq '."available-packages" | length'
aws ecs describe-services --cluster fleetbase-registry-production --services app --query 'services[0].[desiredCount,runningCount]'   # [0, 0]
```

## Step 4 Deploy

*Gate: smoke test of Explore, install and purchase on the three reference organizations (below). Time:
25 minutes.*

1. **Cloud API and console**: deploy fleetbase `v0.8.0` (ember-core 0.4.0, registry-bridge 0.2.0) with
   the staged configuration, the usual way for production. Wait for the ECS deployment to reach steady
   state, so no task of the old API is left writing to the old tables.

2. **Registration**: each task runs `registry:init` on boot. Confirm the instance is registered and
   kept its imported data:

   ```bash
   cloud php artisan registry:cloud-register --json
   # {"status": "current", "summary": {"id": "inst_…", "kind": "cloud", ...}}
   ```

   The `id` must be the `inst_…` the verify printed in step 2 (`Cloud instance: inst_…`): the API
   rotated onto the imported instance (by host, or by adopting it while it had no token). A
   different id means the imported instance already held a token from another host (an earlier
   registration against this database) and the API got a new one: stop and roll back step 4; the
   installs and seats are on the other instance. Go/no-go item 3 (`REGISTRY_CLOUD_HOST` equals the
   API host, on an empty database) prevents it.

   If it reads `failed`: the message names the cause (a secret mismatch is "not signed with the platform
   secret", a clock skew is "expired"). Fix the configuration and run `cloud php artisan
   registry:cloud-register`.

3. **Second import**, from a dump taken now (picks up whatever the old API or Verdaccio wrote since
   step 2):

   ```bash
   cloud "sh -c 'CLOUD_DB_HOST=<db host> CLOUD_DB_USER=<user> MYSQL_PWD=<password> CLOUD_DB_NAME=fleetbase /path/to/cloud-registry-dump.sh /tmp/cloud-registry-$CUTOVER-2.sql.gz && aws s3 cp /tmp/cloud-registry-$CUTOVER-2.sql.gz s3://$CLOUD_FILES_BUCKET/registry-cutover/'"
   svcq "sh -c 'nohup php artisan registry:import --from=import_source:registry-cutover/cloud-registry-$CUTOVER-2.sql.gz --report=/tmp/import-2.json > /tmp/import-2.log 2>&1 &'"
   svcq tail -n 40 /tmp/import-2.log
   svcq php artisan registry:import --from=import_source:registry-cutover/cloud-registry-$CUTOVER-2.sql.gz --verify --sample=50
   ```

4. **Installed extensions per company**, recorded on the cloud API so each console loads its engines:

   ```bash
   cloud php artisan registry:cloud-sync-installs --dry-run
   cloud php artisan registry:cloud-sync-installs
   # Created + Unchanged = installed rows on the service; Skipped = companies deleted from the cloud API
   ```

5. **CLI**: `npm dist-tag add @fleetbase/cli@2.0.0 latest`.

6. Run the [smoke tests](#smoke-tests), frozen part.

## Step 5 Unfreeze

*Gate: the frozen smoke tests passed; Stripe webhooks verified in live mode. Time: 10 minutes.*

1. Service:

   ```bash
   svc php artisan registry:maintenance off
   ```

2. Cloud API: remove `REGISTRY_MAINTENANCE` from its configuration and roll the tasks (a new
   deployment of the same release). Then `cloud php artisan registry:maintenance status` reads `off`.

3. **Stripe live check**: buy a listing priced for this purpose (Stripe Checkout needs at least $0.50;
   a $1 staff listing, or a 100% promotion code on a real one), from a staff organization in the cloud
   console. The hand-off completes, the licence appears under Purchased with "activated here" after the
   install, the webhook shows 200 in the Stripe dashboard. Refund it from the staff finance view.

4. Run the [smoke tests](#smoke-tests), unfrozen part. Status page back to operational; announce.

## Smoke tests

**Frozen (end of step 4)**, in each of the three reference organizations, in the cloud console:

- Every Extensions page shows the yellow "Extensions are paused" banner with the message.
- Explore lists the catalog with categories and counts; a listing's panel opens with versions.
- Installed shows the organization's extensions with the versions the cloud tables had; their engines
  appear in the sidebar after a reload.
- Purchased shows every licence the organization bought (one per purchase), receipts where Stripe had
  them.
- Install, uninstall and Buy are refused with the maintenance message.

And on the service, the reference organizations' installs and paid licences match the cloud numbers
from go/no-go item 7. Open `svc php artisan tinker` and paste:

```php
App\Models\Organization::whereIn('source_uuid', ['<uuid 1>', '<uuid 2>', '<uuid 3>'])->get()
    ->map(fn ($o) => [$o->name, App\Models\Install::where('organization_uuid', $o->uuid)->where('status', 'installed')->count(), App\Models\Licence::where('organization_uuid', $o->uuid)->where('kind', 'paid')->count()]);
```

**Unfrozen (end of step 5)**, in one reference organization:

- Install a free extension: the installer modal runs to "installed"; uninstall it again.
- The purchase in step 5 completed (above).
- `npm view <free engine package> --registry https://registry.fleetbase.io/npm/` and
  `composer show -a <free api package> --repository https://registry.fleetbase.io/composer` answer.
- A publisher can open the Studio on `https://registry.fleetbase.io/studio` and the review queue loads
  for staff.

## Rollback

Rollback is redeploying the previous release of every component together. The old cloud tables were
only read, so the previous cloud API resumes exactly where it stopped.

| From | Do |
| --- | --- |
| Step 1 or 2 | Nothing has changed for clients. Leave the service frozen; `registry:import` can run again later. |
| Step 3 | `pulumi config set --path 'service.route_all' false && pulumi config set --path 'verdaccio.desired_count' 1 && pulumi up`; Verdaccio is back in about two minutes. |
| Step 4 | Redeploy the previous cloud API and console tags, then roll back step 3. The service stays up and frozen; its cloud instance is unused. |
| Step 5 | As step 4, but first `svc php artisan registry:maintenance on`. Purchases and installs made on the service after the unfreeze are not in the old tables: list them from the staff finance view (`/review/finance`) and settle them by hand (refund, or re-grant in the old console). |

Never drop or truncate the old `registry_*` tables in this release (Workstream P removes them after a
release cycle without reads).

## Rehearsal and timings

`scripts/rehearse-cutover.sh` runs the data path of steps 2 and 4 end to end against a seeded fake
cloud database (`scripts/fake-cloud-dump.php`, no real data): two dry runs, the import, `--verify`, a
second import, `--verify` again, then the dump the runbook takes (`scripts/cloud-registry-dump.sh`)
and a dry run reading it.

```bash
SCALE=1 S3_ENDPOINT= MYSQL_PORT=33308 MYSQL_SOCKET=/tmp/mysql.sock scripts/rehearse-cutover.sh
```

At `SCALE=1` the fake is larger than today's cloud registry (tens of extensions, a small community):
80 extensions, 453 bundles with 134 MB of tarballs, 600 purchases, 9,000 installs across 2,000
companies, 825 registry users, 120 developer accounts, and every anomaly class the importer reports
(12 missing tarballs, a bundle without a version, a package name used twice, a listing without bundles,
duplicate versions, a user without email, paid installs without a purchase).

Run 2026-10-24 on an Apple-silicon laptop shared with other builds (load average 15 to 28 throughout,
so these are pessimistic for the CPU and optimistic for nothing else): PHP 8.3 without Xdebug, MySQL
8.1 over a Unix socket (about 0.4 ms a prepared query, close to an in-VPC RDS round trip; loopback TCP
on macOS is 3 to 7 times slower and was not used), and local folders standing in for both S3 buckets
(no MinIO image could be pulled). S3-to-S3 copies in production add the transfer time of the
tarballs: 134 MB at 50 to 100 MB/s is 2 to 3 seconds. Scale 3 is 240 extensions, 1,433 bundles with 428
MB of tarballs, 1,800 purchases and 27,000 installs across 6,000 companies.

| Step | Scale 1 | Scale 3 |
| --- | --- | --- |
| Dump (`cloud-registry-dump.sh`) | 2 s | 2 s |
| Dry run, cold | 1 min 41 s | 6 min 56 s |
| Dry run, again | 2 min 53 s | 6 min 29 s |
| Import | 2 min 11 s | 9 min 38 s |
| Verify | 31 s | 3 min 11 s |
| Second import (nothing new) | 1 min 3 s | 4 min 32 s |
| Verify again (other sample) | 34 s | 3 min 27 s |
| Dry run from the dump | 1 min 46 s | 5 min 8 s |
| Peak memory (PHP) | 72 MB | 134 MB |

What the scale 1 import wrote: 2,114 organizations, 799 accounts, 80 extensions with 156 package names,
447 releases (6 skipped: duplicate versions and bundles without a version), 675 files, 600 purchases
and their 600 paid licences, 6,069 automatic free licences, 9,000 installs and 6,560 seats, all on one
cloud instance. The second import changed nothing (every table "unchanged") and both verifies exited 0.

**Budget for the window** at scale 1: step 2 (dump, import, verify) about 3 minutes, step 4 (dump,
second import, verify) under 2; doubled for the 0.5 vCPU task, about 10 minutes of the 60. The
in-window dry run in step 2 is optional (two clean ones precede the window): skip it if the window is
tight. A dry run can take longer than the import because the rolled-back transaction is undone row by
row. The default 128 MB PHP limit would have failed at scale 3, so the importer now raises its own
limit to 512 MB (`--memory-limit`) and prints its peak.

The rehearsal found and fixed one defect before it could reach production: on MySQL every re-import
counted extensions, releases and purchases as updated (MySQL reorders JSON object keys), so `--verify`
would have reported drift on every run. It also caught a migration index name over MySQL's 64
characters that SQLite accepted.

## After the window

- Delete the dumps: `aws s3 rm s3://$CLOUD_FILES_BUCKET/registry-cutover/ --recursive`.
- Keep Verdaccio parked (bucket and image) for 90 days, then archive (Workstream P).
- Watch for a week: failed registrations (`registry:cloud-register` in the scheduler log), install
  failures by stage, webhook failures, 503s after the unfreeze.
- Publishers re-verify payout details on the website (Connect accounts were copied from
  `companies.stripe_connect_id`; `payout_status` refreshes on the first webhook).

## Decisions this runbook depends on

1. **Freeze on the running cloud API.** Bridge 0.1.x has no maintenance switch, so the old console
   cannot be frozen in step 1. This runbook relies on the second import in step 4 instead. If the
   window must also stop purchases on the old console, backport the bridge's `REGISTRY_MAINTENANCE`
   setting to a 0.1.x hotfix and deploy it before the window.
2. **`route_all` and Verdaccio as an npm proxy** (go/no-go item 6). Flip it and break pre-0.8 clients
   that read `@fleetbase/*` through registry.fleetbase.io, or keep Verdaccio for npm reads (no
   `route_all`, no scale-down) until those clients upgrade.
3. **Order of `service.enabled`.** It moves before the window (the plan has it in step 3), because the
   import writes into the production service.
4. **Reference organizations** and the staff organization slug (`REGISTRY_CLOUD_ORGANIZATION`).
