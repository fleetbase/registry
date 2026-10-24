<?php

namespace App\Http\Requests\Publisher;

use App\Models\ExtensionPackage;
use App\Releases\PackageNames;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class CreateExtensionRequest extends FormRequest
{
    use ListingRules;

    public const NPM_NAME = PackageNames::NPM;

    public const COMPOSER_NAME = PackageNames::COMPOSER;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $packages = $this->input('packages');
        if (is_array($packages)) {
            $this->merge(['packages' => array_map(PackageNames::normalize(...), $packages)]);
        }
    }

    public function rules(): array
    {
        return array_merge([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['nullable', 'string', 'min:2', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'organization' => ['nullable', 'string', 'max:191'],
            'packages' => ['required', 'array'],
            'packages.npm' => ['nullable', 'required_without:packages.composer', 'string', self::packageName(ExtensionPackage::KIND_NPM)],
            'packages.composer' => ['nullable', 'required_without:packages.npm', 'string', self::packageName(ExtensionPackage::KIND_COMPOSER)],
        ], $this->listingRules());
    }

    /** The format rule `PackageNames` applies, so availability checks and creation agree. */
    public static function packageName(string $kind): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($kind) {
            if (is_string($value) && ($error = PackageNames::formatError($kind, $value))) {
                $fail($error);
            }
        };
    }

    /**
     * @return array<string, string> kind => name
     */
    public function packages(): array
    {
        return array_filter([
            'npm' => $this->validated('packages.npm'),
            'composer' => $this->validated('packages.composer'),
        ]);
    }
}
