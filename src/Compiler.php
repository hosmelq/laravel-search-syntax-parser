<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use HosmelQ\SearchSyntaxParser\Laravel\Resolvers\RelationResolver;
use HosmelQ\SearchSyntaxParser\Laravel\Support\SelfRelation;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use HosmelQ\SearchSyntaxParser\Query\ClauseGroup;
use HosmelQ\SearchSyntaxParser\SearchParser;
use Illuminate\Database\Eloquent\Builder;

/**
 * @internal
 */
final readonly class Compiler
{
    public function __construct(
        private SearchParser $parser,
        private DateTimeZone $timezone,
        private DateTimeImmutable $now,
    ) {}

    /**
     * @param Builder<*> $query
     */
    public function apply(Builder $query, ClauseGroup $clauses): void
    {
        $query->where(function (Builder $group) use ($clauses): void {
            $this->contents($group, $clauses);

            SelfRelation::materialize($group->getQuery(), $group->getModel()->getTable(), false);
        });
    }

    /**
     * @param Builder<*> $query
     */
    public function applyDirectives(Builder $query): void
    {
        foreach ($this->parser->getDirectiveClauses() as $clause) {
            /** @var Field $field */
            $field = $this->parser->getAllowedField($clause->field);

            $this->resolve(
                $query,
                $field,
                $clause,
            );
        }
    }

    /**
     * @param Builder<*> $query
     */
    private function contents(Builder $query, Clause|ClauseGroup $clause): void
    {
        if ($clause instanceof Clause) {
            /** @var Field $field */
            $field = $this->parser->getAllowedField($clause->field);

            $this->resolve($query, $field, $clause);

            return;
        }

        if ($clause->children === []) {
            $query->whereRaw($clause->and ? '1 = 1' : '1 = 0');

            return;
        }

        if ($clause->field !== null) {
            /** @var Field $field */
            $field = $this->parser->getAllowedField($clause->field);
            $resolver = $field->getResolver();

            if (
                $resolver instanceof RelationResolver
                && array_all(
                    $clause->children,
                    static fn (Clause|ClauseGroup $child): bool => $child instanceof Clause,
                )
            ) {
                /** @var non-empty-list<Clause> $children */
                $children = $clause->children;

                $resolver->applyMany($query, $children, $this->context($query, $field));

                return;
            }
        }

        foreach ($clause->children as $child) {
            $query->where(function (Builder $group) use ($child): void {
                $this->contents($group, $child);
            }, boolean: $clause->and ? 'and' : 'or');
        }
    }

    /**
     * @param Builder<*> $query
     */
    private function context(Builder $query, Field $field): Context
    {
        return new Context(
            $field,
            $query->getModel()->getConnection()->getDriverName(),
            $this->timezone,
            $this->now,
        );
    }

    /**
     * @param Builder<*> $query
     */
    private function resolve(Builder $query, Field $field, Clause $clause): void
    {
        $resolver = $field->getResolver();
        $context = $this->context($query, $field);

        if ($resolver instanceof Closure) {
            $resolver($query, $clause, $context);
        } else {
            $resolver->apply($query, $clause, $context);
        }
    }
}
