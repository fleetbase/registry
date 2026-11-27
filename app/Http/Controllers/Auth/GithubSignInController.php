<?php

namespace App\Http\Controllers\Auth;

use App\Actions\RegisterAccount;
use App\Auth\SessionGuard;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Event;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Sign in with GitHub (website only). Signed out, the callback signs in the account linked to
 * the GitHub account, else the account whose email equals the GitHub account's verified
 * primary email (and links it), else creates one. Signed in, it connects GitHub to the current
 * account instead. Problems go back to sign in (or the profile) as a flash message.
 */
class GithubSignInController extends Controller
{
    public const SCOPES = ['read:user', 'user:email'];

    public static function configured(): bool
    {
        return filled(config('services.github.client_id')) && filled(config('services.github.client_secret'));
    }

    /**
     * GET /auth/github[?intended=/path] — off to GitHub. `intended` (a path on this site) is
     * where to land after signing in; otherwise the one `auth:web` remembered, else /studio.
     */
    public function redirect(Request $request): RedirectResponse|SymfonyRedirect
    {
        if (! static::configured()) {
            return $this->fail($request, 'Sign in with GitHub isn’t set up on this registry.');
        }

        $intended = $request->query('intended');
        if (is_string($intended) && str_starts_with($intended, '/') && ! str_starts_with($intended, '//') && ! str_contains($intended, '\\')) {
            $request->session()->put('url.intended', url($intended));
        }

        return Socialite::driver('github')->scopes(self::SCOPES)->redirect();
    }

    /**
     * GET /auth/github/callback
     */
    public function callback(Request $request, RegisterAccount $registerAccount): RedirectResponse
    {
        if (! static::configured()) {
            return $this->fail($request, 'Sign in with GitHub isn’t set up on this registry.');
        }

        if ($request->filled('error')) {
            return $this->fail($request, $request->query('error') === 'access_denied'
                ? 'GitHub sign-in was cancelled.'
                : 'GitHub didn’t sign you in. Try again.');
        }

        try {
            $github = Socialite::driver('github')->user();
        } catch (InvalidStateException) {
            return $this->fail($request, 'That GitHub sign-in expired or started in another tab. Try again.');
        } catch (Throwable $e) {
            report($e);

            return $this->fail($request, 'GitHub couldn’t be reached. Try again.');
        }

        /** @var Account|null $current */
        $current = Auth::guard('web')->user();

        return $current
            ? $this->connect($request, $current, $github)
            : $this->signIn($request, $github, $registerAccount);
    }

    /**
     * Signed in already: link the GitHub account to this one, unless another account has it.
     */
    protected function connect(Request $request, Account $account, SocialiteUser $github): RedirectResponse
    {
        $githubId = (string) $github->getId();
        $owner = Account::withTrashed()->where('github_id', $githubId)->first();

        if ($owner && ! $owner->is($account)) {
            Event::record('account.github_conflict', $account, $account, ['github_login' => $github->getNickname()], $request->ip());

            return $this->fail($request, 'That GitHub account (@'.$github->getNickname().') is connected to a different registry account. Disconnect it there first.');
        }

        $this->link($request, $account, $github);

        return redirect('/account/profile')->with('flash', ['type' => 'ok', 'message' => 'GitHub connected as @'.$github->getNickname().'.']);
    }

    protected function signIn(Request $request, SocialiteUser $github, RegisterAccount $registerAccount): RedirectResponse
    {
        $githubId = (string) $github->getId();
        // Socialite's GitHub provider reads the primary email from /user/emails and keeps it
        // only when GitHub has verified it, so `getEmail()` is a verified address or null.
        $email = $github->getEmail() ? Str::lower($github->getEmail()) : null;

        $account = Account::withTrashed()->where('github_id', $githubId)->first();

        if (! $account && $email) {
            $account = Account::withTrashed()->where('email', $email)->first();

            if ($account?->github_id) {
                return $this->fail($request, 'The registry account for '.$email.' is connected to a different GitHub account. Sign in with your email and password.');
            }
        }

        if ($account?->trashed()) {
            return $this->fail($request, 'That registry account has been deleted.');
        }

        if (! $account) {
            if (! $email) {
                return $this->fail($request, 'Your GitHub account has no verified primary email. Verify one on GitHub, or create an account with your email.');
            }

            $account = $registerAccount([
                'name' => $github->getName() ?: $github->getNickname(),
                'email' => $email,
                'username' => $this->freeUsername($github->getNickname()),
                'generate_username' => false,
                'slug' => $github->getNickname(),
                'email_verified' => true,
                'avatar_url' => $github->getAvatar(),
            ], $request->ip());
        }

        if ($account->status === Account::STATUS_SUSPENDED) {
            Event::record('account.login_failed', $account, null, ['via' => 'github', 'reason' => 'suspended'], $request->ip());

            return $this->fail($request, 'This account is suspended.');
        }

        $this->link($request, $account, $github);

        // Matched by a GitHub-verified email: that proves the owner reads it.
        if (! $account->hasVerifiedEmail()) {
            $account->markEmailAsVerified();
        }

        if (! $account->isActive()) {
            return $this->fail($request, 'This account is '.$account->status.'.');
        }

        SessionGuard::signIn($request, $account, 'github');

        return redirect()->intended('/studio');
    }

    protected function link(Request $request, Account $account, SocialiteUser $github): void
    {
        $linking = $account->github_id === null;

        $account->forceFill([
            'github_id' => (string) $github->getId(),
            'github_login' => $github->getNickname(),
            'avatar_url' => $account->avatar_url ?: $github->getAvatar(),
        ])->save();

        if ($linking) {
            Event::record('account.github_connected', $account, $account, ['github_login' => $github->getNickname()], $request->ip());
        }
    }

    /**
     * The GitHub login as a registry username when it fits the username rules and nobody
     * (no account, no organization slug) has it; otherwise none.
     */
    protected function freeUsername(?string $login): ?string
    {
        $username = Str::lower((string) $login);

        if (strlen($username) < 3 || strlen($username) > 40 || ! preg_match('/^[a-z0-9][a-z0-9-]*$/', $username)) {
            return null;
        }

        $taken = Account::withTrashed()->where('username', $username)->exists()
            || Organization::withTrashed()->where('slug', $username)->exists();

        return $taken ? null : $username;
    }

    /**
     * Back to sign in with the message, or to the profile when someone is signed in.
     */
    protected function fail(Request $request, string $message): RedirectResponse
    {
        $to = Auth::guard('web')->check() ? '/account/profile' : '/sign-in';

        return redirect($to)->with('flash', ['type' => 'error', 'message' => $message]);
    }
}
