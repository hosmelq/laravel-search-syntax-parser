<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Resolvers;

use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Laravel\Contracts\Resolver;
use HosmelQ\SearchSyntaxParser\Laravel\Support\ColumnPredicate;
use HosmelQ\SearchSyntaxParser\Laravel\Support\ColumnTarget;
use HosmelQ\SearchSyntaxParser\Laravel\Support\FullTextPredicate;
use HosmelQ\SearchSyntaxParser\Laravel\Support\JsonPredicate;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use HosmelQ\SearchSyntaxParser\Query\Operator;
use HosmelQ\SearchSyntaxParser\Support\FullText;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Override;

final class ColumnResolver implements Resolver
{
    #[Override]
    public function apply(Builder $query, Clause $clause, Context $context): void
    {
        if (
            $context->field->isDate()
            && ! in_array($clause->operator, [Operator::Exists, Operator::NotExists], true)
        ) {
            new DateResolver()->apply($query, $clause, $context);

            return;
        }

        foreach ($context->field->targets($query) as $target) {
            $query->where(function (Builder $column) use ($target, $clause, $context): void {
                if (
                    is_string($target)
                    && ($context->field->isJsonArray() || str_contains($target, '->'))
                ) {
                    $predicate = new JsonPredicate($target, $clause, $context);

                    $column->whereRaw($predicate, $predicate->bindings());

                    return;
                }

                $this->applyColumn($column, $target, $clause, $context);
            }, boolean: $clause->negated ? 'and' : 'or');
        }
    }

    /**
     * @param Builder<*> $query
     */
    private function applyColumn(
        Builder $query,
        Expression|string $target,
        Clause $clause,
        Context $context,
    ): void {
        $target = new ColumnTarget($target);

        if (in_array($clause->operator, [Operator::Exists, Operator::NotExists], true)) {
            $query->addBinding($target->bindings());
            $query->whereNull($target, not: $clause->operator === Operator::Exists);

            return;
        }

        $predicate = $clause->fullText instanceof FullText
            ? new FullTextPredicate($target, $clause->fullText, $context, $clause->negated)
            : new ColumnPredicate($target, $clause, $context);

        $query->whereRaw($predicate, $predicate->bindings());

        if ($clause->negated && $context->field->isNullable()) {
            $query->addBinding($target->bindings());
            $query->orWhereNull($target);
        }
    }
}
