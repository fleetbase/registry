<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:191', 'unique:accounts,email'],
            'username' => ['nullable', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:accounts,username', 'unique:organizations,slug'],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers(), 'max:128'],
            'organization_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.regex' => 'The username may only contain lowercase letters, numbers and dashes.',
        ];
    }
}
