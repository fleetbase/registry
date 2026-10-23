<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'username' => ['sometimes', 'required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('accounts', 'username')->ignore($this->user()->uuid, 'uuid')],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.regex' => 'The username may only contain lowercase letters, numbers and dashes.',
        ];
    }
}
