<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Subset of `Fleetbase\Support\Utils` used by the ported core-api conventions.
 */
class Utils
{
    /**
     * Determine whether a value is set (optionally a key on an object or array).
     */
    public static function isset($var, ?string $key = null): bool
    {
        if ($key !== null) {
            return data_get($var, $key) !== null;
        }

        return isset($var);
    }

    public static function notEmpty($var): bool
    {
        return ! empty($var);
    }

    public static function isEmpty($var): bool
    {
        return empty($var);
    }

    public static function classBasename(object|string $class): string
    {
        return class_basename($class);
    }

    public static function createObject(array $attributes = []): \stdClass
    {
        return (object) $attributes;
    }

    /**
     * Extract the digits of a value as an integer, keeping a leading minus sign.
     */
    public static function numbersOnly($value): int
    {
        $string = strval($value);

        $isNegative = false;
        if (preg_match('/\d/', $string, $matches, PREG_OFFSET_CAPTURE)) {
            $firstDigitOffset = $matches[0][1];
            $minusOffset = strpos($string, '-');
            $isNegative = $minusOffset !== false && $minusOffset < $firstDigitOffset;
        }

        $digits = preg_replace('/[^0-9]/', '', $string);
        if ($digits === '') {
            return 0;
        }

        $number = intval($digits);

        return $isNegative ? -$number : $number;
    }

    /**
     * Build a URL-safe slug, falling back to a random one when the source is empty.
     */
    public static function slugify(?string $value, int $fallbackLength = 8): string
    {
        $slug = Str::slug((string) $value);

        return $slug !== '' ? $slug : Str::lower(Str::random($fallbackLength));
    }
}
