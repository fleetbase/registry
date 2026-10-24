<?php

namespace App\Http\Controllers\Auth;

use App\Models\Account;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The sign-in checks shared by token login and website session sign-in: 401 for a wrong
 * email or password, 403 `email_unverified`, 403 `account_<status>` for inactive accounts.
 */
trait ChecksCredentials
{
    /**
     * The account behind `email` and `password`, or the error response to return.
     * The password check uses Hash::check, the same as core-api's Auth::checkPassword.
     */
    protected function attempt(Request $request): Account|JsonResponse
    {
        $email = Str::lower((string) $request->input('email'));
        $account = Account::where('email', $email)->first();

        if (! $account || ! $account->password || ! Hash::check((string) $request->input('password'), $account->password)) {
            Event::record('account.login_failed', $account, null, ['email' => $email], $request->ip());

            return response()->error('Invalid email or password.', 401);
        }

        if (! $account->hasVerifiedEmail()) {
            return response()->error('Email address is not verified.', 403, ['code' => 'email_unverified']);
        }

        if (! $account->isActive()) {
            return response()->error('This account is '.$account->status.'.', 403, ['code' => 'account_'.$account->status]);
        }

        return $account;
    }
}
