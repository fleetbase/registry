<?php

namespace App\Actions;

use App\Mail\VerificationCodeMail;
use App\Models\Account;
use App\Models\Event;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\VerificationCode;
use App\Support\Utils;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Creates an account with its personal organization and sends the verification code.
 *
 * Input: name, email, password and optional username and organization_name (POST
 * /v1/auth/register). Sign in with GitHub also passes `email_verified` (the account starts
 * active and no code is sent), no password, `generate_username` false (no username unless one
 * is given; the organization slug then comes from `slug`), and `avatar_url`.
 */
class RegisterAccount
{
    public function __invoke(array $input, ?string $ip = null): Account
    {
        $verified = (bool) ($input['email_verified'] ?? false);

        $account = DB::transaction(function () use ($input, $ip, $verified) {
            $username = $input['username'] ?? (($input['generate_username'] ?? true) ? $this->uniqueUsername($input['email']) : null);

            $account = Account::create([
                'name' => $input['name'],
                'email' => Str::lower($input['email']),
                'username' => $username,
                'password' => $input['password'] ?? null,
                'avatar_url' => $input['avatar_url'] ?? null,
                'status' => $verified ? Account::STATUS_ACTIVE : Account::STATUS_PENDING_VERIFICATION,
                'email_verified_at' => $verified ? now() : null,
                'last_login_ip' => $ip,
            ]);

            $organization = Organization::create([
                'slug' => $this->uniqueSlug($username ?? $input['slug'] ?? Str::before($input['email'], '@')),
                'name' => $input['organization_name'] ?? $input['name'],
                'is_personal' => true,
            ]);

            Membership::create([
                'organization_uuid' => $organization->uuid,
                'account_uuid' => $account->uuid,
                'role' => Membership::ROLE_OWNER,
            ]);

            Event::record('account.registered', $account, $account, ['organization' => $organization->public_id], $ip);

            return $account;
        });

        if (! $verified) {
            $this->sendVerificationCode($account);
        }

        return $account;
    }

    public function sendVerificationCode(Account $account): VerificationCode
    {
        $code = VerificationCode::generateFor($account);

        Mail::to($account->email)->send(new VerificationCodeMail($account, $code));

        return $code;
    }

    protected function uniqueUsername(string $email): string
    {
        $base = Utils::slugify(Str::before($email, '@'));
        $candidate = $base;

        while (Account::withTrashed()->where('username', $candidate)->exists() || Organization::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.Str::lower(Str::random(4));
        }

        return $candidate;
    }

    protected function uniqueSlug(string $base): string
    {
        $candidate = Utils::slugify($base);

        while (Organization::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.Str::lower(Str::random(4));
        }

        return $candidate;
    }
}
