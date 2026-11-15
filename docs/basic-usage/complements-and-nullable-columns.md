---
title: "Complements and Nullable Columns"
description: "Include NULL values in negated clauses, and complement fields that search several columns."
weight: 6
---

A negated clause matches every row the positive clause does not match. SQL leaves NULL out of both, so the package needs to know which columns can hold NULL.

## Include missing values

NOT, a leading minus, and inequality select the complement of a matching set. Add `nullable()` when a stored column or expression may return NULL:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$field = Field::integer('quantity')->nullable();
```

| Query | Matches |
| --- | --- |
| `quantity:!=10` | Every quantity except 10, including NULL. |
| `NOT quantity:>10` | Quantities at most 10, including NULL. |
| `quantity:<=10` | Quantities at most 10, excluding NULL. |
| `-quantity:*` | NULL quantities. |

Without `nullable()`, ordinary SQL NULL behavior applies to comparisons. Explicit existence and absence checks always use IS NOT NULL and IS NULL. The package does not inspect your schema.

## Search several nullable columns

A field with several columns matches when any target matches. Its complement requires every target to miss:

```php
use HosmelQ\SearchSyntaxParser\Laravel\Field;

$field = Field::string('person')
    ->columns(['first_name', 'last_name'])
    ->nullable();
```

`person:Ana` matches either column. `person:!=Ana` excludes a row when either column matches, even when the other is NULL. `-person:*` requires both columns to be NULL.

The inequality uses a NULL-aware condition for each target:

```sql
(first_name <> ? OR first_name IS NULL)
AND (last_name <> ? OR last_name IS NULL)
```

Each leaf is grouped. The core pushes NOT through boolean groups and removes double negation before compilation.

## Other target types

[JSON targets](json-columns) always include missing values in complements. Scalar complements also include JSON null and values of another type. Array complements mean no element matches, including empty arrays and non-array values.

[Relation fields](relations) exclude matching related rows with `whereDoesntHave()`. Records without related rows, or with NULL targets, are included regardless of `nullable()`.

[Aggregate fields](aggregates) handle missing aggregate values automatically. [Scope fields](scopes-and-predicates) complement the primary keys selected by their predicates.

NULL-aware and negative conditions can match many rows and change index selection. Check query plans for the negative filters your application exposes.
