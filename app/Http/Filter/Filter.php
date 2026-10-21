<?php

namespace App\Http\Filter;

use App\Support\Http;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Request-driven query filter: every query parameter maps to a method of the same
 * name (snake or camel case); `*_after/_before`, `*_from/_to`, `*_min/_max` pairs map
 * to `<column>Between()`. Ported from `Fleetbase\Http\Filter\Filter`.
 */
abstract class Filter
{
    protected Request $request;

    protected Builder $builder;

    protected array $methodCache = [];

    protected static array $skipParams = [
        'limit', 'offset', 'page', 'per_page', 'sort', 'order', 'with', 'expand', 'without', 'with_count', 'without_relations',
    ];

    protected static array $rangePatterns = ['after:before', 'from:to', 'min:max', 'start:end', 'gte:lte', 'greater:less'];

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function apply(Builder $builder): Builder
    {
        $this->builder = $builder;

        $filterParams = array_diff_key($this->request->all(), array_flip(static::$skipParams));

        foreach ($filterParams as $name => $value) {
            $this->applyFilter((string) $name, $value);
        }

        $this->applyRangeFilters();

        if (Http::isInternalRequest($this->request) && method_exists($this, 'queryForInternal')) {
            $this->queryForInternal();
        }

        if (Http::isPublicRequest($this->request) && method_exists($this, 'queryForPublic')) {
            $this->queryForPublic();
        }

        return $this->builder;
    }

    private function applyFilter(string $name, $value): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        if (! array_key_exists($name, $this->methodCache)) {
            $this->methodCache[$name] = null;
            foreach ([$name, Str::camel($name)] as $methodName) {
                if (method_exists($this, $methodName) && ! in_array($methodName, ['apply', 'applyFilter', 'applyRangeFilters'], true)) {
                    $this->methodCache[$name] = $methodName;
                    break;
                }
            }
        }

        if ($this->methodCache[$name]) {
            $this->{$this->methodCache[$name]}($value);
        }
    }

    private function applyRangeFilters(): void
    {
        $prepositions = Arr::flatten(array_map(fn ($range) => explode(':', $range), static::$rangePatterns));

        $callbacks = collect($this->request->all())
            ->keys()
            ->filter(fn ($param) => Str::endsWith($param, array_map(fn ($p) => '_'.$p, $prepositions)))
            ->mapWithKeys(function ($param) {
                $preposition = Arr::last(explode('_', $param));
                $column = Str::beforeLast($param, '_'.$preposition);

                if ($column === '') {
                    return [];
                }

                $range = Arr::first(static::$rangePatterns, fn ($range) => in_array($preposition, explode(':', $range), true));
                $values = $this->request->all(array_map(fn ($p) => $column.'_'.$p, explode(':', $range)));

                return [Str::camel($column).'Between' => array_values($values)];
            })
            ->toArray();

        foreach ($callbacks as $method => $values) {
            if (method_exists($this, $method)) {
                $this->{$method}(...$values);
            }
        }
    }
}
