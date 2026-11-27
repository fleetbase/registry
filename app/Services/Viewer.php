<?php

namespace App\Services;

use App\Models\Instance;

/**
 * The caller of a catalog or package request as `ExtensionAccess` sees it: the organizations
 * behind its credential and, for an instance token, the instance.
 */
final class Viewer
{
    /** Memoised by `ExtensionAccess::privateExtensionUuids()`. */
    public ?array $privateUuids = null;

    /**
     * @param  list<string>  $organizationUuids
     */
    public function __construct(
        public readonly array $organizationUuids = [],
        public readonly ?Instance $instance = null,
    ) {}

    public static function anonymous(): self
    {
        return new self;
    }

    public static function forInstance(Instance $instance): self
    {
        return new self([$instance->organization_uuid], $instance);
    }
}
