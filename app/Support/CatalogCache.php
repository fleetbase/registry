<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Keys for the public catalog's response cache. Every key embeds a generation id, so
 * `flush()` invalidates every cached catalog page at once on any cache store (the database
 * and array stores cannot delete by prefix). Called when a release is published or yanked
 * and when a listing is suspended. HTTP caches still honour their `max-age`.
 */
class CatalogCache
{
    public const GENERATION_KEY = 'catalog:generation';

    public static function key(Request $request): string
    {
        return 'catalog:'.static::generation().':'.sha1($request->fullUrl());
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
