<?php

use App\Models\Account;
use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Token;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

/** Every package route of `privateExtension()`, by protocol. */
const PRIVATE_ROUTES = [
    'npm packument' => '/npm/@client%2fsecret-engine',
    'npm tarball' => '/npm/@client/secret-engine/-/secret-engine-1.0.0.tgz',
    'npm dist' => '/npm/@client/secret-engine/-/dist/1.0.0',
    'composer p2' => '/composer/p2/client/secret-api.json',
    'composer p2 ~dev' => '/composer/p2/client/secret-api~dev.json',
    'composer dist' => '/composer/dist/client/secret-api/1.0.0.tar',
];

/**
 * A published private extension (free unless `$paid`) with an npm and a Composer package
 * released at 1.0.0, owned by a fresh organization.
 */
function privateExtension(bool $paid = false, array $attributes = []): Extension
{
    $npm = makePackage(ExtensionPackage::KIND_NPM, '@client/secret-engine', $paid, array_merge([
        'visibility' => Extension::VISIBILITY_PRIVATE,
        'slug' => 'client-secret',
        'name' => 'Client Secret Workflows',
    ], $attributes));
    $extension = $npm->extension;
    $composer = $extension->packages()->create(['kind' => ExtensionPackage::KIND_COMPOSER, 'name' => 'client/secret-api']);
    attachDist(makeRelease($npm, '1.0.0', manifest: ['description' => 'secret']), $npm->name);
    makeRelease($composer, '1.0.0');

    return $extension->fresh(['price', 'organization']);
}

/**
 * An organization with an owner account and one registered instance.
 *
 * @return array{0: Organization, 1: Instance, 2: Account}
 */
function clientOrganization(string $name = 'Client Co'): array
{
    $organization = Organization::factory()->create(['name' => $name]);
    $account = Account::factory()->create();
    $account->organizations()->attach($organization->uuid, ['role' => Membership::ROLE_OWNER]);
    $instance = Instance::factory()->for($organization)->create();

    return [$organization, $instance, $account];
}

function accessGrant(Extension $extension, Organization $organization, array $attributes = []): Licence
{
    return Licence::create(array_merge([
        'extension_uuid' => $extension->uuid,
        'organization_uuid' => $organization->uuid,
        'kind' => Licence::KIND_GRANT,
        'max_activations' => null,
        'status' => Licence::STATUS_ACTIVE,
    ], $attributes));
}

/**
 * Headers for a request authenticated as the instance (a fresh token each call).
 */
function instanceHeaders(Instance $instance): array
{
    return ['Authorization' => 'Bearer '.$instance->issueToken()];
}

function personalHeaders(Account $account, array $scopes = [Token::SCOPE_INSTALL], ?string $organizationUuid = null): array
{
    return ['Authorization' => 'Bearer '.Token::issue($account, 'test', $scopes, null, $organizationUuid)->plainTextToken];
}

// ---------------------------------------------------------------------------
// GitHub Actions OIDC
// ---------------------------------------------------------------------------

/**
 * An RSA key pair for signing test OIDC tokens: [private PEM, JWK].
 *
 * @return array{0: string, 1: array<string, string>}
 */
function oidcKeyPair(string $kid = 'test-key'): array
{
    static $keys = [];

    if (! isset($keys[$kid])) {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $pem);
        $details = openssl_pkey_get_details($resource)['rsa'];
        $keys[$kid] = [$pem, [
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => $kid,
            'n' => rtrim(strtr(base64_encode($details['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['e']), '+/', '-_'), '='),
        ]];
    }

    return $keys[$kid];
}

/**
 * Serve the test keys as GitHub's JWKS; no kids (null) makes the endpoint fail. Later calls in a
 * test change what the endpoint serves (Http::fake stubs stack, so they all read one value).
 */
function fakeGithubJwks(?string ...$kids): void
{
    $kids = $kids === [] ? ['test-key'] : $kids;
    $GLOBALS['fake_github_jwks'] = $kids === [null] ? null : $kids;

    Http::fake([
        'token.actions.githubusercontent.com/*' => fn () => $GLOBALS['fake_github_jwks'] === null
            ? Http::response('unavailable', 502)
            : Http::response(['keys' => array_map(fn ($kid) => oidcKeyPair($kid)[1], $GLOBALS['fake_github_jwks'])]),
    ]);
}

/**
 * The claims a tag push of `acme/secret-ext`'s release.yml carries, with overrides.
 */
function githubClaims(array $overrides = []): array
{
    $ref = $overrides['ref'] ?? 'refs/tags/v1.2.0';
    $repository = $overrides['repository'] ?? 'acme/secret-ext';

    return array_merge([
        'iss' => 'https://token.actions.githubusercontent.com',
        'aud' => 'localhost',
        'sub' => "repo:{$repository}:ref:{$ref}",
        'repository' => $repository,
        'repository_owner' => strstr($repository, '/', true),
        'repository_id' => '123456',
        'repository_owner_id' => '7890',
        'ref' => $ref,
        'ref_type' => 'tag',
        'sha' => str_repeat('a', 40),
        'workflow' => 'Release',
        'job_workflow_ref' => "{$repository}/.github/workflows/release.yml@{$ref}",
        'event_name' => 'push',
        'run_id' => '42',
        'actor' => 'octocat',
        'iat' => time() - 10,
        'nbf' => time() - 10,
        'exp' => time() + 300,
    ], $overrides);
}

function githubToken(array $claims = [], string $kid = 'test-key'): string
{
    return JWT::encode(githubClaims($claims), oidcKeyPair($kid)[0], 'RS256', $kid);
}
