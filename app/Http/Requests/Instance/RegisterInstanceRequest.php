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
            // Cloud is the signed platform registration's (register/cloud) alone: a code makes a self-hosted instance.
            'kind' => ['required', 'string', Rule::in([Instance::KIND_SELF_HOSTED])],
            'console_version' => ['nullable', 'string', 'max:64'],
            'api_version' => ['nullable', 'string', 'max:64'],
            'previous_token' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function messages(): array
    {
        return [
            'kind.in' => 'A registration code registers a self_hosted instance. Fleetbase Cloud registers through its signed platform registration.',
        ];
    }
}
