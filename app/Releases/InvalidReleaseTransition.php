<?php

namespace App\Releases;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A release action that its current status does not allow. Renders as 409.
 */
class InvalidReleaseTransition extends ConflictHttpException
{
    public static function make(string $action, string $from): static
    {
        return new static("A release that is {$from} cannot be {$action}.");
    }
}
