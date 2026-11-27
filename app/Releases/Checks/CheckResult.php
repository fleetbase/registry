<?php

namespace App\Releases\Checks;

/**
 * Outcome of one release check, stored as `release_checks.passed` and `output`.
 */
final class CheckResult
{
    /**
     * @param  list<string>  $errors  why the check failed, shown to the developer and the reviewer
     * @param  list<string>  $warnings  shown, but do not fail the check
     */
    public function __construct(
        public readonly bool $passed,
        public readonly string $summary,
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly array $details = [],
        public readonly bool $skipped = false,
    ) {}

    public static function pass(string $summary, array $warnings = [], array $details = []): self
    {
        return new self(true, $summary, [], array_values($warnings), $details);
    }

    public static function fail(string $summary, array $errors, array $warnings = [], array $details = []): self
    {
        return new self(false, $summary, array_values($errors), array_values($warnings), $details);
    }

    /**
     * Passes from errors: fails when there are any.
     */
    public static function from(array $errors, string $passSummary, string $failSummary, array $warnings = [], array $details = []): self
    {
        return $errors === [] ? self::pass($passSummary, $warnings, $details) : self::fail($failSummary, $errors, $warnings, $details);
    }

    /**
     * Not applicable or turned off. Counts as passed; the reason is a warning.
     */
    public static function skip(string $summary, array $warnings = []): self
    {
        return new self(true, $summary, [], array_values($warnings), [], true);
    }

    public function toOutput(): array
    {
        return array_merge(
            ['summary' => $this->summary, 'errors' => $this->errors, 'warnings' => $this->warnings],
            $this->skipped ? ['skipped' => true] : [],
            $this->details,
        );
    }
}
