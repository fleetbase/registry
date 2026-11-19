<?php

namespace App\Http\Requests\Instance;

use App\Instances\CompatibilityReport;
use App\Models\Release;
use App\Support\Compat;
use App\Support\Semver;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `?console=0.8.1&api=2.0.2[&channel=beta][&host[@fleetbase/ember-core]=0.3.27&policy=minor]`:
 * the core versions an upgrade moves to. At least one of `console` and `api`; both must be
 * versions (a leading `v` is fine), since an unparseable target would match every range.
 */
class CompatibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $version = function (string $attribute, mixed $value, Closure $fail) {
            if (! is_string($value) || Semver::normalize($value) === null) {
                $fail("The {$attribute} must be a version such as 0.8.1.");
            }
        };

        return [
            'console' => ['required_without:api', 'nullable', 'string', 'max:64', $version],
            'api' => ['required_without:console', 'nullable', 'string', 'max:64', $version],
            'channel' => ['nullable', 'string', Rule::in(Release::CHANNELS)],
            'host' => ['nullable', 'array', 'max:20'],
            'host.*' => ['string', 'max:64', $version],
            'policy' => ['nullable', 'string', Rule::in(Compat::HOST_POLICIES)],
        ];
    }

    public function report(): CompatibilityReport
    {
        return new CompatibilityReport(
            Semver::normalize($this->query('console')),
            Semver::normalize($this->query('api')),
            collect((array) $this->query('host', []))->map(fn ($version) => (string) Semver::normalize((string) $version))->all(),
            (string) ($this->query('policy') ?: 'minor'),
            $this->query('channel') === Release::CHANNEL_BETA,
        );
    }
}
