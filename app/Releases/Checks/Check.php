<?php

namespace App\Releases\Checks;

interface Check
{
    /** The `release_checks.check` value. */
    public function name(): string;

    public function run(CheckContext $context): CheckResult;
}
