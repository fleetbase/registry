<?php

namespace App\Providers;

use App\Auth\TokenGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Fleetbase resources are returned unwrapped; paginated lists get { data, meta }.
        JsonResource::withoutWrapping();

        Model::shouldBeStrict(! $this->app->isProduction());

        $this->registerAuthGuard();
        $this->registerResponseMacros();
        $this->registerRateLimiters();

        // Package metadata embeds absolute download URLs; pin them behind proxies.
        if ($publicUrl = config('registry.public_url')) {
            URL::forceRootUrl($publicUrl);
            URL::forceScheme(parse_url($publicUrl, PHP_URL_SCHEME) ?: 'https');
        }
    }

    /**
     * `auth:token` resolves personal tokens (flbp_…) to accounts;
     * `auth:instance` resolves instance tokens (flbi_…) to instances.
     */
    protected function registerAuthGuard(): void
    {
        Auth::viaRequest('registry-token', new TokenGuard(TokenGuard::KIND_PERSONAL));
        Auth::viaRequest('registry-instance-token', new TokenGuard(TokenGuard::KIND_INSTANCE));
    }

    /**
     * `response()->error('message', 422)` → { "errors": ["message"], ...extra }
     */
    protected function registerResponseMacros(): void
    {
        Response::macro('error', function ($error, int $statusCode = 400, array $data = []): JsonResponse {
            if ($error instanceof MessageBag) {
                $error = $error->all();
            }

            return Response::json(array_merge(['errors' => is_array($error) ? array_values($error) : [$error]], $data), $statusCode);
        });

        // Package routes add npm's `error` string (shown by the npm CLI) beside the envelope.
        Response::macro('packageError', function (string $message, int $statusCode, array $headers = []): JsonResponse {
            return Response::json(['errors' => [$message], 'error' => $message], $statusCode, $headers);
        });
    }

    protected function registerRateLimiters(): void
    {
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(5)->by('email:'.strtolower((string) $request->input('email')).':'.$request->ip()),
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->uuid ?: $request->ip()));

        RateLimiter::for('instances', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->uuid ?: $request->ip()));

        RateLimiter::for('instance-register', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('catalog', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));

        // npm and Composer fetch metadata and archives in bursts during an install.
        RateLimiter::for('packages', fn (Request $request) => Limit::perMinute(1200)->by($request->bearerToken() ? sha1($request->bearerToken()) : $request->ip()));
    }
}
