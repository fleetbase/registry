<?php

namespace App\Http\Controllers\Web;

use App\Commerce\Stripe;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Organization\OrganizationController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Base for the website's Inertia page controllers.
 *
 * Pages get their props from the same controllers that serve /v1, called in-process with a GET
 * request for the matching /v1 URL, so a page sees exactly what the API returns, the catalog
 * cache (keyed by URL) is shared with real API calls, and the business rules live in one place.
 * Writes go from the browser to /v1 with the session cookie.
 */
abstract class WebController extends Controller
{
    /**
     * Call an API controller action as if `GET $uri?$query` had reached it, and return its JSON
     * body as an array. The caller's account and session carry over.
     *
     * @param  class-string  $controller
     * @param  array<string, mixed>  $parameters  route parameters (slug, release…)
     */
    protected function api(Request $request, string $uri, array $query, string $controller, string $method, array $parameters = []): array
    {
        $sub = Request::create($uri, 'GET', $query);
        $sub->setUserResolver($request->getUserResolver());
        if ($request->hasSession()) {
            $sub->setLaravelSession($request->session());
        }

        $previous = app('request');
        app()->instance('request', $sub);

        try {
            $result = app()->call([app($controller), $method], ['request' => $sub] + $parameters);

            if ($result instanceof JsonResource) {
                return $result->response($sub)->getData(true);
            }

            if ($result instanceof JsonResponse) {
                return $result->getData(true);
            }

            return (array) $result;
        } finally {
            app()->instance('request', $previous);
        }
    }

    /**
     * The caller's organizations where they see money (owners, admins, billing members), and the
     * selected one: `$slug`, else the first that is not personal, else the first.
     *
     * @return array{0: list<array>, 1: ?array}
     */
    protected function billingOrganizations(Request $request, ?string $slug): array
    {
        $organizations = collect($this->api($request, '/v1/organizations', [], OrganizationController::class, 'index'))
            ->filter(fn (array $organization) => in_array($organization['role'] ?? null, OrganizationController::BILLING_ROLES, true))
            ->values();

        $selected = $slug !== null
            ? $organizations->firstWhere('slug', $slug)
            : ($organizations->firstWhere('is_personal', false) ?? $organizations->first());

        if ($slug !== null && $selected === null) {
            abort(404);
        }

        return [$organizations->all(), $selected];
    }

    /**
     * Like api(), but a failure (Stripe not configured or unreachable) answers null and the
     * reason instead of an error page.
     *
     * @return array{0: ?array, 1: ?string}
     */
    protected function tryApi(Request $request, string $uri, array $query, string $controller, string $method, array $parameters = []): array
    {
        try {
            return [$this->api($request, $uri, $query, $controller, $method, $parameters), null];
        } catch (HttpExceptionInterface $e) {
            return [null, $e->getMessage()];
        } catch (ApiErrorException $e) {
            report($e);

            return [null, Stripe::failure($e)];
        }
    }

    /**
     * Only the query parameters a listing page passes through to the API.
     *
     * @param  list<string>  $keys
     */
    protected function only(Request $request, array $keys): array
    {
        return array_filter($request->only($keys), fn ($value) => $value !== null && $value !== '');
    }
}
