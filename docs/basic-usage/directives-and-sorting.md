---
title: "Directives and Sorting"
description: "Apply query settings, soft-delete handling, and allowlisted sorts to the root Eloquent query."
weight: 13
---

Directives configure the root query outside its grouped WHERE filters. They apply even when no filter clauses remain.

## Include soft-deleted models

Add `Field::trashed()` to a model that uses Laravel's `SoftDeletes` trait:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$search->fields([
    Field::string('name'),
    Field::trashed(),
]);
```

| Query | Builder method |
| --- | --- |
| `trashed:with` | `withTrashed()` |
| `trashed:only` | `onlyTrashed()` |
| `trashed:without` | `withoutTrashed()` |

The default is `without`, including when the builder previously included deleted models. A model without `SoftDeletes` throws `LogicException` when its search runs, including for empty input.

This directive controls the root model. Use `withTrashedRelated()` for [relation fields](relations).

## Create a directive

Call `directive()` on an exact scalar field and supply a resolver:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Context;
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Query\Clause;
use Illuminate\Database\Eloquent\Builder;

$field = Field::integer('take')->min(1)->max(100)->directive()->default(25)
    ->using(function (Builder $query, Clause $clause, Context $context): void {
        $query->limit($clause->value);
    });
```

The resolver receives the root builder and a core equality clause with the storage value after `map()` or `scale()`. Its position is the written value offset, or null for a default. Explicit directives and defaults run in declaration order. An ignored explicit value suppresses its default.

Custom directives require `using()` and cannot be term targets. Their defaults must be scalar.

## Place directives in a query

Every ancestor of a directive must be AND. These queries are valid:

```text
(name:coffee AND trashed:with)
(name:coffee OR name:tea) trashed:with
```

OR or negation around the directive itself is rejected. Duplicates, lists, ranges, wildcards, existence checks, and comparisons other than equality also produce validation errors with source positions.

## Allow sorting

Configure public sort names and their columns:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use HosmelQ\SearchSyntaxParser\Laravel\Search;
use HosmelQ\SearchSyntaxParser\Sorting\AllowedSort;

$search = Search::make()
    ->fields([Field::string('name')])
    ->sorts([
        'name',
        AllowedSort::field('created', 'created_at')->defaultDirection('desc'),
        AllowedSort::field('city', 'meta->address->city'),
    ])
    ->defaultSort('created-desc');
```

A string uses the same public and column name. `AllowedSort` maps a public name to another column or JSON path. Sorts cannot target relations, expressions, or custom resolvers.

| Query | Order |
| --- | --- |
| `sort:created` | Uses the definition's default direction. |
| `sort:created-asc` | Ascending. |
| `sort:created-desc` | Descending. |

Names and directions ignore case. Names may contain hyphens; the final hyphen separates an explicit direction. Only the configured internal name reaches SQL, qualified against the query model.

One `sort:` clause is allowed, with the same placement and scalar-equality restrictions as directives. Configuring sorts reserves the field name `sort`. An unknown user sort is a validation error. An invalid default is an `InvalidArgumentException`.

See [field authorization](authorization#configure-sorts-for-the-current-user) to configure sort permissions for each user.

## Order missing values

NULL values sort last in both directions on every supported database. Missing JSON keys and JSON null count as NULL. Other JSON values sort by their JSON value, including numeric ordering for numbers. PostgreSQL compares JSON decimals exactly; SQLite, MySQL, and MariaDB compare them at double precision.

The package appends its NULL-order expression and column order after existing builder orders, so earlier orders take precedence. `defaultSort()` applies whenever input has no explicit sort, including empty input. For stable pagination, add a tie-breaker such as `orderBy('id')` after applying the search.
