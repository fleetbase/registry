<?php

namespace App\Http\Requests\Publisher;

use App\Models\Release;
use App\Releases\ExtensionManifest;
use App\Support\Semver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Multipart upload: `manifest` (extension.json), `engine` and/or `api` tarballs, optional
 * `dist` (prebuilt engine release), optional `channel`, `notes` and `submit`. Only what is
 * needed to create the release is validated here; everything else is a release check.
 */
class UploadReleaseRequest extends FormRequest
{
    /** Release notes, here and in `PATCH /v1/publisher/releases/{id}`. */
    public const NOTES = ['nullable', 'string', 'max:20000'];

    protected ?array $decodedManifest = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $bundleKb = (int) ceil(config('registry.max_bundle_bytes') / 1024);
        $distKb = (int) ceil(config('registry.releases.max_dist_bytes') / 1024);

        return [
            'manifest' => ['required', 'file', 'max:256'],
            'engine' => ['nullable', 'required_without:api', 'file', 'max:'.$bundleKb],
            'api' => ['nullable', 'required_without:engine', 'file', 'max:'.$bundleKb],
            'dist' => ['nullable', 'file', 'max:'.$distKb],
            'channel' => ['nullable', Rule::in(Release::CHANNELS)],
            'notes' => self::NOTES,
            'submit' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('manifest')) {
                return;
            }

            $manifest = ExtensionManifest::decode((string) file_get_contents($this->file('manifest')->getRealPath()));
            if ($manifest === null) {
                $validator->errors()->add('manifest', 'The manifest must be a JSON object (extension.json).');

                return;
            }

            $version = $manifest['version'] ?? null;
            if (! is_string($version) || ! Semver::valid($version) || str_starts_with($version, 'v')) {
                $validator->errors()->add('manifest', 'The manifest needs a SemVer `version` (for example 1.2.0 or 1.3.0-beta.1).');

                return;
            }

            $channel = $this->input('channel');
            $declared = $manifest['channel'] ?? null;
            if ($channel !== null && is_string($declared) && $declared !== $channel) {
                $validator->errors()->add('channel', "The channel `{$channel}` does not match the manifest's `{$declared}`.");
            }

            $this->decodedManifest = $manifest;
        }];
    }

    public function manifest(): array
    {
        return $this->decodedManifest ?? [];
    }

    public function channel(): string
    {
        $declared = $this->decodedManifest['channel'] ?? null;

        return $this->input('channel') ?? (in_array($declared, Release::CHANNELS, true) ? $declared : Release::CHANNEL_STABLE);
    }

    public function uploads(): array
    {
        return array_filter([
            'engine' => $this->file('engine'),
            'api' => $this->file('api'),
            'dist' => $this->file('dist'),
        ]);
    }
}
