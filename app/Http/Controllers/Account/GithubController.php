<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The account's GitHub connection. Connecting goes through the website's OAuth flow
 * (GET /auth/github while signed in); this disconnects it.
 */
class GithubController extends Controller
{
    /**
     * DELETE /v1/account/github — 422 when the account has no password, since GitHub is then
     * its only way to sign in.
     */
    public function destroy(Request $request): JsonResponse|AccountResource
    {
        /** @var Account $account */
        $account = $request->user();

        if ($account->github_id === null) {
            return response()->error('GitHub isn’t connected to this account.', 422);
        }

        if (! $account->password) {
            return response()->error('Set a password before disconnecting GitHub, or you won’t be able to sign in. Use Forgot password on the sign-in page to set one.', 422, ['code' => 'password_required']);
        }

        $login = $account->github_login;
        $account->forceFill(['github_id' => null, 'github_login' => null])->save();
        Event::record('account.github_disconnected', $account, $account, ['github_login' => $login], $request->ip());

        return new AccountResource($account->load('organizations'));
    }
}
