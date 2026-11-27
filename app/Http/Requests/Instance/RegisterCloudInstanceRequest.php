<?php

namespace App\Http\Requests\Instance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of `POST /v1/instances/register/cloud`. The caller is authenticated by the platform
 * signature (`PlatformSignature`), checked in the controller before this body is trusted. The
 * host is required: it is how a deployment finds its instance again (D17).
 */
class RegisterCloudInstanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:191'],
            'host' => ['required', 'string', 'max:255'],
            'console_version' => ['nullable', 'string', 'max:64'],
            'api_version' => ['nullable', 'string', 'max:64'],
            'previous_token' => ['nullable', 'string', 'max:128'],
        ];
    }
}
