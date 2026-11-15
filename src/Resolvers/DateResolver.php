<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Resolvers;

use DateTimeZone;
use HosmelQ\SearchSyntaxParser\Date\DateBounds;
use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Laravel\Contracts\Resolver;
use HosmelQ\SearchSyntaxParser\Laravel\Support\ColumnTarget;
use HosmelQ\SearchSyntaxParser\Query\Bounds;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * @internal
 */
final class DateResolver implements Resolver
{
    #[Override]
    public function apply(Builder $query, Clause $clause, Context $context): void
    {
        $intervals = DateBounds::forClause($clause, $context->timezone, $context->now);
        $format = $context->field->isCalendarDate()
            ? 'Y-m-d'
            : $context->field->dateFormat($query);
        $zone = $context->field->isCalendarDate()
            ? $context->timezone
            : new DateTimeZone(config()->string('app.timezone'));
        $intervals = array_map(
            static fn (DateBounds $bounds): null|Bounds => $bounds->toStorage($zone, $format),
            $intervals,
        );

        foreach ($context->field->targets($query) as $target) {
            $target = new ColumnTarget($target);

            $query->where(
                function (Builder $column) use ($intervals, $target, $clause, $context): void {
                    foreach ($intervals as $bounds) {
                        $column->where(
                            function (Builder $interval) use (
                                $bounds,
                                $target,
                                $clause,
                                $context,
                            ): void {
                                $this->interval(
                                    $interval,
                                    $target,
                                    $bounds,
                                    $clause->negated,
                                    $context->field->isNullable(),
                                );
                            },
                            boolean: $clause->negated ? 'and' : 'or',
                        );
                    }
                },
                boolean: $clause->negated ? 'and' : 'or',
            );
        }
    }

    /**
     * @param Builder<*> $query
     */
    private function interval(
        Builder $query,
        ColumnTarget $target,
        null|Bounds $bounds,
        bool $negated,
        bool $nullable,
    ): void {
        if (! $bounds instanceof Bounds) {
            if ($negated && ! $nullable) {
                $query->addBinding($target->bindings());
                $query->whereNotNull($target);
            } else {
                $query->whereRaw($negated ? '1 = 1' : '1 = 0');
            }

            return;
        }

        if ($bounds->from === null && $bounds->to === null) {
            if ($negated && ! $nullable) {
                $query->whereRaw('1 = 0');
            } else {
                $query->addBinding($target->bindings());
                $query->whereNull($target, not: ! $negated);
            }

            return;
        }

        if ($bounds->from !== null) {
            $query->addBinding($target->bindings());
            $query->where($target, $negated ? '<' : '>=', $bounds->from);
        }

        if ($bounds->to !== null) {
            $query->addBinding($target->bindings());
            $query->where($target, $negated ? '>=' : '<', $bounds->to, $negated ? 'or' : 'and');
        }

        if ($negated && $nullable) {
            $query->addBinding($target->bindings());
            $query->orWhereNull($target);
        }
    }
}
