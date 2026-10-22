<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A package name in a reserved namespace (`@fleetbase/*`, `fleetbase/*`) for an extension
 * not owned by the staff organization. Renders as a 422 on the `name` field.
 */
class ReservedPackageNamespaceException extends ValidationException
{
    public static function for(string $name, string $namespace): static
    {
        return static::withMessages([
            'name' => "The '{$namespace}' package namespace is reserved for Fleetbase; {$name} cannot be registered by this organization.",
        ]);
    }
}
