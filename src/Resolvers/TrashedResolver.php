<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Resolvers;

use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Laravel\Contracts\Resolver;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use Illuminate\Database\Eloquent\Builder;
use Override;

final class TrashedResolver implements Resolver
{
    #[Override]
    public function apply(Builder $query, Clause $clause, Context $context): void
    {
        $method = match ($clause->value) {
            'with' => 'withTrashed',
            'only' => 'onlyTrashed',
            default => 'withoutTrashed',
        };

        $query->__call($method, []);
    }
}
