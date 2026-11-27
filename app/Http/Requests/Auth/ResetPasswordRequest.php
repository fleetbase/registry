<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc'],
            'code' => ['required', 'string', 'digits:6'],
            // Same rule as registration.
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers(), 'max:128'],
        ];
    }
}
