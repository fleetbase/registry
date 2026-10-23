<?php

namespace App\Http\Controllers\Web;

use App\Commerce\Stripe;
use App\Http\Controllers\Catalog\CatalogController;
use App\Http\Controllers\Organization\PayoutController;
use App\Http\Controllers\Organization\StripeConnectController;
use App\Http\Controllers\Publisher\ExtensionController;
use App\Http\Controllers\Publisher\ReleaseController;
use App\Models\Release;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Publisher Studio: my extensions, create, the listing editor, releases with live checks,
 * review status, history, payouts and the coming-soon insights.
 */
class StudioPageController extends WebController
{
    /** Release statuses still moving through the pipeline (the editor's "candidate"). */
    public const OPEN = [
        Release::STATUS_UPLOADED, Release::STATUS_CHECKING, Release::STATUS_FAILED_CHECKS, Release::STATUS_SUBMITTED,
        Release::STATUS_IN_REVIEW, Release::STATUS_APPROVED, Release::STATUS_REJECTED,
    ];

    /**
     * GET /studio — every listing the account can publish, with its releases.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Studio/Index', [
            'extensions' => $this->api($request, '/v1/publisher/extensions', [], ExtensionController::class, 'index'),
            'organization' => $request->query('org'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Studio/Create', [
            'categories' => $this->categories($request),
        ]);
    }

    /**
     * GET /studio/{slug} — the listing editor.
     */
    public function editor(Request $request, string $slug): Response
    {
        return Inertia::render('Studio/Editor', $this->listing($request, $slug) + [
            'categories' => $this->categories($request),
        ]);
    }

    /**
     * GET /studio/{slug}/releases — upload and the release list.
     */
    public function releases(Request $request, string $slug): Response
    {
        return Inertia::render('Studio/Releases', $this->listing($request, $slug));
    }

    /**
     * GET /studio/{slug}/releases/{release} — one release with its checks; the page polls this
     * (a partial reload of `release`) while the checks run.
     */
    public function release(Request $request, string $slug, string $release): Response
    {
        $extension = $this->api($request, "/v1/publisher/extensions/{$slug}", [], ExtensionController::class, 'show', ['slug' => $slug]);

        return Inertia::render('Studio/Release', [
            'extension' => $extension,
            'release' => function () use ($request, $release, $extension) {
                $detail = $this->api($request, "/v1/publisher/releases/{$release}", [], ReleaseController::class, 'show', ['release' => $release]);
                // A release of another of the publisher's listings is not found under this one.
                abort_if(($detail['extension']['id'] ?? null) !== $extension['id'], 404);

                return $detail;
            },
        ]);
    }

    /**
     * GET /studio/{slug}/review — submit the open release, or follow its review.
     */
    public function review(Request $request, string $slug): Response
    {
        $listing = $this->listing($request, $slug);
        $open = collect($listing['releases'])->first(fn (array $release) => in_array($release['status'], self::OPEN, true));

        return Inertia::render('Studio/Review', $listing + [
            'candidate' => $open
                ? $this->api($request, "/v1/publisher/releases/{$open['id']}", [], ReleaseController::class, 'show', ['release' => $open['id']])
                : null,
        ]);
    }

    /**
     * GET /studio/{slug}/history — every release's review timeline, newest first.
     */
    public function history(Request $request, string $slug): Response
    {
        $listing = $this->listing($request, $slug);
        $details = collect($listing['releases'])->take(20)->map(
            fn (array $release) => $this->api($request, "/v1/publisher/releases/{$release['id']}", [], ReleaseController::class, 'show', ['release' => $release['id']])
        )->values()->all();

        return Inertia::render('Studio/History', [
            'extension' => $listing['extension'],
            'releases' => $details,
            'truncated' => count($listing['releases']) > 20,
        ]);
    }

    public function insights(): Response
    {
        return Inertia::render('Studio/Insights');
    }

    /**
     * GET /studio/payouts[/{slug}] — an organization's Stripe Connect account (onboarding and
     * management through Connect.js), balance, payout schedule and sales.
     */
    public function payouts(Request $request, ?string $slug = null): Response
    {
        [$organizations, $organization] = $this->billingOrganizations($request, $slug);
        $payouts = $error = $transactions = null;

        if ($organization) {
            $slug = $organization['slug'];
            [$payouts, $error] = $this->tryApi($request, "/v1/organizations/{$slug}/payouts", [], PayoutController::class, 'summary', ['slug' => $slug]);
            $payouts ??= ['connect' => $this->api($request, "/v1/organizations/{$slug}/stripe", [], StripeConnectController::class, 'show', ['slug' => $slug])];
            $transactions = $this->api($request, "/v1/organizations/{$slug}/payouts/transactions", $this->only($request, ['page', 'from', 'to', 'status']), PayoutController::class, 'transactions', ['slug' => $slug]);
        }

        return Inertia::render('Studio/Payouts', [
            'organizations' => $organizations,
            'organization' => $organization,
            'payouts' => $payouts,
            'transactions' => $transactions,
            'filters' => $this->only($request, ['from', 'to', 'status']),
            'error' => $error,
            'stripe' => ['configured' => Stripe::configured(), 'publishable_key' => config('services.stripe.key')],
        ]);
    }

    /**
     * The listing (as its publisher sees it) and every release with its checks, newest first.
     *
     * @return array{extension: array, releases: list<array>}
     */
    protected function listing(Request $request, string $slug): array
    {
        return [
            'extension' => $this->api($request, "/v1/publisher/extensions/{$slug}", [], ExtensionController::class, 'show', ['slug' => $slug]),
            'releases' => $this->api($request, "/v1/publisher/extensions/{$slug}/releases", [], ReleaseController::class, 'index', ['slug' => $slug]),
        ];
    }

    protected function categories(Request $request): array
    {
        return $this->api($request, '/v1/catalog/categories', [], CatalogController::class, 'categories');
    }
}
