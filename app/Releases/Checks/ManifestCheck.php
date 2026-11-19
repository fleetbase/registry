<?php

namespace App\Releases\Checks;

use App\Models\ReleaseCheck;
use App\Releases\ExtensionManifest;

/**
 * `extension.json` against the JSON Schema, and consistent with what was uploaded: an
 * `engine` entry when an engine tarball was uploaded (and the reverse), the same `api`
 * pairing, the release's version and channel.
 */
class ManifestCheck implements Check
{
    public function name(): string
    {
        return ReleaseCheck::CHECK_MANIFEST;
    }

    public function run(CheckContext $context): CheckResult
    {
        $manifest = $context->manifest;
        $errors = ExtensionManifest::validate($manifest);

        foreach (['engine' => 'an engine tarball', 'api' => 'an api tarball'] as $key => $label) {
            $declared = array_key_exists($key, $manifest);
            $uploaded = $context->file($key) !== null;
            if ($declared && ! $uploaded) {
                $errors[] = "The manifest declares `{$key}` but no {$key} tarball was uploaded.";
            } elseif ($uploaded && ! $declared) {
                $errors[] = "{$label} was uploaded but the manifest has no `{$key}` entry.";
            }
        }

        if (($manifest['version'] ?? null) !== $context->release->version) {
            $errors[] = 'The manifest version does not match the release version '.$context->release->version.'.';
        }

        if (isset($manifest['channel']) && $manifest['channel'] !== $context->release->channel) {
            $errors[] = "The manifest channel `{$manifest['channel']}` does not match the release channel `{$context->release->channel}`.";
        }

        return CheckResult::from($errors, 'extension.json is valid.', 'extension.json is invalid.');
    }
}
