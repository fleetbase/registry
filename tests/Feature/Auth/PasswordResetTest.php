<?php

use App\Mail\PasswordResetCodeMail;
use App\Mail\VerificationCodeMail;
use App\Models\Account;
use App\Models\Event;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/** The live password-reset code for the account. */
function resetCodeFor(Account $account): VerificationCode
{
    return VerificationCode::where('subject_uuid', $account->uuid)->where('for', VerificationCode::FOR_PASSWORD_RESET)->whereNull('used_at')->sole();
}

it('emails a reset code and answers the same way whether or not the account exists', function () {
    Mail::fake();
    $account = Account::factory()->create(['email' => 'pat@example.com']);
    $pending = Account::factory()->unverified()->create();
    $suspended = Account::factory()->suspended()->create();

    $answers = collect(['PAT@example.com', $pending->email, $suspended->email, 'nobody@example.com'])
        ->map(fn ($email) => $this->postJson('/v1/auth/password/forgot', ['email' => $email])->assertStatus(202)->json());

    expect($answers->unique()->count())->toBe(1)
        ->and($answers->first())->toBe(['message' => 'If an account uses that email, we sent it a code to reset the password.']);

    Mail::assertSent(PasswordResetCodeMail::class, 2);
    Mail::assertSent(PasswordResetCodeMail::class, fn ($mail) => $mail->hasTo('pat@example.com') && $mail->verificationCode->is(resetCodeFor($account)));
    Mail::assertNotSent(PasswordResetCodeMail::class, fn ($mail) => $mail->hasTo($suspended->email));
    Mail::assertNotSent(VerificationCodeMail::class);

    expect(resetCodeFor($account)->code)->toMatch('/^\d{6}$/')
        ->and(resetCodeFor($account)->expires_at->between(now()->addMinutes(29), now()->addMinutes(31)))->toBeTrue();

    $this->postJson('/v1/auth/password/forgot', ['email' => 'not-an-email'])->assertStatus(422)->assertJsonStructure(['errors', 'fields' => ['email']]);
});

it('resets the password, uses up the code and signs the account out everywhere', function () {
    Mail::fake();
    $account = Account::factory()->create(['email' => 'pat@example.com', 'password' => 'password']);
    $cookies = websiteSignIn($account);
    $personal = actingAsAccount($account);
    $this->withoutHeader('Authorization');

    $this->postJson('/v1/auth/password/forgot', ['email' => 'pat@example.com'])->assertStatus(202);
    $code = resetCodeFor($account)->code;

    $this->postJson('/v1/auth/password/reset', ['email' => 'Pat@example.com', 'code' => $code, 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123'])
        ->assertOk()
        ->assertJsonPath('message', 'Password changed. Sign in with the new one.');

    $account->refresh();
    expect(Hash::check('new-pass-123', $account->password))->toBeTrue()
        ->and($account->tokens()->active()->count())->toBe(0)
        ->and($personal->fresh()->revoked_at)->not->toBeNull()
        ->and(Event::where('kind', 'account.password_reset')->count())->toBe(1);

    // The website session is gone, the code cannot be used twice, and the new password works.
    websiteRequest('GET', '/v1/auth/session', $cookies)->assertJsonPath('authenticated', false);
    $this->postJson('/v1/auth/password/reset', ['email' => 'pat@example.com', 'code' => $code, 'password' => 'other-pass-123', 'password_confirmation' => 'other-pass-123'])
        ->assertStatus(422)
        ->assertJsonPath('fields.code.0', 'That code is wrong or has expired. Check it, or send a new one.');
    $this->postJson('/v1/auth/login', ['email' => 'pat@example.com', 'password' => 'new-pass-123'])->assertOk();
});

it('applies the registration password rules', function () {
    $account = Account::factory()->create();
    $code = VerificationCode::generateFor($account, VerificationCode::FOR_PASSWORD_RESET);

    $this->postJson('/v1/auth/password/reset', ['email' => $account->email, 'code' => $code->code, 'password' => 'short1', 'password_confirmation' => 'short1'])
        ->assertStatus(422)->assertJsonStructure(['fields' => ['password']]);
    $this->postJson('/v1/auth/password/reset', ['email' => $account->email, 'code' => $code->code, 'password' => 'lettersonly', 'password_confirmation' => 'lettersonly'])
        ->assertStatus(422)->assertJsonStructure(['fields' => ['password']]);
    $this->postJson('/v1/auth/password/reset', ['email' => $account->email, 'code' => $code->code, 'password' => 'new-pass-123', 'password_confirmation' => 'different-123'])
        ->assertStatus(422)->assertJsonStructure(['fields' => ['password']]);
    $this->postJson('/v1/auth/password/reset', ['email' => $account->email, 'code' => '12ab', 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123'])
        ->assertStatus(422)->assertJsonStructure(['fields' => ['code']]);

    // Validation failures do not spend the code.
    expect($code->fresh()->used_at)->toBeNull();
});

it('refuses wrong, expired and email-verification codes on the code field', function () {
    $account = Account::factory()->unverified()->create(['password' => 'password']);
    $verification = VerificationCode::generateFor($account);
    $reset = VerificationCode::generateFor($account, VerificationCode::FOR_PASSWORD_RESET);
    $body = fn (string $code) => ['email' => $account->email, 'code' => $code, 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123'];

    // An email-verification code is not a reset code, and a reset code does not verify.
    $this->postJson('/v1/auth/password/reset', $body($verification->code === $reset->code ? '000000' : $verification->code))
        ->assertStatus(422)->assertJsonPath('fields.code.0', 'That code is wrong or has expired. Check it, or send a new one.');
    if ($verification->code !== $reset->code) {
        $this->postJson('/v1/auth/verify', ['email' => $account->email, 'code' => $reset->code])->assertStatus(422);
    }

    $reset->forceFill(['expires_at' => now()->subMinute()])->save();
    $this->postJson('/v1/auth/password/reset', $body($reset->code))->assertStatus(422)->assertJsonStructure(['fields' => ['code']]);

    $this->postJson('/v1/auth/password/reset', $body('123456') + ['email' => 'nobody@example.com'])->assertStatus(422)->assertJsonStructure(['fields' => ['code']]);

    expect(Hash::check('password', $account->fresh()->password))->toBeTrue();
});

it('stops accepting a code after five wrong guesses', function () {
    $account = Account::factory()->create();
    $code = VerificationCode::generateFor($account, VerificationCode::FOR_PASSWORD_RESET);
    $wrong = $code->code === '000000' ? '111111' : '000000';

    foreach (range(1, VerificationCode::MAX_ATTEMPTS) as $attempt) {
        expect(VerificationCode::consume($account, $wrong, VerificationCode::FOR_PASSWORD_RESET))->toBeFalse();
    }

    expect($code->fresh()->attempts)->toBe(VerificationCode::MAX_ATTEMPTS);
    $this->postJson('/v1/auth/password/reset', ['email' => $account->email, 'code' => $code->code, 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123'])
        ->assertStatus(422);

    // A new code starts over.
    $fresh = VerificationCode::generateFor($account, VerificationCode::FOR_PASSWORD_RESET);
    expect(VerificationCode::consume($account, $fresh->code, VerificationCode::FOR_PASSWORD_RESET))->toBeTrue();
});

it('marks an unverified email verified, since the code proved the owner reads it', function () {
    $account = Account::factory()->unverified()->create();
    $code = VerificationCode::generateFor($account, VerificationCode::FOR_PASSWORD_RESET);

    $this->postJson('/v1/auth/password/reset', ['email' => $account->email, 'code' => $code->code, 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123'])
        ->assertOk();

    $account->refresh();
    expect($account->hasVerifiedEmail())->toBeTrue()
        ->and($account->status)->toBe(Account::STATUS_ACTIVE);
});

it('does not reset a suspended account', function () {
    $account = Account::factory()->suspended()->create(['password' => 'password']);
    $code = VerificationCode::generateFor($account, VerificationCode::FOR_PASSWORD_RESET);

    $this->postJson('/v1/auth/password/reset', ['email' => $account->email, 'code' => $code->code, 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123'])
        ->assertStatus(422)->assertJsonStructure(['fields' => ['code']]);

    expect(Hash::check('password', $account->fresh()->password))->toBeTrue();
});

it('throttles forgot and reset like the other auth routes', function (string $uri, array $body, int $status) {
    Mail::fake();

    foreach (range(1, 5) as $i) {
        $this->postJson($uri, $body)->assertStatus($status);
    }

    $this->postJson($uri, $body)->assertStatus(429);
})->with([
    'forgot' => ['/v1/auth/password/forgot', ['email' => 'pat@example.com'], 202],
    'reset' => ['/v1/auth/password/reset', ['email' => 'pat@example.com', 'code' => '123456', 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123'], 422],
]);
