---
title: "Custom Resolvers"
description: "Write the SQL for a field with a resolver class or closure that receives compiled clauses."
weight: 1
---

A custom resolver writes the SQL for a field when the built-in targets do not fit, such as core array fields or dates with a custom input format.

## Register a resolver

Pass a `Contracts\Resolver` or a closure to `using()`. Both receive `(Builder $query, Clause $clause, Context $context)`:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Laravel\Contracts\Resolver;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use Illuminate\Database\Eloquent\Builder;

final class QuantityResolver implements Resolver
{
    #[\Override]
    public function apply(Builder $query, Clause $clause, Context $context): void
    {
        $column = $query->qualifyColumn('quantity');

        $query->where($column, $clause->operator->value, $clause->value);

        if ($clause->negated && $context->field->isNullable()) {
            $query->orWhereNull($column);
        }
    }
}

$field = Field::integer('quantity')
    ->operators('=', '!=', '>', '>=', '<', '<=')
    ->withoutLists()
    ->withoutRanges()
    ->withoutExists()
    ->withoutWildcards()
    ->nullable()
    ->using(new QuantityResolver());
```

Filter resolvers receive a builder isolated in a leaf group. [Directive resolvers](../basic-usage/directives-and-sorting) receive the root builder. Always add a predicate, even when it matches no rows: an empty group contributes no SQL.

Restrict the field to shapes the resolver supports. The example above accepts only scalar comparison operators.

## Read a clause

Resolvers receive `HosmelQ\SearchSyntaxParser\Query\Clause` from the core parser. It exposes readonly properties:

| Property | Value |
| --- | --- |
| `field` | The public field name. |
| `operator` | A core `Query\Operator`, already complemented for NOT. |
| `value` | A storage scalar, list, core `Query\Bounds`, core `Query\TextPattern`, or `null` for existence. |
| `position` | Source offset, or `null` for defaults. Directives retain the written value offset. |
| `negated` | Whether the clause is a set complement. |
| `text` | Whether the built-in resolver would use case-insensitive text comparison. |
| `fullText` | A core `Support\FullText` object for word matching, otherwise `null`. It exposes `words`, `phrase`, and `prefix`. |

`Query\Operator` includes equality, ordering, list, range, existence, and LIKE variants. `complement()` returns the inverse. `Clause::positive()` recovers the positive clause while preserving its other properties. `Query\Bounds` has inclusive `from` and `to` endpoints; a `null` endpoint is open.

The core [clause compiler](https://hosmelq.com/docs/search-syntax-parser/v3/advanced-usage/compiling-clauses/) prepares values, operators, and text metadata, including positional array conversions. Two open range bounds become existence clauses; text without words becomes a constant group before a resolver is called. It also supplies their `ClauseGroup` tree through `SearchParser::compile()`; this package translates the tree into SQL.

`Field::comparisonType($value)`, `comparesNumbers()`, `getAllowedValues()`, `getValueType()`, `isDate()`, and `isCalendarDate()` expose the core comparison contract. Use `keyOf($clause->field)` to extract a dynamic key.

The context exposes the field definition, connection driver, resolved user `DateTimeZone`, and the invocation's shared `DateTimeImmutable` clock.

## Resolve date intervals

Handle date existence operators directly. For other format-less date clauses, use `DateBounds::forClause($clause, $context->timezone, $context->now)` from `HosmelQ\SearchSyntaxParser\Date`, then `toStorage($zone, $format)` on each interval. These [date helpers](https://hosmelq.com/docs/search-syntax-parser/v3/advanced-usage/compiling-clauses/#resolve-date-intervals) handle positive intervals, storage precision, and supported years; apply the clause's complement in SQL. Null storage bounds mean no matching stored value, while two open endpoints mean every present value. Custom input formats still need their own parsing.

## Compile values consistently

Resolvers own SQL type handling, text folding, NULL complements, and negative conditions across several targets. You can reuse `Resolvers\ColumnResolver` for standard column and JSON behavior.

LIKE values contain literal segments separated by wildcards. Bind `$clause->value->toLike()` to escape `%`, `_`, and `!` with `!`, and emit `LIKE ? ESCAPE '!'` or `NOT LIKE ? ESCAPE '!'`. Laravel's `whereLike()` does not add this required escape clause. Parenthesize trusted expression targets, including before NULL checks.

Built-in dates use interval comparisons; custom input formats require a custom resolver. Relation and aggregate targets use built-in resolvers and cannot be combined with `using()`.

## Strict complements

Use `Context::complement()` to exclude all rows matched by a callback, including rows where SQL NOT would return NULL:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use Illuminate\Database\Eloquent\Builder;

$field = Field::boolean('late')
    ->operators('=', '!=')
    ->withoutLists()->withoutRanges()->withoutWildcards()->withoutExists()
    ->using(function (Builder $query, Clause $clause, Context $context): void {
        $positive = function (Builder $matches): void {
            $matches->where('due_at', '<', now());
        };

        if ((bool) $clause->value !== $clause->negated) {
            $query->where($positive);
        } else {
            $context->complement($query, $positive);
        }
    });
```

The helper excludes primary keys selected by the positive predicate. Its subquery starts without global scopes and groups the callback's conditions. Root constraints and global scopes still apply. Here, NULL due dates match `late:false` and `late:!=true`.

The model must have one named primary key; absent or composite keys throw `LogicException`. The callback must constrain its builder without executing it. For standard value mappings and booleans, use [scope and predicate fields](../basic-usage/scopes-and-predicates).
