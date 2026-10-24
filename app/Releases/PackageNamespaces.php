<?php

namespace App\Releases;

use App\Models\ExtensionPackage;
use App\Models\Organization;

/**
 * Which package names an organization may claim for a new listing (plan, security
 * requirements): `@fleetbase/*` and `fleetbase/*` only for staff organizations; a scope or
 * vendor already used by another organization's packages never; otherwise a new scope must
 * be the organization's slug, one it already publishes under, or one staff granted in
 * `organizations.meta.namespaces`. Unscoped npm names are allowed.
 */
class PackageNamespaces
{
    /**
     * @return list<string> why the name cannot be claimed; empty when it can
     */
    public function errors(Organization $organization, string $kind, string $name): array
    {
        $name = strtolower($name);
        $namespace = ExtensionPackage::namespaceOf($kind, $name);
        if ($namespace === null) {
            return [];
        }

        $display = $kind === ExtensionPackage::KIND_NPM ? '@'.$namespace : $namespace;

        if (ExtensionPackage::isReservedNamespace($namespace)) {
            return $organization->isStaff() ? [] : ["The '{$display}' package namespace is reserved for Fleetbase."];
        }

        $prefix = $display.'/';
        $owners = ExtensionPackage::query()
            ->join('extensions', 'extensions.uuid', '=', 'extension_packages.extension_uuid')
            ->where('extension_packages.kind', $kind)
            ->whereRaw('substr(extension_packages.name, 1, ?) = ?', [strlen($prefix), $prefix])
            ->distinct()
            ->pluck('extensions.organization_uuid');

        if ($owners->contains(fn ($uuid) => $uuid !== $organization->uuid)) {
            return ["The '{$display}' namespace belongs to another publisher."];
        }

        $granted = array_map('strtolower', (array) data_get($organization->meta, 'namespaces', []));
        if ($namespace === strtolower($organization->slug) || $owners->isNotEmpty() || $organization->isStaff() || in_array($namespace, $granted, true)) {
            return [];
        }

        $expected = $kind === ExtensionPackage::KIND_NPM ? '@'.$organization->slug.'/…' : $organization->slug.'/…';

        return ["New package namespaces must match your organization slug ({$expected}); ask Fleetbase staff to grant '{$display}'."];
    }
}
