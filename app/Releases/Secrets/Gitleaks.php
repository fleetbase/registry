<?php

namespace App\Releases\Secrets;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs the gitleaks binary, when installed, over a directory of extracted files
 * (`gitleaks detect --no-git`, JSON report, secrets redacted by gitleaks).
 */
class Gitleaks
{
    public function binary(): ?string
    {
        $configured = (string) config('registry.releases.gitleaks_binary', 'gitleaks');
        if ($configured === '') {
            return null;
        }

        if (str_contains($configured, '/')) {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }

        return (new ExecutableFinder)->find($configured);
    }

    public function available(): bool
    {
        return $this->binary() !== null;
    }

    /**
     * Findings with paths relative to `$directory`.
     *
     * @return list<array{rule: string, description: string, file: string, line: int, match: string}>
     *
     * @throws \RuntimeException when gitleaks fails to run
     */
    public function scan(string $directory): array
    {
        $binary = $this->binary() ?? throw new \RuntimeException('gitleaks is not installed.');
        $report = tempnam(sys_get_temp_dir(), 'gitleaks');

        try {
            $process = new Process([
                $binary, 'detect', '--no-git', '--no-banner', '--redact', '--exit-code', '0',
                '--source', $directory, '--report-format', 'json', '--report-path', $report,
            ]);
            $process->setTimeout((int) config('registry.releases.gitleaks_timeout', 120));
            $process->run();

            if (! $process->isSuccessful()) {
                throw new \RuntimeException('gitleaks failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
            }

            $results = json_decode((string) file_get_contents($report), true);
            if (! is_array($results)) {
                throw new \RuntimeException('gitleaks wrote an unreadable report.');
            }

            $root = rtrim(realpath($directory) ?: $directory, '/').'/';

            return array_values(array_map(fn (array $finding) => [
                'rule' => (string) ($finding['RuleID'] ?? 'gitleaks'),
                'description' => (string) ($finding['Description'] ?? 'gitleaks finding'),
                'file' => str_starts_with((string) ($finding['File'] ?? ''), $root)
                    ? substr($finding['File'], strlen($root))
                    : ltrim(str_replace(rtrim($directory, '/').'/', '', (string) ($finding['File'] ?? '')), '/'),
                'line' => (int) ($finding['StartLine'] ?? 0),
                'match' => SecretRules::redact((string) ($finding['Secret'] ?? $finding['Match'] ?? '')),
            ], array_filter($results, 'is_array')));
        } finally {
            @unlink($report);
        }
    }
}
