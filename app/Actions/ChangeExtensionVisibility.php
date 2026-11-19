<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\Event;
use App\Models\Extension;
use App\Models\Release;
use App\Support\CatalogCache;

/**
 * Switch a listing between public and private.
 *
 * - Public to private takes effect at once: the listing leaves the catalog and every package
 *   endpoint answers 404 to callers without access. Paid licences already sold keep working.
 * - Private to public takes effect at once only when nothing was released yet. Otherwise the
 *   listing stays private with `meta.pending_visibility = public` until a release that went
 *   through human review (review policy: first public release) is published, so nothing
 *   released without review becomes public. Asking for private again cancels the request.
 */
class ChangeExtensionVisibility
{
    /**
     * @return string what happened: `unchanged`, `changed` or `pending`
     */
    public function __invoke(Extension $extension, string $visibility, ?Account $actor): string
    {
        $from = $extension->visibility;

        if ($visibility === Extension::VISIBILITY_PRIVATE) {
            if ($extension->isPrivate()) {
                if ($extension->pendingVisibility() === null) {
                    return 'unchanged';
                }
                $this->setPending($extension, null);
                Event::record('extension.visibility_request_cancelled', $extension, $actor);

                return 'changed';
            }

            $extension->forceFill(['visibility' => Extension::VISIBILITY_PRIVATE])->save();
            $this->record($extension, $actor, $from, $visibility);

            return 'changed';
        }

        if (! $extension->isPrivate()) {
            return 'unchanged';
        }

        $released = $extension->releases()->whereIn('status', [Release::STATUS_PUBLISHED, Release::STATUS_YANKED])->exists();
        if (! $released) {
            $this->setPending($extension, null, save: false);
            $extension->forceFill(['visibility' => Extension::VISIBILITY_PUBLIC])->save();
            $this->record($extension, $actor, $from, $visibility);

            return 'changed';
        }

        if ($extension->pendingVisibility() === null) {
            $this->setPending($extension, Extension::VISIBILITY_PUBLIC);
            Event::record('extension.visibility_requested', $extension, $actor, ['to' => Extension::VISIBILITY_PUBLIC]);
        }

        return 'pending';
    }

    protected function setPending(Extension $extension, ?string $pending, bool $save = true): void
    {
        $meta = (array) $extension->meta;
        if ($pending === null) {
            unset($meta['pending_visibility']);
        } else {
            $meta['pending_visibility'] = $pending;
        }
        $extension->forceFill(['meta' => $meta ?: null]);

        if ($save) {
            $extension->save();
        }
    }

    protected function record(Extension $extension, ?Account $actor, ?string $from, string $to): void
    {
        Event::record('extension.visibility_changed', $extension, $actor, ['from' => $from, 'to' => $to]);
        CatalogCache::flush();
    }
}
