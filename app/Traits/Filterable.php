<?php

namespace App\Traits;

use App\Http\Filter\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Lets a model apply its `$filter` class to a query: `Extension::filter($request)`.
 */
trait Filterable
{
    public function scopeFilter(Builder $query, ?Request $request = null, ?string $filterClass = null): Builder
    {
        $filterClass ??= $this->getFilter();
        if (! $filterClass || ! is_subclass_of($filterClass, Filter::class)) {
            return $query;
        }

        /** @var Filter $filter */
        $filter = new $filterClass($request ?? request());

        return $filter->apply($query);
    }
}
