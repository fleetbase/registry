# Publisher analytics

Studio Insights and the stats APIs report, for a date range against the period of the same
length just before it: installs, updates, uninstalls, active instances, purchases and revenue,
a series per day, week (Monday to Sunday) or month, active installs per version (one listing)
or a row per listing (an organization), and the latest 25 events. Days are UTC.

| Method and path | Who | Returns |
| --- | --- | --- |
| `GET /v1/publisher/extensions/{slug}/stats?from=&to=&group=day\|week\|month` | owners, admins, publishers (`publish` scope) | `extension`, `range`, `previous`, `as_of`, `totals`, `previous_totals`, `series`, `versions`, `events: {data, total}` |
| `GET /v1/organizations/{slug}/stats?from=&to=&group=` | owners, admins, publishers, billing | `organization`, the same report across every listing (deleted listings included while they have figures in the range), `extensions` (a row per listing) instead of `versions` |
| `GET …/stats.csv` (both) | as above | The series as CSV: `period, installs, updates, uninstalls, purchases`, then `gross_`, `fee_`, `net_` per currency (major units) |

`from` and `to` are `YYYY-MM-DD`, inclusive, at most 731 days apart; without them, the last
30 days. Without `group`: days up to 92 days, weeks up to a year, else months. Reports are
cached for `REGISTRY_ANALYTICS_CACHE_SECONDS` (60); `as_of` says when one was built.

What counts:

- **Installs** are installs that succeeded (`installed_at`) on an instance that did not have the
  listing; **updates** replaced the instance's previous install (`previous_release_uuid`,
  including a reinstall of the same version); **uninstalls** removed one that had been installed
  (`uninstalled_at`). Failed attempts count nowhere.
- **Active instances** are installed rows at the end of the range, the same count as the
  catalog's `installs_count` when the range reaches today; for a past range, rows installed by
  then and not yet uninstalled or replaced. `installs_count` stays derived from `installs`; there
  is no counter column.
- **Revenue** per currency, minor units, by the date of the sale, as staff Finance counts it:
  `gross`, `refunded`, `fee` (Fleetbase's fee after refunds) and `net` (the publisher's share after
  refunds), so a refund changes the range its sale fell in. Refund events list on the date Stripe
  reported them.

**Why no rollup table.** The report reads `installs` and `purchases` directly: two grouped
range scans for the series, one count for active instances, one for versions, and four
`LIMIT 25` reads for events, each on an index: `installs (extension_uuid, installed_at,
previous_release_uuid)`, `(extension_uuid, uninstalled_at, installed_at)`, `(extension_uuid,
status, release_uuid)` and `purchases (extension_uuid, created_at)`. The two lifecycle indexes
carry the other column their query reads, so the series never touches the table, and active
instances for a past range are walked back from today's count instead of scanning history.
Measured on SQLite with 230,000 installs on one listing (about 280 a day for two years,
196,000 still active), on a heavily loaded machine, so read these as upper bounds:

| Report | Time |
| --- | --- |
| One listing, 30 days by day | 0.23 s |
| One listing, a year by week | 0.7 s |
| One listing, two years by month | 0.9 s |
| An organization of 50 listings, 30 days to two years | 0.4 to 0.8 s |

Most of that is counting the active installs, which the catalog's `installs_count` already does
for every listing it shows. The registry expects tens of listings with installs in the hundreds,
so a daily rollup table and its scheduled command would be one more thing to keep correct
(late reports, refunds, deleted listings) with nothing to gain yet. Add one (per listing, day
and version, rebuilt nightly for the last few days) when a listing's report runs past a few
hundred milliseconds in production.
