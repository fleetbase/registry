<?php

use App\Models\Instance;
use App\Models\Organization;
use App\Support\CloudPlatform;
use App\Support\PlatformSignature;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

const CLOUD_PLATFORM_SECRET = 'test-platform-secret-0123456789abcdef0123456789';

/**
 * The cloud platform instance (kind `cloud`, `meta.platform`) in the cloud organization.
 */
function cloudPlatformInstance(array $attributes = []): Instance
{
    return Instance::factory()->create(array_merge([
        'organization_uuid' => CloudPlatform::organization()->uuid,
        'name' => 'Fleetbase Cloud',
        'kind' => Instance::KIND_CLOUD,
        'host' => 'https://api.fleetbase.test',
        'meta' => ['platform' => true],
    ], $attributes));
}

/**
 * The organization of a cloud company, as the importer creates it.
 */
function cloudCompany(?string $name = null): Organization
{
    $organization = Organization::factory()->create(['name' => $name ?? 'Company '.Str::random(6)]);
    $organization->forceFill(['source_uuid' => (string) Str::uuid()])->save();

    return $organization;
}

/**
 * Send instance requests as the cloud instance acting for a company.
 */
function actingForCompany(Organization|string $company, ?string $name = null): void
{
    test()->withHeader(CloudPlatform::TENANT_HEADER, $company instanceof Organization ? $company->source_uuid : $company);
    if ($name !== null) {
        test()->withHeader(CloudPlatform::TENANT_NAME_HEADER, rawurlencode($name));
    }
}

function cloudRegistrationBody(array $overrides = []): array
{
    return array_merge(['name' => 'Fleetbase Cloud', 'host' => 'https://api.fleetbase.test', 'console_version' => '0.8.0', 'api_version' => '1.6.80'], $overrides);
}

/**
 * `POST /v1/instances/register/cloud` signed with the platform secret.
 */
function registerCloudInstance(array $body = [], ?string $secret = CLOUD_PLATFORM_SECRET, array $headers = []): TestResponse
{
    $body = cloudRegistrationBody($body);
    $signed = $secret !== null ? PlatformSignature::headers($secret, json_encode($body)) : [];

    return test()->withHeaders(array_merge($signed, $headers))->postJson('/v1/instances/register/cloud', $body);
}
