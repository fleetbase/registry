<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the publisher website: the Ember build's `public/index.html` for every GET path
 * the API does not own, so the app's client-side routes deep-link. API, package and health
 * paths, and every non-GET method, answer the JSON 404 envelope instead.
 */
class WebsiteController extends Controller
{
    /** Paths that never fall back to the website. */
    public const API_PATHS = ['v1', 'v1/*', 'npm', 'npm/*', 'composer', 'composer/*', 'up'];

    public function __invoke(Request $request): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            throw new NotFoundHttpException;
        }

        if ($request->is(...self::API_PATHS)) {
            throw new NotFoundHttpException;
        }

        // A built asset the web server did not serve itself (php artisan serve does).
        $public = realpath(public_path());
        $file = realpath(public_path(ltrim($request->path(), '/')));
        if ($request->path() !== '/' && $file !== false && $public !== false && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file) && basename($file) !== 'index.php') {
            return response()->file($file);
        }

        $index = public_path('index.html');
        if (! is_file($index)) {
            return response()->error('The website has not been built. Run npm run build in web/.', 503);
        }

        return response()->file($index, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
