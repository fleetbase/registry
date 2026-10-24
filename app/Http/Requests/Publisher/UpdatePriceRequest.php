<?php

namespace App\Http\Requests\Publisher;

use App\Models\Price;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `{model: free|one_time, amount, currency}`. Amounts are minor units (cents).
 */
class UpdatePriceRequest extends FormRequest
{
    /** Largest one-time price, in minor units. */
    public const MAX_AMOUNT = 100_000_00;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim($this->input('currency')))]);
        }
    }

    public function rules(): array
    {
        return [
            'model' => ['required', 'string', Rule::in(Price::MODELS)],
            'amount' => ['nullable', 'required_if:model,'.Price::MODEL_ONE_TIME, 'integer', 'min:'.($this->input('model') === Price::MODEL_ONE_TIME ? 1 : 0), 'max:'.self::MAX_AMOUNT],
            'currency' => ['nullable', 'required_if:model,'.Price::MODEL_ONE_TIME, 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ];
    }
}
