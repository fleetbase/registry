<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `{extension, organization}` from the website: the extension to buy and the buyer organization
 * (slug or public id; defaults to the caller's personal organization).
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
            'organization' => ['nullable', 'string', 'max:191'],
        ];
    }
}
