<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Concerns;

use Closure;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

trait HasSearchSyntax
{
    abstract protected function searchSyntax(Search $search): Search;

    /**
     * @param Builder<static> $query
     * @param null|Closure(Search): (Search|void) $configure
     */
    #[Scope]
    protected function whereSearch(
        Builder $query,
        mixed $q,
        null|Closure $configure = null,
    ): void {
        $search = $this->searchSyntax(Search::make());

        if ($configure instanceof Closure) {
            $configured = $configure($search);

            if ($configured instanceof Search) {
                $search = $configured;
            }
        }

        $search->query($q)($query);
    }
}
