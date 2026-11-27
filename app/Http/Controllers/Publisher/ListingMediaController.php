<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Resources\PublisherExtensionResource;
use App\Models\Event;
use App\Models\Extension;
use App\Models\File;
use App\Services\ReleaseStorage;
use App\Support\CatalogCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Listing icon and screenshots, stored through ReleaseStorage under the `icons/` and
 * `screenshots/` prefixes as `files` rows of the listing. Images only: PNG, JPEG or WebP,
 * checked by content. SVG is refused because it can carry script when served from our
 * origin. Like other listing edits, media changes never need review.
 */
class ListingMediaController extends PublisherController
{
    public const IMAGE_MIMES = 'png,jpg,jpeg,webp';

    public const ICON_MAX_KB = 1024;

    public const SCREENSHOT_MAX_KB = 5120;

    public function __construct(protected ReleaseStorage $storage) {}

    /**
     * POST /v1/publisher/extensions/{slug}/icon — multipart `icon` (PNG, JPEG or WebP, 1 MB).
     * Replaces the current icon.
     */
    public function storeIcon(Request $request, string $slug): PublisherExtensionResource
    {
        $extension = $this->findExtension($request, $slug, ['icon']);
        $request->validate(['icon' => ['required', 'file', 'mimes:'.self::IMAGE_MIMES, 'max:'.self::ICON_MAX_KB]]);
        $upload = $this->image($request->file('icon'), 'icon');

        $previous = $extension->icon;
        $file = $this->storage->store($upload->getRealPath(), File::TYPE_ICON, $extension, [
            'name' => 'icon.'.$upload->guessExtension(),
            'original_filename' => $upload->getClientOriginalName(),
            'uploader_account_uuid' => $this->account($request)->uuid,
            'mime' => $upload->getMimeType(),
        ]);

        $extension->forceFill(['icon_file_uuid' => $file->uuid])->save();
        if ($previous !== null) {
            $this->storage->delete($previous);
        }

        return $this->changed($request, $extension, 'extension.icon_updated', ['file' => $file->public_id]);
    }

    /**
     * DELETE /v1/publisher/extensions/{slug}/icon
     */
    public function destroyIcon(Request $request, string $slug): PublisherExtensionResource
    {
        $extension = $this->findExtension($request, $slug, ['icon']);
        $icon = $extension->icon;

        if ($icon !== null) {
            $extension->forceFill(['icon_file_uuid' => null])->save();
            $this->storage->delete($icon);
        }

        return $this->changed($request, $extension, 'extension.icon_removed', ['file' => $icon?->public_id]);
    }

    /**
     * POST /v1/publisher/extensions/{slug}/screenshots — multipart `screenshot` (PNG, JPEG or
     * WebP, 5 MB) and optional `caption`. Added last; at most 8 per listing.
     */
    public function storeScreenshot(Request $request, string $slug): JsonResponse
    {
        $extension = $this->findExtension($request, $slug, ['screenshots']);
        $request->validate([
            'screenshot' => ['required', 'file', 'mimes:'.self::IMAGE_MIMES, 'max:'.self::SCREENSHOT_MAX_KB],
            'caption' => ['nullable', 'string', 'max:255'],
        ]);
        $upload = $this->image($request->file('screenshot'), 'screenshot');

        if ($extension->screenshots->count() >= Extension::MAX_SCREENSHOTS) {
            throw ValidationException::withMessages(['screenshot' => 'A listing can have at most '.Extension::MAX_SCREENSHOTS.' screenshots. Delete one first.']);
        }

        $order = $extension->screenshots->max(fn (File $file) => $file->meta['order'] ?? -1);
        $order = max((int) ($order ?? -1), $extension->screenshots->count() - 1) + 1;

        $file = $this->storage->store($upload->getRealPath(), File::TYPE_SCREENSHOT, $extension, [
            'name' => 'screenshot.'.$upload->guessExtension(),
            'original_filename' => $upload->getClientOriginalName(),
            'uploader_account_uuid' => $this->account($request)->uuid,
            'mime' => $upload->getMimeType(),
            'meta' => array_filter(['caption' => $request->input('caption'), 'order' => $order], fn ($value) => $value !== null),
        ]);

        return $this->changed($request, $extension, 'extension.screenshot_added', ['file' => $file->public_id])->response()->setStatusCode(201);
    }

    /**
     * PATCH /v1/publisher/extensions/{slug}/screenshots — `order`: every screenshot id of the
     * listing, once, in display order.
     */
    public function reorderScreenshots(Request $request, string $slug): PublisherExtensionResource
    {
        $extension = $this->findExtension($request, $slug, ['screenshots']);
        $request->validate([
            'order' => ['required', 'array', 'max:'.Extension::MAX_SCREENSHOTS],
            'order.*' => ['required', 'string', 'distinct'],
        ]);

        $ids = array_values($request->input('order'));
        $files = $extension->screenshots->keyBy('public_id');
        if (count($ids) !== $files->count() || array_diff($ids, $files->keys()->all()) !== []) {
            throw ValidationException::withMessages(['order' => 'The order must list every screenshot of the listing exactly once.']);
        }

        foreach ($ids as $position => $id) {
            $file = $files->get($id);
            $file->forceFill(['meta' => array_merge($file->meta ?? [], ['order' => $position])])->save();
        }

        return $this->changed($request, $extension, 'extension.screenshots_reordered', ['order' => $ids]);
    }

    /**
     * DELETE /v1/publisher/extensions/{slug}/screenshots/{file}
     */
    public function destroyScreenshot(Request $request, string $slug, string $file): PublisherExtensionResource
    {
        $extension = $this->findExtension($request, $slug, ['screenshots']);
        $screenshot = $extension->screenshots->first(fn (File $screenshot) => $screenshot->public_id === $file || $screenshot->uuid === $file);

        if ($screenshot === null) {
            throw new NotFoundHttpException('Screenshot not found.');
        }

        $this->storage->delete($screenshot);

        return $this->changed($request, $extension, 'extension.screenshot_removed', ['file' => $screenshot->public_id]);
    }

    /**
     * The upload, once its bytes decode as an image (the `mimes` rule only sniffs the type).
     */
    protected function image(UploadedFile $upload, string $field): UploadedFile
    {
        $size = @getimagesize($upload->getRealPath());
        if ($size === false || ! in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages([$field => 'The '.$field.' must be a PNG, JPEG or WebP image.']);
        }

        return $upload;
    }

    /**
     * Record the change, flush the catalog for published listings and return the listing.
     */
    protected function changed(Request $request, Extension $extension, string $event, array $payload): PublisherExtensionResource
    {
        Event::record($event, $extension, $this->account($request), $payload, $request->ip());

        if ($extension->status === Extension::STATUS_PUBLISHED) {
            CatalogCache::flush();
        }

        return new PublisherExtensionResource($extension->fresh(ExtensionController::DETAIL));
    }
}
