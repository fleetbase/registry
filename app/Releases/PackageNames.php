<?php

namespace App\Releases;

use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\Organization;

/**
 * Whether an organization may claim a package name for a new listing. The one place
 * `POST /v1/publisher/extensions` and `GET /v1/publisher/packages/availability` take their
 * rules from, checked in the same order: the name's format, the listing that already holds
 * it, then the namespace rules (`PackageNamespaces`: reserved `@fleetbase`/`fleetbase`,
 * scopes of other publishers, the organization's own and granted namespaces).
 */
class PackageNames
{
    public const MAX_LENGTH = 214;

    /** npm's rules for a (lowercase) package name, optionally scoped. */
    public const NPM = '/^(@[a-z0-9-~][a-z0-9-._~]*\/)?[a-z0-9-~][a-z0-9-._~]*$/';

    /** Composer's `vendor/package` rule. */
    public const COMPOSER = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/';

    public function __construct(protected PackageNamespaces $namespaces) {}

    /** Names are compared lowercase and trimmed, as npm and Composer require them. */
    public static function normalize(mixed $name): mixed
    {
        return is_string($name) ? strtolower(trim($name)) : $name;
    }

    /** Why the name is not a valid package name of its kind, or null. */
    public static function formatError(string $kind, string $name): ?string
    {
        if (strlen($name) > self::MAX_LENGTH) {
            return 'Package names are at most '.self::MAX_LENGTH.' characters.';
        }

        if ($kind === ExtensionPackage::KIND_NPM && ! preg_match(self::NPM, $name)) {
            return 'Use a valid npm package name: lowercase letters, digits, - . _ ~, optionally scoped (@scope/name).';
        }

        if ($kind === ExtensionPackage::KIND_COMPOSER && ! preg_match(self::COMPOSER, $name)) {
            return 'Use a valid Composer package name: vendor/package in lowercase letters, digits, - . _.';
        }

        return null;
    }

    /** The listing (deleted ones too) that holds the name, or null. */
    public function owner(string $kind, string $name): ?Extension
    {
        $uuid = ExtensionPackage::query()->where('kind', $kind)->where('name', self::normalize($name))->value('extension_uuid');

        return $uuid === null ? null : Extension::withTrashed()->with('organization')->find($uuid);
    }

    /**
     * Why the organization cannot claim the name for a new listing; null when it can.
     *
     * @param  list<string>  $publishable  uuids of the organizations the caller publishes for
     */
    public function problem(Organization $organization, string $kind, string $name, array $publishable): ?string
    {
        $name = self::normalize($name);

        if ($error = self::formatError($kind, $name)) {
            return $error;
        }

        if ($owner = $this->owner($kind, $name)) {
            return $this->takenBy($owner, $organization, $publishable);
        }

        return $this->namespaces->errors($organization, $kind, $name)[0] ?? null;
    }

    /**
     * Why a name the listing already holds cannot go to a new listing of the organization.
     *
     * @param  list<string>  $publishable
     */
    protected function takenBy(Extension $owner, Organization $organization, array $publishable): string
    {
        if ($owner->trashed()) {
            return 'This name belongs to a deleted extension; contact Fleetbase staff.';
        }

        if (! in_array($owner->organization_uuid, $publishable, true)) {
            return 'This name is registered to another publisher\'s extension.';
        }

        if ($owner->organization_uuid !== $organization->uuid) {
            return "This name belongs to {$owner->slug}, which another of your organizations owns.";
        }

        return "Your extension {$owner->name} already uses this name.";
    }
}
