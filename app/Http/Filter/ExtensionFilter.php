<?php

namespace App\Http\Filter;

use App\Models\Category;
use App\Models\Extension;
use App\Models\Install;
use App\Models\Price;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Catalog query parameters: q, category, pricing, hosting, publisher, sort.
 */
class ExtensionFilter extends Filter
{
    public const SORTS = ['newest', 'updated', 'popular', 'name', 'price'];

    /**
     * Free-text search. MySQL uses the fulltext index; other drivers fall back to LIKE.
     */
    public function q(string $query): void
    {
        $query = trim($query);
        if ($query === '') {
            return;
        }

        if ($this->builder->getConnection()->getDriverName() === 'mysql') {
            $this->builder->where(function (Builder $builder) use ($query) {
                $builder->whereFullText(['name', 'subtitle', 'description'], $query)
                    ->orWhere('name', 'like', $query.'%')
                    ->orWhere('slug', 'like', Str::slug($query).'%');
            });

            return;
        }

        $like = '%'.$query.'%';
        $this->builder->where(function (Builder $builder) use ($like) {
            $builder->where('name', 'like', $like)
                ->orWhere('subtitle', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('slug', 'like', $like);
        });
    }

    /**
     * Category by slug or public id; a comma-separated list matches any.
     */
    public function category(string $category): void
    {
        $values = array_filter(array_map('trim', explode(',', $category)));

        $this->builder->whereIn('category_uuid', Category::query()
            ->where(fn (Builder $q) => $q->whereIn('slug', $values)->orWhereIn('public_id', $values))
            ->select('uuid'));
    }

    /**
     * `free` or `paid`.
     */
    public function pricing(string $pricing): void
    {
        $pricing = Str::lower($pricing);

        if ($pricing === 'free') {
            $this->builder->where(function (Builder $builder) {
                $builder->whereDoesntHave('price')
                    ->orWhereHas('price', fn (Builder $q) => $q->where('model', Price::MODEL_FREE)->orWhere('amount', '<=', 0));
            });
        } elseif ($pricing === 'paid') {
            $this->builder->whereHas('price', fn (Builder $q) => $q->where('model', '!=', Price::MODEL_FREE)->where('amount', '>', 0));
        }
    }

    /**
     * `cloud`, `self_hosted` or `both`. Extensions marked `both` match either side.
     */
    public function hosting(string $hosting): void
    {
        $hosting = Str::lower($hosting);

        if ($hosting === Extension::HOSTING_BOTH) {
            $this->builder->where('hosting', Extension::HOSTING_BOTH);
        } elseif (in_array($hosting, Extension::HOSTINGS, true)) {
            $this->builder->whereIn('hosting', [$hosting, Extension::HOSTING_BOTH]);
        }
    }

    /**
     * Publisher organization by slug or public id.
     */
    public function publisher(string $publisher): void
    {
        $this->builder->whereHas('organization', fn (Builder $q) => $q->where('slug', $publisher)->orWhere('public_id', $publisher));
    }

    /**
     * Any of the listing's tags.
     */
    public function tag(string $tag): void
    {
        $this->builder->whereJsonContains('tags', $tag);
    }

    /**
     * Applied by the catalog controller because `sort` is skipped by the generic loop.
     */
    public function applySort(?string $sort): void
    {
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'popular';

        match ($sort) {
            'newest' => $this->builder->orderByDesc('published_at')->orderByDesc('created_at'),
            'updated' => $this->builder->orderByDesc('updated_at'),
            'name' => $this->builder->orderBy('name'),
            'price' => $this->builder->orderBy(
                Price::query()->select('amount')->whereColumn('prices.extension_uuid', 'extensions.uuid')->limit(1)
            )->orderBy('name'),
            default => $this->builder
                ->orderByDesc(
                    Install::query()->selectRaw('count(*)')
                        ->whereColumn('installs.extension_uuid', 'extensions.uuid')
                        ->where('installs.status', Install::STATUS_INSTALLED)
                )
                ->orderByDesc('published_at'),
        };
    }

    /**
     * Only published listings on the public API.
     */
    public function queryForPublic(): void
    {
        $this->builder->where('extensions.status', Extension::STATUS_PUBLISHED);
    }
}
