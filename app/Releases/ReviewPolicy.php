<?php

namespace App\Releases;

use App\Models\Extension;
use App\Models\Price;
use App\Models\Release;

/**
 * Decision D6: automated checks on every release; a human review on an extension's first
 * release and whenever package names, pricing or `permissions` changed since the last
 * release that went out. Everything else is approved and published as soon as it is
 * submitted with passing checks.
 *
 * Baselines come from the last released (published or yanked) release: its
 * `meta.package_names` (else the names in its stored tarballs), its `meta.price` snapshot
 * taken at publish, and its manifest's `permissions`. A release with no price snapshot
 * (imported, or published before snapshots) counts as free.
 */
class ReviewPolicy
{
    public const FIRST_RELEASE = 'first_release';

    public const PACKAGE_NAMES = 'package_names';

    public const PRICING = 'pricing';

    public const PERMISSIONS = 'permissions';

    public const LISTING_NOT_PUBLISHED = 'listing_not_published';

    /**
     * @return array{required: bool, reasons: array<string, string>}
     */
    public function evaluate(Release $release): array
    {
        $release->loadMissing('extension.price');
        $extension = $release->extension;
        $reasons = [];

        $previous = Release::query()
            ->where('extension_uuid', $release->extension_uuid)
            ->whereKeyNot($release->getKey())
            ->released()
            ->with(['engineTarball', 'apiTarball'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        if ($previous === null) {
            $reasons[self::FIRST_RELEASE] = 'This is the extension\'s first release.';
        } else {
            $before = static::packageNames($previous);
            $after = static::packageNames($release);
            if ($before !== $after) {
                $reasons[self::PACKAGE_NAMES] = 'Package names changed from '.static::describe($before).' to '.static::describe($after).'.';
            }

            $priceBefore = $previous->metaValue('price') ?? static::freePrice();
            $priceAfter = static::priceSnapshot($extension->price);
            if (static::comparablePrice($priceBefore) !== static::comparablePrice($priceAfter)) {
                $reasons[self::PRICING] = 'Pricing changed from '.static::describePrice($priceBefore).' to '.static::describePrice($priceAfter).'.';
            }

            $permissionsBefore = static::canonical($previous->manifest['permissions'] ?? null);
            $permissionsAfter = static::canonical($release->manifest['permissions'] ?? null);
            if ($permissionsBefore !== $permissionsAfter) {
                $reasons[self::PERMISSIONS] = 'The manifest\'s permissions changed.';
            }
        }

        if ($previous !== null && $extension->status !== Extension::STATUS_PUBLISHED) {
            $reasons[self::LISTING_NOT_PUBLISHED] = "The listing is {$extension->status}.";
        }

        return ['required' => $reasons !== [], 'reasons' => $reasons];
    }

    /**
     * @return array<string, string> kind => name, sorted
     */
    public static function packageNames(Release $release): array
    {
        $names = $release->metaValue('package_names');

        if (! is_array($names)) {
            $release->loadMissing(['engineTarball', 'apiTarball']);
            $names = array_filter([
                'npm' => $release->engineTarball?->manifest()['name'] ?? null,
                'composer' => $release->apiTarball?->manifest()['name'] ?? null,
            ]);
        }

        $names = array_map(fn ($name) => strtolower((string) $name), $names);
        ksort($names);

        return $names;
    }

    public static function priceSnapshot(?Price $price): array
    {
        if ($price === null || $price->isFree()) {
            return static::freePrice();
        }

        return [
            'model' => $price->model,
            'amount' => (int) $price->amount,
            'currency' => strtoupper((string) $price->currency),
            'sale_amount' => $price->sale_amount === null ? null : (int) $price->sale_amount,
            'on_sale' => (bool) $price->on_sale,
        ];
    }

    public static function freePrice(): array
    {
        return ['model' => Price::MODEL_FREE, 'amount' => 0, 'currency' => null, 'sale_amount' => null, 'on_sale' => false];
    }

    /**
     * Model, amount and currency. Turning a sale on or off is not a pricing change.
     */
    protected static function comparablePrice(array $price): string
    {
        return ($price['model'] ?? Price::MODEL_FREE) === Price::MODEL_FREE || (int) ($price['amount'] ?? 0) === 0
            ? 'free'
            : $price['model'].':'.(int) $price['amount'].':'.strtoupper((string) ($price['currency'] ?? ''));
    }

    protected static function describePrice(array $price): string
    {
        $key = static::comparablePrice($price);

        return $key === 'free' ? 'free' : number_format(((int) $price['amount']) / 100, 2).' '.strtoupper((string) $price['currency']).' ('.$price['model'].')';
    }

    protected static function describe(array $names): string
    {
        return $names === [] ? 'none' : implode(', ', $names);
    }

    protected static function canonical(mixed $value): string
    {
        $sort = function ($value) use (&$sort) {
            if (! is_array($value)) {
                return $value;
            }
            $value = array_map($sort, $value);
            if (array_is_list($value)) {
                usort($value, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
            } else {
                ksort($value);
            }

            return $value;
        };

        return (string) json_encode($sort($value));
    }
}
