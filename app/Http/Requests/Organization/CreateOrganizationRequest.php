<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            // The slug is also the organization's npm scope and Composer vendor, so it never changes.
            'slug' => ['nullable', 'string', 'min:2', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('organizations', 'slug'), Rule::notIn(config('registry.reserved_scopes', []))],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'bio' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers and single dashes.',
            'slug.not_in' => 'That slug is reserved.',
        ];
    }
}
