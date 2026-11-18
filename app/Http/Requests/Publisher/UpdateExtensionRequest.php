<?php

namespace App\Http\Requests\Publisher;

use App\Models\Extension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExtensionRequest extends FormRequest
{
    use ListingRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'visibility' => ['sometimes', Rule::in(Extension::VISIBILITIES)],
        ], $this->listingRules());
    }
}
