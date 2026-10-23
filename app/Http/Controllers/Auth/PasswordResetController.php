<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Mail\PasswordResetCodeMail;
use App\Models\Account;
use App\Models\Event;
use App\Models\VerificationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Forgot password: a six-digit code by email (a `password_reset` verification code, so an
 * email-verification code never resets a password), then the code and a new password.
 */
class PasswordResetController extends Controller
{
    /**
     * POST /v1/auth/password/forgot — always 202 with the same body, so it cannot tell whether
     * an account exists. Accounts that exist and are not suspended get a code.
     */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'string', 'email:rfc']]);

        $account = Account::where('email', Str::lower($request->input('email')))->first();

        if ($account && $account->status !== Account::STATUS_SUSPENDED) {
            $code = VerificationCode::generateFor($account, VerificationCode::FOR_PASSWORD_RESET);
            Mail::to($account->email)->send(new PasswordResetCodeMail($account, $code));
            Event::record('account.password_reset_requested', $account, null, [], $request->ip());
        }

        return response()->json(['message' => 'If an account uses that email, we sent it a code to reset the password.'], 202);
    }

    /**
     * POST /v1/auth/password/reset — email, code, password, password_confirmation. Sets the
     * password, uses up the code, revokes every token and website session of the account, and
     * marks the email verified (the code proved the owner reads it). A wrong, used or expired
     * code (or an unknown or suspended account) is 422 on `code`.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $account = Account::where('email', Str::lower($request->input('email')))->first();

        if (! $account || $account->status === Account::STATUS_SUSPENDED || ! VerificationCode::consume($account, $request->input('code'), VerificationCode::FOR_PASSWORD_RESET)) {
            throw ValidationException::withMessages(['code' => 'That code is wrong or has expired. Check it, or send a new one.']);
        }

        DB::transaction(function () use ($account, $request) {
            $account->forceFill(['password' => $request->input('password')])->save();

            if (! $account->hasVerifiedEmail()) {
                $account->markEmailAsVerified();
            }

            // Personal tokens and website (flbs_) sessions alike: whoever knew the old password
            // is signed out everywhere.
            $account->tokens()->active()->get()->each->revoke();
        });

        Event::record('account.password_reset', $account, $account, [], $request->ip());

        return response()->json(['message' => 'Password changed. Sign in with the new one.']);
    }
}
