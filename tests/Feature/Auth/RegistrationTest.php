<?php

use App\Mail\VerificationCodeMail;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

it('registers an account, creates a personal organization and emails a code', function () {
    Mail::fake();

    $response = $this->postJson('/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'Ada@Example.com',
        'username' => 'ada',
        'password' => 'correct-horse-1',
    ]);

    $response->assertCreated()
        ->assertJsonPath('account.email', 'ada@example.com')
        ->assertJsonPath('account.status', Account::STATUS_PENDING_VERIFICATION)
        ->assertJsonMissingPath('account.password');

    expect($response->json('account.id'))->toStartWith('acct_');

    $account = Account::where('email', 'ada@example.com')->firstOrFail();
    expect($account->hasVerifiedEmail())->toBeFalse()
        ->and(Hash::check('correct-horse-1', $account->password))->toBeTrue()
        ->and($account->organizations)->toHaveCount(1);

    $organization = $account->organizations->first();
    expect($organization->slug)->toBe('ada')
        ->and($organization->is_personal)->toBeTrue()
        ->and($organization->pivot->role)->toBe(Membership::ROLE_OWNER)
        ->and($organization->public_id)->toStartWith('org_');

    expect(VerificationCode::where('subject_uuid', $account->uuid)->count())->toBe(1);
    Mail::assertSent(VerificationCodeMail::class, fn (VerificationCodeMail $mail) => $mail->hasTo('ada@example.com'));
});

it('derives a username from the email when none is given', function () {
    Mail::fake();

    $this->postJson('/v1/auth/register', [
        'name' => 'Grace',
        'email' => 'grace.hopper@example.com',
        'password' => 'correct-horse-1',
    ])->assertCreated()->assertJsonPath('account.username', 'gracehopper');
});

it('rejects duplicate emails and weak passwords with the error envelope', function () {
    Account::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/v1/auth/register', [
        'name' => 'Dup',
        'email' => 'taken@example.com',
        'password' => 'short',
    ])->assertStatus(422)
        ->assertJsonStructure(['errors', 'fields' => ['email', 'password']]);
});

it('rejects usernames that collide with an existing organization slug', function () {
    Organization::factory()->create(['slug' => 'fleetbase']);

    $this->postJson('/v1/auth/register', [
        'name' => 'Imposter',
        'email' => 'imposter@example.com',
        'username' => 'fleetbase',
        'password' => 'correct-horse-1',
    ])->assertStatus(422)->assertJsonPath('fields.username.0', fn ($m) => is_string($m));
});
