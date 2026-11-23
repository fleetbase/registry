<?php

namespace App\Http\Requests\Publisher;

use App\Models\Extension;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The `listing` block of an extension's extension.json, as `flb publish` sends it: listing fields by
 * their API names (Extension::MANIFEST_LISTING_FIELDS). The keys present are the fields the manifest
 * manages from now on; an empty body hands every field back to Studio.
 */
class ApplyManifestListingRequest extends FormRequest
{
    use ListingRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->listingRules();
    }

    /**
     * The manifest-managed fields in this request, in Extension::MANIFEST_LISTING_FIELDS order.
     *
     * @return array<int, string>
     */
    public function declaredFields(): array
    {
        return array_values(array_filter(Extension::MANIFEST_LISTING_FIELDS, fn (string $field) => $this->has($field)));
    }
}
