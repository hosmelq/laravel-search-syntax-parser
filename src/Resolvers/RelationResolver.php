<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Resolvers;

use Closure;
use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Laravel\Contracts\Resolver;
use HosmelQ\SearchSyntaxParser\Laravel\Support\RelationPath;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use HosmelQ\SearchSyntaxParser\Support\FullText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Override;

/**
 * @internal
 */
final class RelationResolver implements Resolver
{
    #[Override]
    public function apply(Builder $query, Clause $clause, Context $context): void
    {
        $this->applyMany($query, [$clause], $context);
    }

    /**
     * @param non-empty-list<Clause> $clauses
     * @param Builder<*> $query
     */
    public function applyMany(Builder $query, array $clauses, Context $context): void
    {
        $predicate = static function (Builder $related) use ($clauses, $context): void {
            $related->where(function (Builder $group) use ($clauses, $context): void {
                foreach ($clauses as $clause) {
                    $positive = $clause->positive();

                    $group->orWhere(function (Builder $member) use ($positive, $context): void {
                        new ColumnResolver()->apply($member, $positive, $context);
                    });
                }
            });
        };

        if (
            in_array($context->driver, ['mysql', 'mariadb'], true)
            && array_any(
                $clauses,
                static fn (Clause $clause): bool => $clause->fullText instanceof FullText,
            )
        ) {
            $match = $predicate;
            $predicate = static function (Builder $related) use ($match): void {
                // Evaluate MATCH on the indexed table before a self-relation write materializes it.
                Context::matching($related, $match);
            };
        }

        $path = new RelationPath($context->field);

        $this->constrain(
            $query,
            $path->segments(),
            $context,
            $predicate,
            $clauses[0]->negated,
        );
    }

    /**
     * @param non-empty-list<string> $segments
     * @param Builder<*> $query
     * @param Closure(Builder<*>): void $predicate
     */
    private function constrain(
        Builder $query,
        array $segments,
        Context $context,
        Closure $predicate,
        bool $negated = false,
    ): void {
        $name = array_shift($segments);
        $relation = RelationPath::relation($query->getModel(), $name);

        if ($context->field->includesTrashedRelated() && $relation instanceof HasOneOrManyThrough) {
            $relation->withTrashedParents();
        }

        $callback = function (Builder $related) use ($segments, $context, $predicate): void {
            if (
                $context->field->includesTrashedRelated()
                && $related->getModel()::isSoftDeletable()
            ) {
                $related->withoutGlobalScope(SoftDeletingScope::class);
            }

            if ($segments === []) {
                $predicate($related);
            } else {
                $this->constrain($related, $segments, $context, $predicate);
            }
        };

        if ($relation instanceof MorphTo) {
            $types = $context->field->getRelationTypes();

            if ($negated) {
                $query->where(function (Builder $group) use ($relation, $types, $callback): void {
                    // Laravel's explicit-type negative morph query excludes NULL and other types.
                    // They belong to the complement of the configured positive matching set.
                    $column = $group->qualifyColumn($relation->getMorphType());
                    $classes = array_map(
                        static fn (string $type): string => new $type()->getMorphClass(),
                        $types,
                    );

                    $group->whereDoesntHaveMorph($relation, $types, $callback)
                        ->orWhereNotIn($column, $classes)
                        ->orWhereNull($column);
                });
            } else {
                $query->whereHasMorph($relation, $types, $callback);
            }

            return;
        }

        if ($negated) {
            $query->whereDoesntHave($relation, $callback);
        } else {
            $query->whereHas($relation, $callback);
        }
    }
}
