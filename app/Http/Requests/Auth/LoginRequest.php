<?php

namespace App\Http\Requests\Auth;

use App\Models\Token;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc'],
            'password' => ['required', 'string'],
            'token_name' => ['nullable', 'string', 'max:80'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string', Rule::in(Token::SCOPES)],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:'.Token::MAX_LIFETIME_DAYS],
        ];
    }
}
