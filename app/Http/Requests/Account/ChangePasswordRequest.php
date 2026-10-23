<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            // Same rule as registration.
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers(), 'max:128'],
        ];
    }
}
