<?php

use App\Models\Account;
use App\Models\Instance;
use App\Models\Token;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Issue a token for the account and authenticate subsequent requests with it.
 */
function actingAsAccount(Account $account, array $scopes = [Token::SCOPE_ALL]): Token
{
    $token = Token::issue($account, 'test', $scopes);

    test()->withHeader('Authorization', 'Bearer '.$token->plainTextToken);

    return $token;
}

/**
 * Issue an instance token and authenticate subsequent requests with it.
 */
function actingAsInstance(Instance $instance): string
{
    $plain = $instance->issueToken();

    test()->withHeader('Authorization', 'Bearer '.$plain);

    return $plain;
}
