<?php

namespace App\Http\Controllers\Auth;

use App\Actions\RegisterAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Resources\AccountResource;
use App\Http\Resources\TokenResource;
use App\Models\Account;
use App\Models\Event;
use App\Models\Token;
use App\Models\VerificationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use ChecksCredentials;

    /**
     * POST /v1/auth/register
     */
    public function register(RegisterRequest $request, RegisterAccount $registerAccount): JsonResponse
    {
        $account = $registerAccount($request->validated(), $request->ip());

        return response()->json([
            'account' => (new AccountResource($account))->resolve($request),
            'message' => 'Account created. Check your email for a verification code.',
        ], 201);
    }

    /**
     * POST /v1/auth/verify
     */
    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        $account = Account::where('email', Str::lower($request->input('email')))->first();

        if (! $account) {
            return response()->error('No account found for that email.', 404);
        }

        if ($account->hasVerifiedEmail()) {
            return response()->json(['verified' => true, 'message' => 'Email already verified.']);
        }

        if (! VerificationCode::consume($account, $request->input('code'))) {
            return response()->error('Invalid or expired verification code.', 422);
        }

        $account->markEmailAsVerified();
        Event::record('account.verified', $account, $account, [], $request->ip());

        return response()->json(['verified' => true, 'message' => 'Email verified. You can now log in.']);
    }

    /**
     * POST /v1/auth/verify/resend
     */
    public function resendVerification(Request $request, RegisterAccount $registerAccount): JsonResponse
    {
        $request->validate(['email' => ['required', 'email:rfc']]);

        $account = Account::where('email', Str::lower($request->input('email')))->first();

        // Always answer the same way so this cannot be used to enumerate accounts.
        if ($account && ! $account->hasVerifiedEmail()) {
            $registerAccount->sendVerificationCode($account);
        }

        return response()->json(['message' => 'If that account needs verification, a new code has been sent.']);
    }

    /**
     * POST /v1/auth/login
     *
     * Exchanges email and password for a personal token, shown once.
     * The password check uses Hash::check, the same as core-api's Auth::checkPassword.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $account = $this->attempt($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }

        $token = Token::issue(
            $account,
            $request->input('token_name') ?: 'login '.now()->toDateString(),
            $request->input('scopes') ?: [Token::SCOPE_ALL],
            now()->addDays((int) ($request->input('expires_in_days') ?: 30)),
        );

        $account->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        Event::record('account.login', $account, $account, ['token' => $token->public_id], $request->ip());

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->expires_at,
            'token_details' => (new TokenResource($token))->resolve($request),
            'account' => (new AccountResource($account->load('organizations')))->resolve($request),
        ]);
    }

    /**
     * POST /v1/auth/logout — revokes the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var Account $account */
        $account = $request->user();
        $token = $account->currentToken();

        $token?->revoke();
        Event::record('account.logout', $account, $account, ['token' => $token?->public_id], $request->ip());

        return response()->json(['message' => 'Logged out.']);
    }
}
