<?php

namespace App\Http\Middleware;

use App\Http\Resources\AccountResource;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

/**
 * Props every website page receives: the signed-in account (from the cookie session), the
 * registry's public host for install commands, and the console version operators compare
 * extensions against.
 */
class HandleInertiaRequests extends Middleware
{
    /** Cookie holding the console version the visitor checks compatibility against. */
    public const CONSOLE_COOKIE = 'registry_console';

    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var Account|null $account */
        $account = Auth::guard('web')->user();

        return [
            ...parent::share($request),
            'auth' => [
                'account' => $account ? (new AccountResource($account->loadMissing('organizations')))->resolve($request) : null,
            ],
            'registry' => [
                'host' => parse_url((string) (config('registry.public_url') ?: $request->getSchemeAndHttpHost()), PHP_URL_HOST) ?: $request->getHost(),
                'url' => rtrim((string) (config('registry.public_url') ?: $request->getSchemeAndHttpHost()), '/'),
            ],
            'console' => $this->consoleVersion($request),
            'flash' => fn () => $request->session()->get('flash'),
        ];
    }

    /**
     * The console version from `?console=` (a console linking here sends it) or the cookie.
     */
    protected function consoleVersion(Request $request): ?string
    {
        $version = $request->query('console') ?: $request->cookie(self::CONSOLE_COOKIE);

        return is_string($version) && preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) ? $version : null;
    }
}
