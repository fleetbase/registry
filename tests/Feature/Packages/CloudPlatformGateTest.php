<?php

use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\LicenceActivation;

/**
 * A paid extension released at 1.0.0 as `@cloud/pro-engine` and `cloud/pro-api`.
 */
function cloudPaidExtension(): Extension
{
    $npm = makePackage(ExtensionPackage::KIND_NPM, '@cloud/pro-engine', paid: true);
    $composer = $npm->extension->packages()->create(['kind' => ExtensionPackage::KIND_COMPOSER, 'name' => 'cloud/pro-api']);
    makeRelease($npm, '1.0.0');
    makeRelease($composer, '1.0.0');

    return $npm->extension;
}

it('lets the cloud instance download a paid package while a company holds a seat on it', function () {
    $extension = cloudPaidExtension();
    $instance = cloudPlatformInstance();
    actingAsInstance($instance);

    $this->get('/npm/@cloud%2fpro-engine')->assertForbidden();
    $this->get('/composer/dist/cloud/pro-api/1.0.0.tar')->assertForbidden();

    $licence = Licence::create(['extension_uuid' => $extension->uuid, 'organization_uuid' => cloudCompany()->uuid, 'kind' => Licence::KIND_PAID, 'max_activations' => 5, 'status' => Licence::STATUS_ACTIVE]);
    $activation = LicenceActivation::create(['licence_uuid' => $licence->uuid, 'instance_uuid' => $instance->uuid, 'activated_at' => now()]);

    $this->get('/npm/@cloud%2fpro-engine')->assertOk();
    $this->get('/composer/dist/cloud/pro-api/1.0.0.tar')->assertOk();

    // A released seat or a revoked licence closes it again.
    $activation->forceFill(['deactivated_at' => now()])->save();
    $this->get('/npm/@cloud%2fpro-engine')->assertForbidden();

    $activation->forceFill(['deactivated_at' => null])->save();
    $licence->forceFill(['status' => Licence::STATUS_REVOKED])->save();
    $this->get('/npm/@cloud%2fpro-engine')->assertForbidden();
});

it('keeps the seat rule to the cloud instance', function () {
    $extension = cloudPaidExtension();
    $instance = Instance::factory()->create(['kind' => Instance::KIND_CLOUD]);
    $licence = Licence::create(['extension_uuid' => $extension->uuid, 'organization_uuid' => cloudCompany()->uuid, 'kind' => Licence::KIND_PAID, 'max_activations' => 5, 'status' => Licence::STATUS_ACTIVE]);
    LicenceActivation::create(['licence_uuid' => $licence->uuid, 'instance_uuid' => $instance->uuid, 'activated_at' => now()]);

    actingAsInstance($instance);
    $this->get('/npm/@cloud%2fpro-engine')->assertForbidden();
});
