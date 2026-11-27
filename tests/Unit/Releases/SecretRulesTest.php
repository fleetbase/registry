<?php

use App\Releases\Secrets\SecretRules;

it('finds provider tokens and reports the line', function (string $secret, string $rule) {
    $findings = (new SecretRules)->scan('src/config.js', "const a = 1;\nconst key = '{$secret}';\n");

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['rule'])->toBe($rule)
        ->and($findings[0]['line'])->toBe(2)
        ->and($findings[0]['match'])->not->toContain(substr($secret, 8));
})->with([
    'aws' => ['AKIA'.'QYLPMN5HHHFPZAM2', 'aws-access-token'],
    'github' => ['ghp_'.str_repeat('A1b2', 9), 'github-pat'],
    'stripe' => ['sk_live_'.str_repeat('a1B2', 6), 'stripe-access-token'],
    'slack' => ['xoxb-'.'1234567890-1234567890123-'.str_repeat('a', 24), 'slack-token'],
    'npm' => ['npm_'.str_repeat('a1B2', 9), 'npm-access-token'],
    'google' => ['AIza'.str_repeat('x', 35), 'gcp-api-key'],
    'fleetbase' => ['flbp_'.str_repeat('a', 40), 'fleetbase-registry-token'],
    'anthropic' => ['sk-ant-api03-'.str_repeat('a', 90), 'anthropic-api-key'],
]);

it('finds private keys and AWS secrets in assignments', function () {
    $rules = new SecretRules;
    $pem = "-----BEGIN RSA PRIVATE KEY-----\n".str_repeat('MIIEowIBAAKCAQEA', 6)."\n-----END RSA PRIVATE KEY-----";

    expect($rules->scan('key.pem', $pem)[0]['rule'])->toBe('private-key')
        ->and($rules->scan('config.php', "'aws_secret_access_key' => '".str_repeat('aB3/', 10)."',")[0]['rule'])->toBe('aws-secret-access-key')
        // Code that only mentions the header is not a key.
        ->and($rules->scan('parser.js', "if (pem.startsWith('-----BEGIN RSA PRIVATE KEY-----')) {}"))->toBe([]);
});

it('honours gitleaks:allow and ignores binary files', function () {
    $rules = new SecretRules;
    $token = 'ghp_'.str_repeat('A1b2', 9);

    expect($rules->scan('a.js', "const t = '{$token}'; // gitleaks:allow"))->toBe([])
        ->and($rules->scan('a.png', "\x89PNG\0\0{$token}"))->toBe([]);
});

it('flags files that hold credentials by convention', function () {
    $rules = new SecretRules;

    expect($rules->scan('.env', 'APP_KEY=x')[0]['description'])->toBe('Environment file (.env)')
        ->and($rules->scan('config/.env.production', '')[0]['rule'])->toBe('sensitive-file')
        ->and($rules->scan('.env.example', 'APP_KEY='))->toBe([])
        ->and($rules->scan('deploy/id_rsa', null)[0]['description'])->toBe('SSH private key (id_rsa)')
        ->and($rules->scan('certs/server.p12', null))->toHaveCount(1)
        ->and($rules->scan('.npmrc', '//registry.npmjs.org/:_authToken=abc123'))->toHaveCount(1)
        ->and($rules->scan('.npmrc', '//registry.npmjs.org/:_authToken=${NPM_TOKEN}'))->toBe([])
        ->and($rules->scan('auth.json', '{"bearer": {"registry.fleetbase.io": "flbp_x"}}'))->toHaveCount(1)
        ->and($rules->scan('auth.json', '{"bearer": {}}'))->toBe([]);
});

it('redacts matches', function () {
    expect(SecretRules::redact('AKIAQYLPMN5HHHFPZAM2'))->toBe('AKIAQ…[20 chars]')
        ->and(SecretRules::redact('abc'))->toBe('…[3 chars]');
});
