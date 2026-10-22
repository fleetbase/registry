<?php

namespace App\Http\Requests\Publisher;

use Illuminate\Foundation\Http\FormRequest;

class CreateExtensionRequest extends FormRequest
{
    use ListingRules;

    public const NPM_NAME = '/^(@[a-z0-9-~][a-z0-9-._~]*\/)?[a-z0-9-~][a-z0-9-._~]*$/';

    public const COMPOSER_NAME = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $packages = $this->input('packages');
        if (is_array($packages)) {
            $this->merge(['packages' => array_map(fn ($name) => is_string($name) ? strtolower(trim($name)) : $name, $packages)]);
        }
    }

    public function rules(): array
    {
        return array_merge([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['nullable', 'string', 'min:2', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'organization' => ['nullable', 'string', 'max:191'],
            'packages' => ['required', 'array'],
            'packages.npm' => ['nullable', 'required_without:packages.composer', 'string', 'max:214', 'regex:'.self::NPM_NAME],
            'packages.composer' => ['nullable', 'required_without:packages.npm', 'string', 'max:214', 'regex:'.self::COMPOSER_NAME],
        ], $this->listingRules());
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
