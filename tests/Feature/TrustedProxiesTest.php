<?php

// Rate limits count per client address. Behind a load balancer that address is in
// X-Forwarded-For; these pin down when it is believed.

function remainingFor(string $remoteAddress, ?string $forwardedFor = null): int
{
    $response = test()
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->getJson('/v1/catalog/categories', array_filter(['X-Forwarded-For' => $forwardedFor]));

    return (int) $response->assertOk()->headers->get('X-RateLimit-Remaining');
}

it('counts clients behind a trusted proxy separately', function () {
    $first = remainingFor('10.0.1.20', '203.0.113.7');

    expect(remainingFor('10.0.2.31', '198.51.100.4'))->toBe($first)
        ->and(remainingFor('10.0.2.31', '203.0.113.7'))->toBe($first - 1);
});

it('takes the address the proxy added, not one the client sent', function () {
    $first = remainingFor('10.0.1.20', '203.0.113.7');

    // A load balancer appends the address it saw; whatever precedes it came from the client.
    expect(remainingFor('10.0.1.20', '192.0.2.99, 203.0.113.7'))->toBe($first - 1);
});

it('ignores X-Forwarded-For from an address that is not a trusted proxy', function () {
    $first = remainingFor('203.0.113.50', '192.0.2.1');

    expect(remainingFor('203.0.113.50', '192.0.2.2'))->toBe($first - 1);
});

it('trusts only the proxies in TRUSTED_PROXIES', function () {
    config(['trustedproxy.proxies' => '172.31.0.0/16']);

    $first = remainingFor('10.0.1.20', '203.0.113.7');

    expect(remainingFor('10.0.1.20', '198.51.100.4'))->toBe($first - 1)
        ->and(remainingFor('172.31.4.4', '198.51.100.4'))->toBe($first);
});

it('does not take the host from X-Forwarded-Host', function () {
    $response = test()
        ->withServerVariables(['REMOTE_ADDR' => '10.0.1.20'])
        ->getJson('/v1', ['X-Forwarded-Host' => 'registry.example.test', 'X-Forwarded-Proto' => 'https']);

    expect($response->assertOk()->json('catalog'))->not->toContain('registry.example.test');
});
