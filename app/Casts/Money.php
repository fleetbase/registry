<?php

namespace App\Casts;

use App\Support\Utils;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * Stores money as integer minor units, stripping symbols and separators on write.
 * Ported from `Fleetbase\Casts\Money`.
 */
class Money implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        return $value === null ? null : (int) $value;
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return static::apply($value);
    }

    public static function apply($value): int
    {
        if ($value === null) {
            return 0;
        }

        $value = static::removeCurrencySymbols((string) $value);
        $value = static::removeSpecialCharactersExceptDotAndComma($value);

        return Utils::numbersOnly($value);
    }

    public static function removeCurrencySymbols(string $string): string
    {
        return preg_replace('/[\$€£¥₹¢฿₽₪₩₮]/u', '', $string);
    }

    public static function removeSpecialCharactersExceptDotAndComma(string $string): string
    {
        return preg_replace('/[^\d.,-]/', '', $string);
    }
}
