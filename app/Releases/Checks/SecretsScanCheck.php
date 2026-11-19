<?php

namespace App\Releases\Checks;

use App\Models\ReleaseCheck;
use App\Releases\Secrets\Gitleaks;

/**
 * Secrets in any archive: the built-in gitleaks-style ruleset (`SecretRules`, run while the
 * archives were inspected) and, when the gitleaks binary is installed and the scanner is
 * `auto`, gitleaks over the extracted files. `REGISTRY_SECRETS_SCANNER=off` skips the check
 * with a warning.
 */
class SecretsScanCheck implements Check
{
    public const LISTED = 25;

    public function __construct(protected Gitleaks $gitleaks) {}

    public function name(): string
    {
        return ReleaseCheck::CHECK_SECRETS_SCAN;
    }

    public static function mode(): string
    {
        return strtolower((string) config('registry.releases.secrets_scanner', 'auto'));
    }

    /**
     * Whether the archives should be mirrored to disk for gitleaks.
     */
    public function wantsGitleaks(): bool
    {
        return self::mode() === 'auto' && $this->gitleaks->available();
    }

    public function run(CheckContext $context): CheckResult
    {
        if (self::mode() === 'off') {
            return CheckResult::skip('Secrets scan skipped.', ['The secrets scanner is turned off (REGISTRY_SECRETS_SCANNER=off); nothing was scanned.']);
        }

        $warnings = [];
        $scanners = ['builtin'];
        $findings = [];

        foreach ($context->archives->all() as $label => $inventory) {
            foreach ($inventory->secrets as $finding) {
                $findings[] = ['file' => $label.':'.$finding['file']] + $finding;
            }
        }

        if ($this->wantsGitleaks() && $context->archives->mirrorDir !== null) {
            try {
                foreach ($this->gitleaks->scan($context->archives->mirrorDir) as $finding) {
                    // Mirrored paths are `<label>/<path>`.
                    $finding['file'] = preg_replace('#^([^/]+)/#', '$1:', $finding['file'], 1);
                    $findings[] = $finding;
                }
                $scanners[] = 'gitleaks';
            } catch (\Throwable $e) {
                $warnings[] = 'gitleaks did not run ('.$e->getMessage().'); only the built-in ruleset was applied.';
            }
        } elseif (self::mode() === 'auto') {
            $warnings[] = 'gitleaks is not installed; only the built-in ruleset was applied.';
        }

        $findings = collect($findings)->unique(fn (array $f) => $f['file'].'|'.$f['line'].'|'.$f['match'])->values()->all();

        $errors = array_map(
            fn (array $f) => $f['file'].($f['line'] > 0 ? ':'.$f['line'] : '').' — '.$f['description'].($f['match'] !== '' ? ' ('.$f['match'].')' : ''),
            array_slice($findings, 0, self::LISTED),
        );
        if (count($findings) > self::LISTED) {
            $errors[] = 'and '.(count($findings) - self::LISTED).' more findings.';
        }
        if ($errors !== []) {
            $errors[] = 'Remove the secrets, rotate them (they are compromised once uploaded), and upload again. Mark a false positive with a `gitleaks:allow` comment on its line.';
        }

        return CheckResult::from(
            $errors,
            'No secrets found.',
            count($findings).' possible '.(count($findings) === 1 ? 'secret' : 'secrets').' found.',
            $warnings,
            ['scanners' => $scanners, 'findings' => array_slice($findings, 0, 100)],
        );
    }
}
