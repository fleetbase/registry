<?php

namespace App\Analytics;

use App\Commerce\FinanceReport;
use App\Models\Event;
use App\Models\Extension;
use App\Models\Install;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Release;
use App\Support\Semver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Install and sales figures for one or more listings over a range, read straight from
 * `installs` and `purchases` (no counters or rollups; see the README's "Publisher analytics").
 *
 * - An install is a row that reached `installed` without replacing another (`installed_at`, no
 *   `previous_release_uuid`); an update is one that replaced the instance's previous install; an
 *   uninstall is an installed row that was removed (`uninstalled_at`).
 * - Active instances are installed rows, as the catalog's `installs_count` counts them, at the end
 *   of the range (now, when the range reaches today).
 * - Purchases count in the range their sale falls in, refunds with them, as in FinanceReport:
 *   `fee` is what Fleetbase kept and `net` what the publisher kept after refunds. Minor units.
 * - Days are UTC.
 */
class ExtensionStats
{
    /** @var array<string, int>|null */
    protected ?array $activeNow = null;

    /** Recent events a report lists. */
    public const EVENTS = 25;

    public const REFUND_EVENTS = ['purchase.refunded', 'purchase.partially_refunded'];

    /**
     * @param  Collection<int, Extension>  $extensions
     */
    public function __construct(protected Collection $extensions) {}

    /**
     * The report, cached for `registry.analytics_cache_seconds` per listings, range and shape;
     * `as_of` says when it was built.
     */
    public function cachedReport(StatsRange $range, bool $versions = false, bool $perExtension = false): array
    {
        $key = 'analytics:'.sha1(json_encode([$this->uuids(), $range->from->toDateString(), $range->to->toDateString(), $range->group, $versions, $perExtension]));

        return Cache::remember($key, (int) config('registry.analytics_cache_seconds', 60), fn () => $this->report($range, $versions, $perExtension));
    }

    /**
     * @return list<string>
     */
    protected function uuids(): array
    {
        return $this->extensions->pluck('uuid')->all();
    }

    /**
     * The report: totals for the range and the period before it, the series, active installs
     * per version (`$versions`), per-listing rows (`$perExtension`) and recent events.
     */
    public function report(StatsRange $range, bool $versions = false, bool $perExtension = false): array
    {
        $previous = $range->previous();
        $current = $this->figures($range);
        $before = $this->figures($previous);

        $report = [
            'range' => $range->toArray() + ['group' => $range->group],
            'previous' => $previous->toArray(),
            'as_of' => now()->toIso8601String(),
            'totals' => $current['totals'],
            'previous_totals' => $before['totals'],
            'series' => $this->series($range, $current),
        ];

        if ($versions) {
            $report['versions'] = $this->versions();
        }

        if ($perExtension) {
            $report['extensions'] = $this->perExtension($current);
        }

        $report['events'] = [
            'data' => $this->events($range),
            'total' => $current['totals']['installs'] + $current['totals']['updates'] + $current['totals']['uninstalls']
                + $current['totals']['purchases'] + $this->refundEvents($range)->count(),
        ];

        return $report;
    }

    /**
     * Counts per extension and day, and the totals over the range.
     *
     * @return array{totals: array, days: array<string, array<string, array>>, extensions: array<string, array>}
     */
    protected function figures(StatsRange $range): array
    {
        $uuids = $this->uuids();
        $days = [];
        $extensions = [];
        $blank = ['installs' => 0, 'updates' => 0, 'uninstalls' => 0, 'purchases' => 0, 'revenue' => []];

        $installed = Install::query()
            ->whereIn('extension_uuid', $uuids)
            ->whereBetween('installed_at', [$range->from, $range->to])
            ->groupBy('extension_uuid', DB::raw('DATE(installed_at)'))
            ->selectRaw('extension_uuid, DATE(installed_at) as day')
            ->selectRaw('SUM(CASE WHEN previous_release_uuid IS NULL THEN 1 ELSE 0 END) as installs')
            ->selectRaw('SUM(CASE WHEN previous_release_uuid IS NULL THEN 0 ELSE 1 END) as updates')
            ->toBase()
            ->get();

        foreach ($installed as $row) {
            $days[$row->day][$row->extension_uuid] ??= $blank;
            $days[$row->day][$row->extension_uuid]['installs'] += (int) $row->installs;
            $days[$row->day][$row->extension_uuid]['updates'] += (int) $row->updates;
        }

        $uninstalled = Install::query()
            ->whereIn('extension_uuid', $uuids)
            ->whereBetween('uninstalled_at', [$range->from, $range->to])
            ->whereNotNull('installed_at')
            ->groupBy('extension_uuid', DB::raw('DATE(uninstalled_at)'))
            ->selectRaw('extension_uuid, DATE(uninstalled_at) as day, COUNT(*) as uninstalls')
            ->toBase()
            ->get();

        foreach ($uninstalled as $row) {
            $days[$row->day][$row->extension_uuid] ??= $blank;
            $days[$row->day][$row->extension_uuid]['uninstalls'] += (int) $row->uninstalls;
        }

        // Sales are few next to installs; refunds live on each purchase, so add them up here.
        Purchase::query()
            ->whereIn('extension_uuid', $uuids)
            ->whereBetween('created_at', [$range->from, $range->to])
            ->select(['id', 'uuid', 'extension_uuid', 'amount_total', 'fee_amount', 'net_amount', 'currency', 'status', 'meta', 'created_at'])
            ->lazyById(500)
            ->each(function (Purchase $purchase) use (&$days, $blank) {
                $day = $purchase->created_at->toDateString();
                $days[$day][$purchase->extension_uuid] ??= $blank;
                $days[$day][$purchase->extension_uuid]['purchases']++;
                $days[$day][$purchase->extension_uuid]['revenue'] = static::addRevenue($days[$day][$purchase->extension_uuid]['revenue'], $purchase);
            });

        $active = $this->active($range);
        $totals = $blank + ['active_instances' => array_sum($active)];

        foreach ($days as $byExtension) {
            foreach ($byExtension as $uuid => $counts) {
                $extensions[$uuid] = static::merge($extensions[$uuid] ?? $blank, $counts);
                $totals = static::merge($totals, $counts);
            }
        }
        foreach ($this->uuids() as $uuid) {
            $extensions[$uuid] = ($extensions[$uuid] ?? $blank) + ['active_instances' => $active[$uuid] ?? 0];
        }

        $totals['revenue'] = static::revenueList($totals['revenue']);
        ksort($days);

        return ['totals' => $totals, 'days' => $days, 'extensions' => $extensions];
    }

    /**
     * Active installs per extension at the end of the range: installed rows (the catalog's count)
     * when the range reaches today. For an earlier end, the same count walked back: less what was
     * installed since, plus what was uninstalled since (an update replaces one row with another,
     * so it changes nothing). That reads only the rows after the end of the range.
     *
     * @return array<string, int>
     */
    protected function active(StatsRange $range): array
    {
        $now = $this->activeNow();
        if ($range->isCurrent()) {
            return $now;
        }

        $since = [$range->to->copy()->addSecond(), now()];
        $installed = $this->countBy(Install::query()->whereBetween('installed_at', $since)->whereNull('previous_release_uuid'));
        $uninstalled = $this->countBy(Install::query()->whereBetween('uninstalled_at', $since)->whereNotNull('installed_at'));

        return collect($this->uuids())
            ->mapWithKeys(fn (string $uuid) => [$uuid => max(0, ($now[$uuid] ?? 0) - ($installed[$uuid] ?? 0) + ($uninstalled[$uuid] ?? 0))])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    protected function activeNow(): array
    {
        return $this->activeNow ??= $this->countBy(Install::query()->active());
    }

    /**
     * @return array<string, int>
     */
    protected function countBy(Builder $query): array
    {
        return $query->whereIn('extension_uuid', $this->uuids())
            ->groupBy('extension_uuid')
            ->selectRaw('extension_uuid, COUNT(*) as aggregate')
            ->toBase()
            ->pluck('aggregate', 'extension_uuid')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * One row per bucket, oldest first, zeros included.
     *
     * @return list<array>
     */
    protected function series(StatsRange $range, array $figures): array
    {
        $buckets = array_fill_keys($range->buckets(), ['installs' => 0, 'updates' => 0, 'uninstalls' => 0, 'purchases' => 0, 'revenue' => []]);

        foreach ($figures['days'] as $day => $byExtension) {
            $bucket = $range->bucket($day);
            foreach ($byExtension as $counts) {
                $buckets[$bucket] = static::merge($buckets[$bucket], $counts);
            }
        }

        return collect($buckets)
            ->map(fn (array $counts, string $date) => ['date' => $date] + array_merge($counts, ['revenue' => static::revenueList($counts['revenue'])]))
            ->values()
            ->all();
    }

    /**
     * Active installs per release, newest version first, with each one's share.
     *
     * @return list<array{release: ?string, version: ?string, instances: int, share: float}>
     */
    protected function versions(): array
    {
        $counts = Install::query()
            ->active()
            ->whereIn('extension_uuid', $this->uuids())
            ->groupBy('release_uuid')
            ->selectRaw('release_uuid, COUNT(*) as instances')
            ->toBase()
            ->get();

        $total = max(1, (int) $counts->sum('instances'));
        $releases = Release::withTrashed()->whereIn('uuid', $counts->pluck('release_uuid')->filter())->get()->keyBy('uuid');

        return $counts
            ->map(fn ($row) => [
                'release' => $releases->get($row->release_uuid)?->public_id,
                'version' => $releases->get($row->release_uuid)?->version,
                'instances' => (int) $row->instances,
                'share' => round(((int) $row->instances) / $total, 4),
            ])
            ->sort(fn (array $a, array $b) => match (true) {
                $a['version'] === null => 1,
                $b['version'] === null => -1,
                default => Semver::compare($b['version'], $a['version']),
            })
            ->values()
            ->all();
    }

    /**
     * Figures per listing, most active instances first.
     *
     * @return list<array>
     */
    protected function perExtension(array $figures): array
    {
        return $this->extensions
            // A deleted listing shows only while it still has figures in the range.
            ->reject(fn (Extension $extension) => $extension->trashed() && static::isEmpty($figures['extensions'][$extension->uuid]))
            ->map(fn (Extension $extension) => [
                'extension' => static::extensionReference($extension),
            ] + array_merge($figures['extensions'][$extension->uuid], [
                'revenue' => static::revenueList($figures['extensions'][$extension->uuid]['revenue']),
            ]))
            ->sortBy([['active_instances', 'desc'], ['installs', 'desc'], [fn ($row) => $row['extension']['name'], 'asc']])
            ->values()
            ->all();
    }

    /**
     * The latest installs, updates, uninstalls, purchases and refunds in the range, newest first,
     * with the organization behind each.
     *
     * @return list<array>
     */
    protected function events(StatsRange $range): array
    {
        $uuids = $this->uuids();
        $between = [$range->from, $range->to];
        $instance = fn ($q) => $q->withTrashed()->with(['organization' => fn ($o) => $o->withTrashed()]);
        $releases = fn ($q) => $q->withTrashed();
        $events = collect();

        $installed = Install::query()
            ->whereIn('extension_uuid', $uuids)
            ->whereBetween('installed_at', $between)
            ->with(['instance' => $instance, 'release' => $releases, 'previousRelease' => $releases])
            ->orderByDesc('installed_at')->orderByDesc('id')
            ->limit(self::EVENTS)
            ->get();

        foreach ($installed as $install) {
            $events->push($this->event($install->previous_release_uuid ? 'update' : 'install', $install->installed_at, $install->extension_uuid, $install->instance?->organization, [
                'version' => $install->release?->version,
                'from_version' => $install->previousRelease?->version,
            ]));
        }

        $uninstalled = Install::query()
            ->whereIn('extension_uuid', $uuids)
            ->whereBetween('uninstalled_at', $between)
            ->whereNotNull('installed_at')
            ->with(['instance' => $instance, 'release' => $releases])
            ->orderByDesc('uninstalled_at')->orderByDesc('id')
            ->limit(self::EVENTS)
            ->get();

        foreach ($uninstalled as $install) {
            $events->push($this->event('uninstall', $install->uninstalled_at, $install->extension_uuid, $install->instance?->organization, [
                'version' => $install->release?->version,
            ]));
        }

        $purchases = Purchase::query()
            ->whereIn('extension_uuid', $uuids)
            ->whereBetween('created_at', $between)
            ->with(['buyer' => fn ($q) => $q->withTrashed()])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::EVENTS)
            ->get();

        foreach ($purchases as $purchase) {
            $events->push($this->event('purchase', $purchase->created_at, $purchase->extension_uuid, $purchase->buyer, [
                'amount' => (int) $purchase->amount_total,
                'currency' => $purchase->currency,
            ]));
        }

        $refunds = $this->refundEvents($range)->orderByDesc('created_at')->limit(self::EVENTS)->get();
        $refunded = Purchase::withTrashed()->whereIn('uuid', $refunds->pluck('subject_uuid'))->with(['buyer' => fn ($q) => $q->withTrashed()])->get()->keyBy('uuid');

        foreach ($refunds as $refund) {
            $purchase = $refunded->get($refund->subject_uuid);
            $events->push($this->event('refund', $refund->created_at, $purchase?->extension_uuid, $purchase?->buyer, [
                'amount' => (int) ($refund->payload['amount_refunded'] ?? 0),
                'currency' => $purchase?->currency,
                'partial' => $refund->kind === 'purchase.partially_refunded',
            ]));
        }

        return $events->sortByDesc('at')->take(self::EVENTS)->values()->all();
    }

    /**
     * Stripe refund notices (`charge.refunded`) on the listings' purchases in the range.
     */
    protected function refundEvents(StatsRange $range): Builder
    {
        return Event::query()
            ->whereIn('kind', self::REFUND_EVENTS)
            ->where('subject_type', (new Purchase)->getMorphClass())
            ->whereIn('subject_uuid', Purchase::withTrashed()->select('uuid')->whereIn('extension_uuid', $this->uuids()))
            ->whereBetween('created_at', [$range->from, $range->to]);
    }

    protected function event(string $type, ?Carbon $at, ?string $extensionUuid, ?Organization $organization, array $details = []): array
    {
        $extension = $this->extensions->firstWhere('uuid', $extensionUuid);

        return [
            'type' => $type,
            'at' => $at?->toIso8601String(),
            'extension' => $extension ? static::extensionReference($extension) : null,
            'organization' => $organization ? ['id' => $organization->public_id, 'slug' => $organization->slug, 'name' => $organization->name] : null,
        ] + array_merge(['version' => null, 'from_version' => null, 'amount' => null, 'currency' => null], $details);
    }

    /**
     * @return array{id: string, slug: string, name: string, status: string}
     */
    public static function extensionReference(Extension $extension): array
    {
        return ['id' => $extension->public_id, 'slug' => $extension->slug, 'name' => $extension->name, 'status' => $extension->status];
    }

    /**
     * Add a purchase to revenue keyed by currency: gross, refunded, Fleetbase's fee (after
     * refunds) and the publisher's net (after refunds).
     *
     * @param  array<string, array>  $revenue
     * @return array<string, array>
     */
    protected static function addRevenue(array $revenue, Purchase $purchase): array
    {
        $figures = FinanceReport::figures($purchase);
        $line = $revenue[$purchase->currency] ?? ['currency' => $purchase->currency, 'purchases' => 0, 'gross' => 0, 'refunded' => 0, 'fee' => 0, 'net' => 0];

        $line['purchases']++;
        $line['gross'] += $figures['gross'];
        $line['refunded'] += $figures['refunded'];
        $line['fee'] += $figures['fee_revenue'];
        $line['net'] += $figures['publisher_net'];
        $revenue[$purchase->currency] = $line;

        return $revenue;
    }

    /**
     * Counts added together; revenue merged per currency.
     */
    protected static function merge(array $into, array $counts): array
    {
        foreach (['installs', 'updates', 'uninstalls', 'purchases'] as $field) {
            $into[$field] += $counts[$field];
        }

        foreach ($counts['revenue'] as $currency => $line) {
            $mine = $into['revenue'][$currency] ?? array_map(fn ($value) => is_int($value) ? 0 : $value, $line);
            foreach (['purchases', 'gross', 'refunded', 'fee', 'net'] as $field) {
                $mine[$field] += $line[$field];
            }
            $into['revenue'][$currency] = $mine;
        }

        return $into;
    }

    protected static function isEmpty(array $counts): bool
    {
        return $counts['installs'] + $counts['updates'] + $counts['uninstalls'] + $counts['purchases'] + $counts['active_instances'] === 0;
    }

    /**
     * Revenue keyed by currency as a list, highest net first.
     *
     * @return list<array>
     */
    protected static function revenueList(array $revenue): array
    {
        return collect($revenue)->sortBy([['net', 'desc'], ['currency', 'asc']])->values()->all();
    }
}
