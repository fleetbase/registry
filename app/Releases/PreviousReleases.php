<?php

namespace App\Releases;

use App\Models\Release;
use Illuminate\Support\Collection;

/**
 * The version a release updates: its extension's latest published (not yanked) release other
 * than itself, by `published_at` then id, the order `ReviewPolicy` uses. For a release that is
 * already published, only releases published before it count, so a decided release does not
 * show a later version as its predecessor. Null for a first release.
 *
 * Set as the `previousRelease` relation (a model or null) so it is never saved with the
 * release. One query for any number of releases.
 */
class PreviousReleases
{
    /** Enough for the queue's "Update from vX". */
    public const SUMMARY_COLUMNS = ['id', 'uuid', 'public_id', 'extension_uuid', 'version', 'status', 'published_at'];

    /**
     * @param  iterable<Release>  $releases
     * @param  list<string>  $columns  columns to load on each previous release (`['*']` for its manifest and compat)
     */
    public static function attach(iterable $releases, array $columns = self::SUMMARY_COLUMNS): void
    {
        $releases = Collection::make($releases);
        $extensions = $releases->pluck('extension_uuid')->filter()->unique()->values();

        $published = $extensions->isEmpty() ? collect() : Release::query()
            ->select($columns === ['*'] ? ['*'] : array_values(array_unique(array_merge($columns, self::SUMMARY_COLUMNS))))
            ->whereIn('extension_uuid', $extensions)
            ->published()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('extension_uuid');

        foreach ($releases as $release) {
            $previous = ($published[$release->extension_uuid] ?? collect())
                ->first(fn (Release $candidate) => $candidate->getKey() !== $release->getKey() && self::before($candidate, $release));

            $release->setRelation('previousRelease', $previous);
        }
    }

    /**
     * Whether `$candidate` went out before `$release`: always, unless `$release` is published
     * itself, in which case it must have been published earlier (ties broken by id).
     */
    protected static function before(Release $candidate, Release $release): bool
    {
        if ($release->published_at === null || $candidate->published_at === null) {
            return true;
        }

        return $candidate->published_at->lt($release->published_at)
            || ($candidate->published_at->eq($release->published_at) && $candidate->getKey() < $release->getKey());
    }
}
