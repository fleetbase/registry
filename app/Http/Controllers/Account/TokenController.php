<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\CreateTokenRequest;
use App\Http\Resources\TokenResource;
use App\Models\Account;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Token;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TokenController extends Controller
{
    /**
     * GET /v1/account/tokens — the caller's tokens, newest first, masked. Website session
     * tokens (`flbs_`) are not listed; they end with their session.
     */
    public function index(Request $request)
    {
        /** @var Account $account */
        $account = $request->user();

        $tokens = $account->tokens()
            ->with('organization')
            ->whereNull('revoked_at')
            ->where('prefix', '!=', Token::PREFIX_SESSION)
            ->orderByDesc('created_at')
            ->get();

        return TokenResource::collection($tokens);
    }

    /**
     * POST /v1/account/tokens — creates a token; the plaintext is only returned here.
     */
    public function store(CreateTokenRequest $request): JsonResponse
    {
        /** @var Account $account */
        $account = $request->user();

        $organizationUuid = null;
        if ($request->filled('organization')) {
            $organization = $account->organizations()
                ->where(fn ($q) => $q->where('organizations.public_id', $request->input('organization'))->orWhere('organizations.slug', $request->input('organization')))
                ->first();

            if (! $organization instanceof Organization) {
                return response()->error('You are not a member of that organization.', 422);
            }

            $organizationUuid = $organization->uuid;
        }

        $token = Token::issue(
            $account,
            $request->input('name'),
            $request->input('scopes'),
            now()->addDays((int) ($request->input('expires_in_days') ?: Token::MAX_LIFETIME_DAYS)),
            $organizationUuid,
        );

        $token->load('organization');
        Event::record('token.created', $token, $account, ['scopes' => $token->scopes], $request->ip());

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->expires_at,
            'token_details' => (new TokenResource($token))->resolve($request),
        ], 201);
    }

    /**
     * DELETE /v1/account/tokens/{token} — revokes one of the caller's tokens.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var Account $account */
        $account = $request->user();

        $token = $account->tokens()
            ->where('prefix', '!=', Token::PREFIX_SESSION)
            ->where(fn ($q) => $q->where('public_id', $id)->orWhere('uuid', $id))
            ->first();

        if (! $token) {
            return response()->error('Token not found.', 404);
        }

        $token->revoke();
        Event::record('token.revoked', $token, $account, [], $request->ip());

        return response()->json(['revoked' => true, 'id' => $token->public_id]);
    }
}
