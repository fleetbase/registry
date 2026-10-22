<?php

namespace App\Http\Requests\Instance;

use App\Models\Instance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterInstanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:191'],
            'host' => ['nullable', 'string', 'max:255'],
            'kind' => ['required', 'string', Rule::in(Instance::KINDS)],
            'console_version' => ['nullable', 'string', 'max:64'],
            'api_version' => ['nullable', 'string', 'max:64'],
        ];
    }
}
