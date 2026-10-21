<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Request classification helpers mirroring `Fleetbase\Support\Http`.
 *
 * Public requests are everything under `/v1`. Internal requests are the
 * staff/console surface under `/int` (not built yet) and expose internal ids.
 */
class Http
{
    public static function isInternalRequest(?Request $request = null): bool
    {
        $request = $request ?? request();
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        return Str::startsWith(ltrim((string) $route->uri(), '/'), 'int/');
    }

    public static function isPublicRequest(?Request $request = null): bool
    {
        $request = $request ?? request();
        $route = $request->route();

        if ($route === null) {
            return false;
        }

        return Str::startsWith(ltrim((string) $route->uri(), '/'), 'v1/');
    }
}
