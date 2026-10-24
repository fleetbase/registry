<?php

namespace App\Releases;

use Illuminate\Validation\ValidationException;

/**
 * Submission or publication refused because the release or its listing is not ready.
 * Renders as 422 with the reasons in `errors` and under `fields.release`.
 */
class ReleaseNotReady extends ValidationException
{
    /**
     * @param  list<string>  $reasons
     */
    public static function because(array $reasons): static
    {
        return static::withMessages(['release' => $reasons]);
    }
}
