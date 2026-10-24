<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Models\Event;
use App\Models\Token;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    /**
     * GET /v1/account
     */
    public function show(Request $request): AccountResource
    {
        return new AccountResource($request->user()->load('organizations'));
    }

    /**
     * PATCH /v1/account — name, username, timezone. The personal organization keeps its slug.
     */
    public function update(UpdateAccountRequest $request): AccountResource
    {
        /** @var Account $account */
        $account = $request->user();
        $attributes = $request->validated();

        $account->fill($attributes)->save();
        Event::record('account.updated', $account, $account, ['fields' => array_keys($attributes)], $request->ip());

        return new AccountResource($account->load('organizations'));
    }

    /**
     * POST /v1/account/password — current_password, password, password_confirmation. Revokes
     * the account's other website sessions; personal tokens stay valid.
     */
    public function password(ChangePasswordRequest $request): JsonResponse
    {
        /** @var Account $account */
        $account = $request->user();

        if (! $account->password || ! Hash::check($request->input('current_password'), $account->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }

        $account->forceFill(['password' => $request->input('password')])->save();

        $current = $account->currentToken();
        $account->tokens()
            ->active()
            ->where('prefix', Token::PREFIX_SESSION)
            ->when($current, fn ($q) => $q->whereKeyNot($current->getKey()))
            ->get()
            ->each->revoke();

        Event::record('account.password_changed', $account, $account, [], $request->ip());

        return response()->json(['message' => 'Password changed.']);
    }
}
