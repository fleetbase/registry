<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'slug' => ['prohibited'],
            'website' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'avatar_url' => ['sometimes', 'nullable', 'url:https', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.prohibited' => 'An organization\'s slug cannot be changed.',
        ];
    }
}
