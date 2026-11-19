<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\TrustedPublishing\GithubOidc;
use App\TrustedPublishing\NoMatchingPublisher;
use App\TrustedPublishing\OidcTokenInvalid;
use App\TrustedPublishing\TokenExchange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trusted publishing: a GitHub Actions workflow trades its OIDC token for a short-lived publish
 * token, so no long-lived registry token is stored in the repository's secrets.
 */
class TrustedPublishingController extends Controller
{
    /**
     * POST /v1/auth/oidc/github — `{token, extension}` where `extension` is the listing's slug,
     * `ext_` id or one of its package names. 200 with the `flbt_` token; 401 when the OIDC token
     * does not verify; 403 when no trusted publisher of the extension matches its claims (the
     * same answer for an unknown extension).
     */
    public function github(Request $request, GithubOidc $oidc, TokenExchange $exchange): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:8192'],
            'extension' => ['required', 'string', 'max:255'],
        ]);

        try {
            $claims = $oidc->verify((string) $request->input('token'));
        } catch (OidcTokenInvalid $e) {
            return response()->error($e->getMessage(), 401);
        } catch (\RuntimeException $e) {
            report($e);

            return response()->error('The registry could not reach GitHub to verify the token; try again.', 503);
        }

        try {
            ['token' => $token, 'publisher' => $publisher, 'extension' => $extension] = $exchange->exchange($claims, (string) $request->input('extension'));
        } catch (NoMatchingPublisher $e) {
            return response()->error($e->getMessage(), 403);
        }

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->expires_at?->toIso8601String(),
            'scopes' => $token->scopes,
            'extension' => ['id' => $extension->public_id, 'slug' => $extension->slug],
            'trusted_publisher' => ['id' => $publisher->public_id, 'repository' => $publisher->repository, 'workflow' => $publisher->workflow],
        ])->header('Cache-Control', 'no-store');
    }
}
