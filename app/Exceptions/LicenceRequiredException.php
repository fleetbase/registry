<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * No usable licence (none held, revoked, expired, or every seat in use).
 * Rendered as a 403 error envelope by the API exception handler.
 */
class LicenceRequiredException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(403, $message);
    }
}
