<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Keys for the public catalog's response cache. Every key embeds a generation id, so
 * `flush()` invalidates every cached catalog page at once on any cache store (the database
 * and array stores cannot delete by prefix). Called when a release is published or yanked
 * and when a listing is suspended. HTTP caches still honour their `max-age`; responses that
 * include private listings are `Cache-Control: private` and never reach shared caches.
 */
class CatalogCache
{
    public const GENERATION_KEY = 'catalog:generation';

    /**
     * The anonymous key, or, for a caller who may see private listings, a key that also names
     * exactly those listings: callers with the same access share entries, nobody else does, and
     * a revoked grant changes the set (and so the key) on the next request.
     *
     * @param  list<string>  $privateExtensionUuids  sorted
     */
    public static function key(Request $request, array $privateExtensionUuids = []): string
    {
        $key = 'catalog:'.static::generation().':'.sha1($request->fullUrl());

        return $privateExtensionUuids === [] ? $key : $key.':private:'.sha1(implode(',', $privateExtensionUuids));
    }

    public static function generation(): string
    {
        return (string) Cache::rememberForever(self::GENERATION_KEY, fn () => (string) Str::uuid());
    }

    public static function flush(): void
    {
        Cache::forever(self::GENERATION_KEY, (string) Str::uuid());
    }
}
