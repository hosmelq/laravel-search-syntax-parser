<?php

declare(strict_types=1);

namespace HosmelQ\SearchSyntaxParser\Laravel\Resolvers;

use Closure;
use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Laravel\Contracts\Resolver;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * @internal
 */
final class ScopeResolver implements Resolver
{
    #[Override]
    public function apply(Builder $query, Clause $clause, Context $context): void
    {
        /** @var list<bool|float|int|string> $values */
        $values = is_array($clause->value) ? $clause->value : [$clause->value];

        if ($clause->negated) {
            foreach ($values as $value) {
                Context::complement($query, $this->predicate($context, $value));
            }

            return;
        }

        $query->where(function (Builder $group) use ($values, $context): void {
            foreach ($values as $value) {
                Context::matching($group, $this->predicate($context, $value), 'or');
            }
        });
    }

    /**
     * @return Closure(Builder<*>): void
     */
    private function predicate(Context $context, bool|float|int|string $value): Closure
    {
        $predicate = $context->field->scopePredicate($value);

        return static function (Builder $keys) use ($predicate): void {
            if ($predicate instanceof Closure) {
                $predicate($keys);
            } else {
                $keys->scopes($predicate);
            }
        };
    }
}
