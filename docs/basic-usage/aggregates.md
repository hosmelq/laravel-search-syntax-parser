---
title: "Aggregates"
description: "Filter records by counts, sums, averages, minima, and maxima computed over a relation."
weight: 10
---

Aggregate fields compare a value computed over one relation of the root model, such as the number of order lines or their total price.

## Define an aggregate field

Call an aggregate method with the relation and, except for counts, the column:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;
use Illuminate\Database\Eloquent\Builder;

$fields = [
    Field::integer('items')->countOf('lines'),
    Field::decimal('spent', 0, 2)->sumOf('lines', 'price_cents')->scale(100),
    Field::numeric('average')->avgOf('lines', 'quantity'),
    Field::numeric('smallest')->minOf('lines', 'quantity'),
    Field::numeric('largest')->maxOf('lines', 'quantity'),
    Field::date('first')->minOf('lines', 'created_at'),
    Field::date('last')->maxOf('lines', 'created_at'),
    Field::integer('paid')->countOf('lines', function (Builder $query): void {
        $query->where('status', 'paid');
    }),
];
```

| Method | Accepted field types |
| --- | --- |
| `countOf($relation, $constraint)` | Integer. |
| `sumOf($relation, $column, $constraint)` | Numeric or decimal. |
| `avgOf($relation, $column, $constraint)` | Numeric or decimal. |
| `minOf($relation, $column, $constraint)` | Numeric, decimal, or date. |
| `maxOf($relation, $column, $constraint)` | Numeric, decimal, or date. |

Constraints are optional. `min()` and `max()` still set input validation limits; they do not choose an aggregate.

The relation must be one root relation method. Dotted paths and `morphTo` are rejected. Define a through relation to aggregate across an intermediate model. Pivot and other polymorphic relations use Laravel's normal correlation queries.

## Compare aggregate values

Aggregates support their field's comparisons, lists, ranges, existence checks, and storage conversions:

```text
items:>5
items:0
items:1..3
spent:>=100
NOT spent:>=100
first:2025-01-15
```

With `scale(100)`, the sum comparison binds `10000`. Date minima and maxima use the usual [date intervals](dates-and-time-zones), including time zones, relative values, and enabled partial dates. The related model supplies timestamp precision. Add `withoutTime()` for DATE columns.

## Replace missing values

Counts are always present, including zero. Other aggregates return NULL when no rows match or all target values are NULL. Their complements include missing values without `nullable()`.

Use `whenEmpty()` to replace NULL with a storage value:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$fields = [
    Field::decimal('spent', 0, 2)
        ->sumOf('lines', 'price_cents')
        ->scale(100)
        ->whenEmpty(0),
    Field::date('first')
        ->minOf('lines', 'created_at')
        ->whenEmpty('2000-01-01 00:00:00'),
];
```

The replacement does not pass through storage conversion. For cents, `whenEmpty(500)` means five currency units. Date replacements use the related model's stored date format and storage zone, or `Y-m-d` with `withoutTime()`.

After replacement the value is present. `spent:0` includes an empty sum replaced with zero; `spent:!=0` excludes it. `whenEmpty()` is rejected on counts and fields without an aggregate. Invalid date replacements throw when the search runs.

## Constrain related rows

The optional closure receives the related builder, as in Laravel's `withCount()`. Joins remain inside the subquery and added conditions are grouped, including OR conditions. Related global scopes and soft deletes apply.

The aggregate replaces any select columns. `groupBy()`, `having()`, `limit()`, and `offset()` are rejected because the query must return one aggregate over all matching rows.

For through relations, Laravel applies the intermediate model's soft-delete scope but not its other global scopes. Put required intermediate constraints in the relation or aggregate closure.

Laravel embeds some subqueries, including query arguments to `whereIn()` and counted `has()`, as SQL text. The package cannot rewrite those reads of the root table for MySQL updates or deletes. Use `whereHas()` or `whereExists()` when a constraint reads the table being changed.

## Keep targets separate

Aggregate targets cannot be combined with column, expression, JSON, relation, scope, or custom resolver targets. Dynamic fields and arrays are also rejected. These are application configuration errors; invalid user values still produce validation errors.

Each aggregate predicate uses a correlated subquery. Date intervals and NULL-aware complements may repeat it. Index relation foreign keys and inspect plans with the constraints your application uses.
