<?php

namespace App\Releases\Checks;

use App\Models\ReleaseCheck;
use App\Support\Compat;

/**
 * `compat.console` and `compat.api` must parse as npm/Composer ranges (composer/semver);
 * the schema only checks their characters. A missing range means "any version", which is
 * allowed with a warning. Each `compat.extensions` range (another extension the release needs)
 * must parse too.
 */
class CompatCheck implements Check
{
    public function name(): string
    {
        return ReleaseCheck::CHECK_COMPAT;
    }

    public function run(CheckContext $context): CheckResult
    {
        $compat = is_array($context->manifest['compat'] ?? null) ? $context->manifest['compat'] : [];
        $errors = [];
        $warnings = [];

        foreach (['console', 'api'] as $side) {
            $range = $compat[$side] ?? null;
            if ($range === null || $range === '') {
                $warnings[] = "No compat.{$side} range: the release is offered to every {$side} version.";
            } elseif (! is_string($range) || ! Compat::validRange($range)) {
                $errors[] = "compat.{$side} `".(is_string($range) ? $range : json_encode($range)).'` is not a valid version range.';
            }
        }

        $extensions = $compat['extensions'] ?? null;
        if ($extensions !== null && ! is_array($extensions)) {
            $errors[] = 'compat.extensions must map extension package names to version ranges.';
        }
        foreach (is_array($extensions) ? $extensions : [] as $package => $range) {
            if (! is_string($range) || $range === '' || ! Compat::validRange($range)) {
                $errors[] = "compat.extensions `{$package}` `".(is_string($range) ? $range : json_encode($range)).'` is not a valid version range.';
            }
        }

        if ($context->file('dist') !== null && empty($compat['console'])) {
            $warnings[] = 'A prebuilt dist is pinned to the ember-ui and ember-core versions it was built against; set compat.console to the console versions that ship them.';
        }

        return CheckResult::from($errors, 'Compatibility ranges parse.', 'Compatibility ranges are invalid.', $warnings, ['compat' => $compat]);
    }
}
