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
 */
class RegisterAccount
{
    public function __invoke(array $input, ?string $ip = null): Account
    {
        $account = DB::transaction(function () use ($input, $ip) {
            $username = $input['username'] ?? $this->uniqueUsername($input['email']);

            $account = Account::create([
                'name' => $input['name'],
                'email' => Str::lower($input['email']),
                'username' => $username,
                'password' => $input['password'],
                'status' => Account::STATUS_PENDING_VERIFICATION,
                'last_login_ip' => $ip,
            ]);

            $organization = Organization::create([
                'slug' => $this->uniqueSlug($username),
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

        $this->sendVerificationCode($account);

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
