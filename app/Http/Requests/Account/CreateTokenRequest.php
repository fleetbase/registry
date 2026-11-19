<?php

namespace App\Http\Requests\Account;

use App\Models\Token;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(Token::SCOPES)],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:'.Token::MAX_LIFETIME_DAYS],
            'organization' => ['nullable', 'string'],
        ];
    }
}
