<?php

namespace App\Http\Requests\Instance;

use App\Models\Install;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportInstallRequest extends FormRequest
{
    public const STATUSES = [Install::STATUS_INSTALLING, Install::STATUS_INSTALLED, Install::STATUS_FAILED];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'extension' => ['required', 'string', 'max:191'],
            'version' => ['required_without:release', 'nullable', 'string', 'max:64'],
            'release' => ['required_without:version', 'nullable', 'string', 'max:191'],
            'status' => ['required', 'string', Rule::in(self::STATUSES)],
            'error' => ['nullable', 'string', 'max:10000'],
            'installed_by' => ['nullable', 'string', 'max:191'],
        ];
    }
}
