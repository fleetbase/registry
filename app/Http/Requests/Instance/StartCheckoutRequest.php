<?php

namespace App\Http\Requests\Instance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `{extension, return_url, cancel_url?, email?}` from a console. `return_url` is where Stripe
 * sends the buyer after paying (the hand-off id is added as `handoff`); `cancel_url` defaults to it.
 * `email` prefills Checkout.
 */
class StartCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'extension' => ['required', 'string', 'max:191'],
            'return_url' => ['required', 'string', 'max:2000', 'url:http,https'],
            'cancel_url' => ['nullable', 'string', 'max:2000', 'url:http,https'],
            'email' => ['nullable', 'string', 'email', 'max:191'],
        ];
    }
}
