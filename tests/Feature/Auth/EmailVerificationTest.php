<?php

use App\Mail\VerificationCodeMail;
use App\Models\Account;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\Mail;

it('verifies an email with a valid code and activates the account', function () {
    $account = Account::factory()->unverified()->create();
    $code = VerificationCode::generateFor($account);

    $this->postJson('/v1/auth/verify', ['email' => $account->email, 'code' => $code->code])
        ->assertOk()
        ->assertJsonPath('verified', true);

    $account->refresh();
    expect($account->hasVerifiedEmail())->toBeTrue()
        ->and($account->status)->toBe(Account::STATUS_ACTIVE)
        ->and($code->fresh()->used_at)->not->toBeNull();
});

it('rejects wrong, reused and expired codes', function () {
    $account = Account::factory()->unverified()->create();
    $code = VerificationCode::generateFor($account);

    $this->postJson('/v1/auth/verify', ['email' => $account->email, 'code' => '000000'])
        ->assertStatus(422)->assertJsonPath('errors.0', 'Invalid or expired verification code.');

    $code->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->postJson('/v1/auth/verify', ['email' => $account->email, 'code' => $code->code])->assertStatus(422);

    expect($account->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('resends a code only for unverified accounts without revealing whether one exists', function () {
    Mail::fake();
    $pending = Account::factory()->unverified()->create();
    $verified = Account::factory()->create();

    $this->postJson('/v1/auth/verify/resend', ['email' => $pending->email])->assertOk();
    $this->postJson('/v1/auth/verify/resend', ['email' => $verified->email])->assertOk();
    $this->postJson('/v1/auth/verify/resend', ['email' => 'nobody@example.com'])->assertOk();

    Mail::assertSent(VerificationCodeMail::class, 1);
});

it('generates a fresh code and invalidates the previous one', function () {
    $account = Account::factory()->unverified()->create();
    $first = VerificationCode::generateFor($account);
    $second = VerificationCode::generateFor($account);

    expect(VerificationCode::where('subject_uuid', $account->uuid)->count())->toBe(1)
        ->and(VerificationCode::consume($account, $first->code))->toBeFalse()
        ->and(VerificationCode::consume($account, $second->code))->toBeTrue();
});
