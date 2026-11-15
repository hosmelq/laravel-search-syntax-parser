<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Contracts;

use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use Illuminate\Database\Eloquent\Builder;

interface Resolver
{
    /**
     * Each leaf runs in its own group; its complement must include NULL rows when the field is nullable.
     *
     * @param Builder<*> $query
     */
    public function apply(Builder $query, Clause $clause, Context $context): void;
}
